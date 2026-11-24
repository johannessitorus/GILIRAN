<?php

use App\Services\ShiftSwaps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('giliran:demo', function () {
    if (! app()->environment('local')) {
        $this->error('Data demo hanya tersedia di lingkungan lokal.');

        return 1;
    }

    $password = bin2hex(random_bytes(12));
    $created = DB::transaction(function () use ($password) {
        $department = DB::table('departments')->where('name', 'Operasional Demo')->value('id')
            ?? DB::table('departments')->insertGetId(['name' => 'Operasional Demo']);
        $created = [];
        foreach (['ADMIN' => 'Admin Demo', 'SUPERVISOR' => 'Supervisor Demo', 'EMPLOYEE' => 'Karyawan Demo'] as $role => $name) {
            $email = strtolower($role).'@giliran.test';
            if (! DB::table('users')->where('email', $email)->exists()) {
                DB::table('users')->insert([
                    'name' => $name, 'email' => $email, 'role' => $role,
                    'department_id' => $role === 'ADMIN' ? null : $department,
                    'password_hash' => Hash::make($password), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $created[] = $email;
            }
        }
        DB::table('shift_types')->insertOrIgnore([
            'department_id' => $department, 'name' => 'Pagi',
            'start_local_time' => '08:00', 'end_local_time' => '16:00',
        ]);

        return $created;
    });
    if ($created === []) {
        $this->info('Akun demo sudah tersedia.');

        return 0;
    }
    $this->line('Akun baru: '.implode(', ', $created));
    $this->line('Kata sandi lokal yang dibuat sekali: '.$password);

    return 0;
})->purpose('Membuat akun dan tipe shift fiktif untuk uji lokal');

Artisan::command('giliran:complete-assignments', function () {
    $ids = DB::table('shift_assignments')->where('status', 'SCHEDULED')
        ->where('ends_at', '<=', now())->orderBy('id')->pluck('id');
    foreach ($ids as $id) {
        DB::transaction(function () use ($id) {
            $snapshot = DB::table('shift_assignments')->find($id);
            DB::table('departments')->where('id', $snapshot->department_id)->sharedLock()->first();
            DB::table('users')->where('id', $snapshot->employee_id)->lockForUpdate()->first();
            $row = DB::table('shift_assignments')->where('id', $id)->lockForUpdate()->first();
            if ($row->status !== 'SCHEDULED' || $row->employee_id !== $snapshot->employee_id || CarbonImmutable::parse($row->ends_at)->isFuture()) {
                return;
            }
            DB::table('shift_assignments')->where('id', $id)->update([
                'status' => 'COMPLETED', 'version' => $row->version + 1, 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'department_id' => $row->department_id, 'action' => 'COMPLETED', 'entity_type' => 'assignments',
                'entity_id' => $id, 'outcome' => 'SUCCESS',
                'metadata' => json_encode(['before' => ['status' => 'SCHEDULED'], 'after' => ['status' => 'COMPLETED']], JSON_THROW_ON_ERROR),
            ]);
        }, 3);
    }
})->purpose('Menandai penugasan yang sudah berakhir sebagai selesai');

Schedule::command('giliran:complete-assignments')->everyMinute()->withoutOverlapping();

Artisan::command('giliran:expire-swaps', function (ShiftSwaps $swaps) {
    DB::table('shift_swap_requests')->whereIn('status', ShiftSwaps::PENDING)
        ->where('expires_at', '<=', now())->select('id')
        ->chunkById(100, function ($rows) use ($swaps) {
            foreach ($rows as $row) {
                $swaps->expire($row->id);
            }
        });
})->purpose('Menutup permintaan tukar shift yang kedaluwarsa');

Schedule::command('giliran:expire-swaps')->everyMinute()->withoutOverlapping();
