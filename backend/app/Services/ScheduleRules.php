<?php

namespace App\Services;

use App\Http\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ScheduleRules
{
    public static function check(int $employee, object $type, CarbonImmutable $start, CarbonImmutable $end, array $excluded, int $rest): void
    {
        if ($type->requires_qualification && ! DB::table('employee_qualifications')
            ->where('employee_id', $employee)->where('shift_type_id', $type->id)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', self::utc($start)))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', self::utc($end)))->exists()) {
            throw new ApiProblem(409, 'QUALIFICATION_REQUIRED');
        }
        $schedule = DB::table('shift_assignments')->where('employee_id', $employee)
            ->whereIn('status', ['SCHEDULED', 'COMPLETED'])->whereNotIn('id', $excluded);
        if ((clone $schedule)->where('starts_at', '<', self::utc($end))->where('ends_at', '>', self::utc($start))->exists()) {
            throw new ApiProblem(409, 'ASSIGNMENT_OVERLAP');
        }
        if ($rest > 0 && $schedule->where('starts_at', '<', self::utc($end->addMinutes($rest)))
            ->where('ends_at', '>', self::utc($start->subMinutes($rest)))->exists()) {
            throw new ApiProblem(409, 'MINIMUM_REST_VIOLATION');
        }
    }

    public static function utc(CarbonImmutable $date): string
    {
        return $date->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
