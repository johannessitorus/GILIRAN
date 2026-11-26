<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::getDatabaseName(), 'giliran_test')) {
            throw new \RuntimeException('Gunakan basis data PostgreSQL khusus uji dengan awalan giliran_test.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2030-01-01T00:00:00Z'));
    }

    public function test_admin_can_create_employee_and_supervisor_cannot(): void
    {
        $department = $this->department('Operasional');
        $admin = $this->user('ADMIN', null);
        $supervisor = $this->user('SUPERVISOR', $department);
        $payload = ['name' => 'Karyawan Baru', 'role' => 'EMPLOYEE', 'email' => 'Baru@example.test',
            'password' => 'Demo-password-123', 'department_id' => (string) $department];

        $this->actingAs($supervisor, 'web')->postJson('/api/v1/users', $payload)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'web')->postJson('/api/v1/users', $payload)->assertCreated()
            ->assertJsonPath('data.email', 'baru@example.test')->assertJsonPath('data.department_id', (string) $department)
            ->assertJsonMissingPath('data.password_hash');
        $this->postJson('/api/v1/users', $payload)->assertConflict()->assertJsonPath('error.code', 'DUPLICATE_EMAIL');
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'CREATED', 'entity_type' => 'users']);
    }

    public function test_scoped_scheduling_checks_overlap_rest_and_versions(): void
    {
        $department = $this->department('Operasional');
        $other = $this->department('Dukungan');
        $supervisor = $this->user('SUPERVISOR', $department);
        $employee = $this->user('EMPLOYEE', $department);
        $outside = $this->user('EMPLOYEE', $other);
        $type = $this->shiftType($department);
        $payload = ['employee_id' => (string) $employee->id, 'shift_type_id' => (string) $type,
            'starts_at' => '2030-01-02T22:00:00Z', 'ends_at' => '2030-01-03T06:00:00Z'];

        $this->actingAs($supervisor, 'web');
        $first = $this->postJson('/api/v1/assignments', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/assignments', array_replace($payload, ['employee_id' => (string) $outside->id]))->assertNotFound();
        $this->postJson('/api/v1/assignments', array_replace($payload, ['starts_at' => '2030-01-03T01:00:00Z', 'ends_at' => '2030-01-03T08:00:00Z']))
            ->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_OVERLAP');
        $next = array_replace($payload, ['starts_at' => '2030-01-03T06:00:00Z', 'ends_at' => '2030-01-03T14:00:00Z']);
        $this->postJson('/api/v1/assignments', $next)->assertConflict()->assertJsonPath('error.code', 'MINIMUM_REST_VIOLATION');
        DB::table('system_settings')->where('setting_key', 'minimum_rest_minutes')->update(['setting_value' => '0']);
        $this->postJson('/api/v1/assignments', $next)->assertCreated();
        $this->postJson("/api/v1/assignments/{$first}/cancel", ['version' => 2])->assertConflict();
        $this->postJson("/api/v1/assignments/{$first}/cancel", ['version' => 1])->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_employee_calendar_projects_data_and_respects_department(): void
    {
        $department = $this->department('Operasional');
        $other = $this->department('Dukungan');
        $employee = $this->user('EMPLOYEE', $department);
        $peer = $this->user('EMPLOYEE', $department);
        $outside = $this->user('EMPLOYEE', $other);
        $type = $this->shiftType($department);
        $otherType = $this->shiftType($other);
        foreach ([[$employee, $type], [$peer, $type], [$outside, $otherType]] as [$owner, $shiftType]) {
            DB::table('shift_assignments')->insert([
                'employee_id' => $owner->id, 'department_id' => $owner->department_id, 'shift_type_id' => $shiftType,
                'created_by' => $owner->id, 'starts_at' => '2030-01-02T08:00:00Z',
                'ends_at' => '2030-01-02T16:00:00Z', 'status' => 'SCHEDULED',
            ]);
        }
        $range = '/api/v1/assignments?from=2030-01-02T00:00:00Z&to=2030-01-03T00:00:00Z';
        $this->getJson($range)->assertUnauthorized();
        $this->actingAs($employee, 'web')->getJson($range)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_id', (string) $employee->id);
        $this->getJson($range.'&view=team')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.display_name', $peer->name)->assertJsonMissingPath('data.1.employee_id')
            ->assertJsonMissingPath('data.1.email');
        $outsideId = DB::table('shift_assignments')->where('employee_id', $outside->id)->value('id');
        $this->getJson("/api/v1/assignments/{$outsideId}")->assertNotFound();
    }

    public function test_shift_type_catalog_is_paginated_and_scoped_to_the_actor(): void
    {
        $department = $this->department('Operasional');
        $other = $this->department('Dukungan');
        $type = $this->shiftType($department);
        $otherType = $this->shiftType($other);
        $route = '/api/v1/shift-types';

        $this->getJson($route)->assertUnauthorized();
        foreach (['SUPERVISOR', 'EMPLOYEE'] as $role) {
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->user($role, $department), 'web')->getJson($route)
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', (string) $type)
                ->assertJsonPath('data.0.active', true)
                ->assertJsonPath('meta.total', 1);
        }
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user('ADMIN', null), 'web')->getJson($route.'?per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.id', (string) $otherType)->assertJsonPath('meta.total', 2);
    }

    private function department(string $name): int
    {
        return DB::table('departments')->insertGetId(['name' => $name]);
    }

    private function user(string $role, ?int $department): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Pengguna '.uniqid(), 'email' => uniqid().'@example.test', 'role' => $role,
            'department_id' => $department, 'password_hash' => Hash::make('Demo-password-123'),
        ]);

        return User::query()->findOrFail($id);
    }

    private function shiftType(int $department): int
    {
        return DB::table('shift_types')->insertGetId([
            'department_id' => $department, 'name' => 'Reguler', 'start_local_time' => '08:00', 'end_local_time' => '16:00',
        ]);
    }
}
