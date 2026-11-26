<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HandoverTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::getDatabaseName(), 'giliran_test')) {
            throw new \RuntimeException('Gunakan basis data PostgreSQL khusus uji dengan awalan giliran_test.');
        }
    }

    public function test_handover_survives_owner_changes_and_audit_keeps_attribution(): void
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-01T00:00:00Z'));
        $department = DB::table('departments')->insertGetId(['name' => 'Operasional']);
        $outside = DB::table('departments')->insertGetId(['name' => 'Dukungan']);
        $type = $this->shiftType($department);
        $author = $this->user($department);
        $replacement = $this->user($department);
        $peer = $this->user($department);
        $newPeer = $this->user($department);
        $supervisor = $this->user($department, 'SUPERVISOR');
        $outsideSupervisor = $this->user($outside, 'SUPERVISOR');
        $source = $this->assignment($author, $type, '2030-01-02 08:00Z', '2030-01-02 12:00Z');
        $recipient = $this->assignment($peer, $type, '2030-01-02 13:00Z', '2030-01-02 17:00Z');
        $swapTarget = $this->assignment($newPeer, $type, '2030-01-02 18:00Z', '2030-01-02 22:00Z');

        $this->as($author);
        $id = $this->postJson('/api/v1/handovers', ['source_assignment_id' => $source, 'summary' => 'Ringkasan privat'])
            ->assertCreated()->assertJsonPath('data.recipient_assignment_id', (string) $recipient)->json('data.id');
        $report = "/api/v1/handovers/{$id}";
        $first = $this->postJson("{$report}/tasks", ['title' => 'Tugas privat'])->assertCreated()->json('data.id');
        $second = $this->postJson("{$report}/tasks", ['title' => 'Tugas kedua'])->assertCreated()->json('data.id');
        $removed = $this->postJson("{$report}/tasks", ['title' => 'Tugas sementara'])->assertCreated()->json('data.id');
        $this->deleteJson("{$report}/tasks/{$removed}", ['version' => 1])->assertNoContent();

        $this->as($supervisor);
        $this->patchJson("/api/v1/assignments/{$source}", ['version' => 1, 'employee_id' => $replacement->id])->assertOk();
        $this->as($author);
        $this->getJson($report)->assertNotFound();
        $this->postJson("{$report}/submit", ['version' => 1])->assertForbidden();

        $this->as($replacement);
        $this->postJson("{$report}/submit", ['version' => 1])->assertConflict()
            ->assertJsonPath('error.code', 'DRAFT_ADOPTION_REQUIRED');
        $this->postJson("{$report}/adopt", ['version' => 2])->assertConflict();
        $this->postJson("{$report}/adopt", ['version' => 1])->assertOk()
            ->assertJsonPath('data.summary', 'Ringkasan privat')->assertJsonPath('data.tasks.0.created_by', (string) $author->id);
        $this->postJson("{$report}/submit", ['version' => 2])->assertOk()
            ->assertJsonPath('data.submitted_by', (string) $replacement->id);
        $this->patchJson($report, ['version' => 3, 'summary' => 'Pengganti'])->assertConflict();

        $this->as($peer);
        $inbox = $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.event', 'HANDOVER_SUBMITTED')->assertJsonPath('data.0.subject_id', (string) $id);
        $this->assertStringNotContainsString('privat', $inbox->getContent());

        $this->as($supervisor);
        $this->postJson("/api/v1/assignments/{$source}/cancel", ['version' => 2])->assertConflict()
            ->assertJsonPath('error.code', 'ASSIGNMENT_HAS_HANDOVER');
        $this->patchJson("/api/v1/assignments/{$recipient}", ['version' => 1, 'starts_at' => '2030-01-02T14:00:00Z'])
            ->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_HAS_HANDOVER');
        $this->as($peer);
        $swap = $this->postJson('/api/v1/swaps', [
            'source_assignment_id' => $recipient, 'target_assignment_id' => $swapTarget, 'reason' => 'Alasan privat',
        ])->assertCreated()->json('data.id');
        $this->as($newPeer);
        $this->post("/api/v1/swaps/{$swap}/accept")->assertOk();
        $this->as($supervisor);
        $this->post("/api/v1/swaps/{$swap}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->patchJson("{$report}/tasks/{$second}", ['version' => 1, 'status' => 'IN_PROGRESS'])->assertOk();

        $this->as($peer);
        $this->getJson($report)->assertNotFound();
        $this->postJson("{$report}/acknowledge", ['version' => 3])->assertForbidden();
        $this->patchJson("{$report}/tasks/{$first}", ['version' => 1, 'status' => 'DONE'])->assertForbidden();
        $this->as($newPeer);
        $this->postJson("{$report}/acknowledge", ['version' => 3])->assertConflict()
            ->assertJsonPath('error.code', 'RECIPIENT_SHIFT_NOT_STARTED');
        DB::table('shift_assignments')->where('id', $recipient)->update(['status' => 'COMPLETED']);
        $this->travelTo(CarbonImmutable::parse('2030-01-03T00:00:00Z'));
        $this->postJson("{$report}/acknowledge", ['version' => 3])->assertOk()
            ->assertJsonPath('data.acknowledged_by', (string) $newPeer->id)
            ->assertJsonPath('data.tasks.0.status', 'OPEN')->assertJsonPath('data.tasks.1.status', 'IN_PROGRESS');
        $this->postJson("{$report}/acknowledge", ['version' => 4])->assertConflict();
        $this->patchJson("{$report}/tasks/{$first}", ['version' => 2, 'status' => 'DONE'])->assertConflict()
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->patchJson("{$report}/tasks/{$first}", ['version' => 1, 'status' => 'DONE'])->assertOk();
        $this->getJson('/api/v1/audit-logs')->assertForbidden();

        $this->as($replacement);
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('data.0.event', 'HANDOVER_ACKNOWLEDGED')->assertJsonPath('data.0.subject_id', (string) $id);

        $this->as($outsideSupervisor);
        $this->getJson($report)->assertNotFound();
        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonCount(0, 'data');
        $this->as($supervisor);
        $audit = $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonFragment(['action' => 'DRAFT_ADOPTED'])
            ->assertJsonFragment(['action' => 'ACKNOWLEDGED'])->assertJsonFragment(['action' => 'DELETED']);
        $this->assertStringNotContainsString('privat', $audit->getContent());
        $this->assertDatabaseCount('audit_logs', 16);
    }

    public function test_recipient_must_be_one_of_the_earliest_and_is_rechecked_on_submit(): void
    {
        $department = DB::table('departments')->insertGetId(['name' => 'Operasional']);
        $type = $this->shiftType($department);
        $author = $this->user($department);
        $first = $this->user($department);
        $second = $this->user($department);
        $source = $this->assignment($author, $type, '2030-01-02 08:00Z', '2030-01-02 12:00Z');
        $this->assignment($first, $type, '2030-01-02 11:00Z', '2030-01-02 12:30Z');
        $this->as($author);
        $payload = ['source_assignment_id' => $source, 'summary' => 'Ringkasan'];
        $this->postJson('/api/v1/handovers', $payload)->assertConflict()->assertJsonPath('error.code', 'NO_RECIPIENT');
        $earliest = $this->assignment($first, $type, '2030-01-02 13:00Z', '2030-01-02 15:00Z');
        $tied = $this->assignment($second, $type, '2030-01-02 13:00Z', '2030-01-02 15:00Z');
        $this->postJson('/api/v1/handovers', $payload)->assertConflict()
            ->assertJsonPath('error.code', 'RECIPIENT_SELECTION_REQUIRED');
        $id = $this->postJson('/api/v1/handovers', $payload + ['recipient_assignment_id' => $tied])
            ->assertCreated()->json('data.id');
        DB::table('shift_assignments')->where('id', $tied)->update(['status' => 'CANCELLED']);
        $this->postJson("/api/v1/handovers/{$id}/submit", ['version' => 1])->assertConflict()
            ->assertJsonPath('error.code', 'INVALID_RECIPIENT');
        $this->postJson("/api/v1/handovers/{$id}/submit", ['version' => 1, 'recipient_assignment_id' => $earliest])
            ->assertOk()->assertJsonPath('data.recipient_assignment_id', (string) $earliest);
    }

    public function test_failed_audit_write_rolls_back_report_creation(): void
    {
        $department = DB::table('departments')->insertGetId(['name' => 'Operasional']);
        $type = $this->shiftType($department);
        $author = $this->user($department);
        $peer = $this->user($department);
        $source = $this->assignment($author, $type, '2030-01-02 08:00Z', '2030-01-02 12:00Z');
        $this->assignment($peer, $type, '2030-01-02 13:00Z', '2030-01-02 17:00Z');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_reject_draft_audit CHECK (action <> 'DRAFT_CREATED')");

        $this->as($author);
        $this->postJson('/api/v1/handovers', ['source_assignment_id' => $source])->assertInternalServerError();
        $this->assertDatabaseCount('handover_reports', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function as(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function user(int $department, string $role = 'EMPLOYEE'): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $role, 'email' => strtolower(fake()->unique()->safeEmail()), 'password_hash' => 'unused',
            'department_id' => $department, 'role' => $role,
        ]);

        return User::findOrFail($id);
    }

    private function shiftType(int $department): int
    {
        return DB::table('shift_types')->insertGetId([
            'department_id' => $department, 'name' => 'Reguler', 'start_local_time' => '08:00', 'end_local_time' => '17:00',
        ]);
    }

    private function assignment(User $employee, int $type, string $start, string $end): int
    {
        return DB::table('shift_assignments')->insertGetId([
            'department_id' => $employee->department_id, 'shift_type_id' => $type, 'employee_id' => $employee->id,
            'created_by' => $employee->id, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'SCHEDULED',
        ]);
    }
}
