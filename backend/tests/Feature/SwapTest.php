<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SwapTest extends TestCase
{
    use DatabaseMigrations;

    private array $people;

    private array $shifts;

    private int $department;

    private int $type;

    private CarbonImmutable $start;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::getDatabaseName(), 'giliran_test')) {
            throw new RuntimeException('Gunakan basis data PostgreSQL khusus uji dengan awalan giliran_test.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = DB::table('departments')->insertGetId(['name' => 'Operasional']);
        $other = DB::table('departments')->insertGetId(['name' => 'Dukungan']);
        $this->type = DB::table('shift_types')->insertGetId([
            'department_id' => $this->department, 'name' => 'Pagi', 'start_local_time' => '08:00', 'end_local_time' => '16:00',
        ]);
        $this->people = [
            'a' => $this->user('EMPLOYEE', $this->department), 'b' => $this->user('EMPLOYEE', $this->department),
            's' => $this->user('SUPERVISOR', $this->department), 'other' => $this->user('SUPERVISOR', $other),
            'admin' => $this->user('ADMIN', null),
        ];
        $this->start = CarbonImmutable::now()->addDays(4)->startOfHour();
        // Identical intervals create temporary overlap until both ownership changes are applied.
        $this->shifts = ['a' => $this->shift('a', $this->start), 'b' => $this->shift('b', $this->start)];
    }

    public function test_roles_rejection_and_competing_side_reservation(): void
    {
        $this->as('b')->postJson('/api/v1/swaps', $this->payload())->assertForbidden();
        $id = $this->propose();
        $this->as('b')->postJson('/api/v1/swaps', [
            'source_assignment_id' => $this->shifts['b'], 'target_assignment_id' => $this->shifts['a'], 'reason' => 'Saingan',
        ])->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_RESERVED');
        $this->as('a')->post("/api/v1/swaps/{$id}/accept")->assertForbidden();
        $this->as('s')->post("/api/v1/swaps/{$id}/approve")->assertConflict();
        $this->as('other')->getJson("/api/v1/swaps/{$id}")->assertNotFound();
        $this->as('b')->post("/api/v1/swaps/{$id}/peer-reject")->assertOk()->assertJsonPath('data.status', 'REJECTED');
        $this->post("/api/v1/swaps/{$id}/peer-reject")->assertConflict();
        $this->assertSame(2, DB::table('shift_swap_events')->where('swap_request_id', $id)->count());
        $this->assertSame(0, DB::table('shift_swap_reservations')->count());
        $this->assertOwners(false);
        $this->propose();
    }

    public function test_expiry_releases_reservations_at_deadline(): void
    {
        $id = $this->propose();
        $deadline = DB::table('shift_swap_requests')->where('id', $id)->value('expires_at');
        $this->travelTo(CarbonImmutable::parse($deadline));
        $this->as('a')->getJson("/api/v1/swaps/{$id}")->assertOk()->assertJsonPath('data.status', 'EXPIRED');
        $this->as('b')->post("/api/v1/swaps/{$id}/accept")->assertConflict()->assertJsonPath('error.code', 'SWAP_EXPIRED');
        $this->assertDatabaseHas('shift_swap_events', ['swap_request_id' => $id, 'to_status' => 'EXPIRED', 'actor_id' => null]);
        $this->propose();
    }

    #[TestWith([10, 'MINIMUM_REST_VIOLATION'])]
    #[TestWith([6, 'ASSIGNMENT_OVERLAP'])]
    public function test_approval_rechecks_schedule_and_preserves_it_on_conflict(int $hoursBefore, string $code): void
    {
        $id = $this->peerAccept();
        // Stagger the destination in a separate request so a new neighbor conflicts only after the swap.
        $this->as('a')->post("/api/v1/swaps/{$id}/cancel")->assertOk();
        DB::table('shift_assignments')->where('id', $this->shifts['b'])->update([
            'starts_at' => $this->start->addDays(1)->toIso8601String(), 'ends_at' => $this->start->addDays(1)->addHours(8)->toIso8601String(),
        ]);
        $id = $this->peerAccept();
        $this->shift('a', $this->start->addDays(1)->subHours($hoursBefore));
        $this->as('s')->post("/api/v1/swaps/{$id}/approve")->assertConflict()->assertJsonPath('error.code', $code);
        $this->assertDatabaseHas('shift_swap_requests', ['id' => $id, 'status' => 'CANCELLED', 'terminal_reason' => $code]);
        $this->assertSame(0, DB::table('shift_swap_reservations')->count());
        $this->assertOwners(false);
    }

    public function test_approval_rolls_back_after_second_ownership_write_fails(): void
    {
        $id = $this->peerAccept();
        DB::unprepared('CREATE FUNCTION giliran_test_fail_swap() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.version = 2 AND NEW.id = (SELECT max(id) FROM shift_assignments) THEN RAISE EXCEPTION \'Injected ownership failure\'; END IF; RETURN NEW; END; $$;
            CREATE TRIGGER giliran_test_fail_swap BEFORE UPDATE ON shift_assignments FOR EACH ROW EXECUTE FUNCTION giliran_test_fail_swap();');
        try {
            $this->as('s')->post("/api/v1/swaps/{$id}/approve")->assertServerError();
            $this->assertOwners(false);
            $this->assertDatabaseHas('shift_swap_requests', ['id' => $id, 'status' => 'PENDING_APPROVAL']);
            $this->assertSame(2, DB::table('shift_swap_reservations')->count());
            $this->assertDatabaseMissing('audit_logs', ['action' => 'SWAP_OWNERSHIP_CHANGED']);
        } finally {
            DB::unprepared('DROP TRIGGER giliran_test_fail_swap ON shift_assignments; DROP FUNCTION giliran_test_fail_swap();');
        }
    }

    public function test_concurrent_proposals_and_approvals_commit_once(): void
    {
        $results = $this->race([
            ['create', $this->people['a']->id, $this->shifts['a'], $this->shifts['b']],
            ['create', $this->people['b']->id, $this->shifts['b'], $this->shifts['a']],
        ]);
        $this->assertEqualsCanonicalizing([201, 409], array_column($results, 'status'));
        $this->assertContains('ASSIGNMENT_RESERVED', array_column($results, 'code'));
        $swap = DB::table('shift_swap_requests')->sole();
        $peer = $swap->target_employee_id === $this->people['a']->id ? 'a' : 'b';
        $this->as($peer)->post("/api/v1/swaps/{$swap->id}/accept")->assertOk();
        $this->as('admin')->post("/api/v1/swaps/{$swap->id}/approve")->assertForbidden();
        $results = $this->race([
            ['approve', $this->people['s']->id, $swap->id, 0],
            ['approve', $this->people['s']->id, $swap->id, 0],
        ]);
        $this->assertEqualsCanonicalizing([200, 409], array_column($results, 'status'));
        $this->assertContains('INVALID_TRANSITION', array_column($results, 'code'));
        $this->assertOwners(true);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'SWAP_OWNERSHIP_CHANGED')->count());
        $this->assertSame(0, DB::table('shift_swap_reservations')->count());
    }

    public function test_schedule_edit_and_approval_race_never_partially_commit(): void
    {
        $id = $this->peerAccept();
        [$approval, $edit] = $this->race([
            ['approve', $this->people['s']->id, $id, 0],
            ['patch', $this->people['s']->id, $this->shifts['a'], $this->start->addHours(1)->toIso8601String()],
        ]);
        $this->assertEqualsCanonicalizing([200, 409], [$approval['status'], $edit['status']]);
        $approved = $approval['status'] === 200;
        $this->assertOwners($approved, 2, $approved ? 2 : 1);
        $this->assertDatabaseHas('shift_swap_requests', ['id' => $id, 'status' => $approved ? 'APPROVED' : 'CANCELLED']);
        $this->assertSame(0, DB::table('shift_swap_reservations')->count());
    }

    private function race(array $jobs): array
    {
        DB::select('SELECT pg_advisory_lock(543219)');
        $processes = [];
        try {
            foreach ($jobs as $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/swap_worker.php'), ...array_map('strval', $job)], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => DB::getDatabaseName(),
                ], null, 150);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            }
        } finally {
            DB::select('SELECT pg_advisory_unlock(543219)');
        }
        $results = [];
        foreach ($processes as $process) {
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $lines = explode("\n", trim($process->getOutput()));
            $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    private function as(string $person): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->people[$person], 'web');
    }

    private function payload(): array
    {
        return ['source_assignment_id' => $this->shifts['a'], 'target_assignment_id' => $this->shifts['b'], 'reason' => 'Keperluan keluarga'];
    }

    private function propose(): string
    {
        return $this->as('a')->postJson('/api/v1/swaps', $this->payload())->assertCreated()->json('data.id');
    }

    private function peerAccept(): string
    {
        $id = $this->propose();
        $this->as('b')->post("/api/v1/swaps/{$id}/accept")->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');

        return $id;
    }

    private function assertOwners(bool $swapped, ?int $a = null, ?int $b = null): void
    {
        $this->assertDatabaseHas('shift_assignments', [
            'id' => $this->shifts['a'], 'employee_id' => $this->people[$swapped ? 'b' : 'a']->id, 'version' => $a ?? ($swapped ? 2 : 1),
        ]);
        $this->assertDatabaseHas('shift_assignments', [
            'id' => $this->shifts['b'], 'employee_id' => $this->people[$swapped ? 'a' : 'b']->id, 'version' => $b ?? ($swapped ? 2 : 1),
        ]);
    }

    private function user(string $role, ?int $department): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $role, 'email' => uniqid().'@example.test', 'role' => $role,
            'department_id' => $department, 'password_hash' => Hash::make('Demo-password-123'),
        ]);

        return User::query()->findOrFail($id);
    }

    private function shift(string $person, CarbonImmutable $start): int
    {
        return DB::table('shift_assignments')->insertGetId([
            'employee_id' => $this->people[$person]->id, 'created_by' => $this->people['s']->id,
            'department_id' => $this->department, 'shift_type_id' => $this->type,
            'status' => 'SCHEDULED', 'starts_at' => $start->toIso8601String(), 'ends_at' => $start->addHours(8)->toIso8601String(),
        ]);
    }
}
