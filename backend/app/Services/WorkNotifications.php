<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class WorkNotifications
{
    public static function send(string $event, int $subject, int $department, array $recipients, ?int $actor, bool $supervisors = false): void
    {
        $users = DB::table('users')->where('department_id', $department)->where('active', true)
            ->where(function ($query) use ($recipients, $supervisors) {
                $query->whereIn('id', $recipients);
                if ($supervisors) {
                    $query->orWhere('role', 'SUPERVISOR');
                }
            });
        if ($actor !== null) {
            $users->where('id', '<>', $actor);
        }
        $rows = $users->pluck('id')->map(fn ($id) => [
            'user_id' => $id, 'department_id' => $department, 'event' => $event, 'subject_id' => $subject,
        ])->all();

        if ($rows !== []) {
            DB::table('work_notifications')->insert($rows);
        }
    }
}
