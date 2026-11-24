<?php

namespace App\Services;

use App\Http\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ShiftSwaps
{
    public const PENDING = ['PENDING_PEER', 'PENDING_APPROVAL'];

    public function create(object $actor, int $sourceId, int $targetId, string $reason): object
    {
        abort_unless($actor->role === 'EMPLOYEE', 403);
        $ids = [$sourceId, $targetId];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            foreach ($this->expiredFor($ids) as $expired) {
                $this->expire($expired);
            }
            try {
                return DB::transaction(fn () => $this->reserve($actor, $sourceId, $targetId, $reason), 3);
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) !== '23505' || ! str_contains($e->getMessage(), 'shift_swap_reservations_pkey')) {
                    throw $e;
                }
            } catch (ApiProblem $e) {
                if ($e->problemCode !== 'ASSIGNMENT_RESERVED') {
                    throw $e;
                }
            }
            if ($this->expiredFor($ids)->isEmpty()) {
                break;
            }
        }
        throw new ApiProblem(409, 'ASSIGNMENT_RESERVED');
    }

    private function reserve(object $actor, int $sourceId, int $targetId, string $reason): object
    {
        $ids = [$sourceId, $targetId];
        $department = DB::table('departments')->where('id', $actor->department_id)->sharedLock()->first();
        abort_unless($department && $department->active, 403);
        $policy = $this->policy();
        $snapshot = DB::table('shift_assignments')->where('department_id', $department->id)->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($snapshot->count() === 2, 404);
        $owners = $this->users([$actor->id, $snapshot[$sourceId]->employee_id, $snapshot[$targetId]->employee_id]);
        $this->checkActor($actor, $owners[$actor->id] ?? null);
        $assignments = $this->assignments($ids);
        $source = $assignments[$sourceId];
        $target = $assignments[$targetId];
        abort_unless($source->employee_id === $actor->id, 403);
        if (! isset($owners[$target->employee_id])) {
            throw new ApiProblem(409, 'ASSIGNMENT_CHANGED');
        }
        if ($source->employee_id === $target->employee_id) {
            throw new ApiProblem(409, 'INELIGIBLE_EMPLOYEE');
        }
        $time = CarbonImmutable::now();
        $firstStart = min(CarbonImmutable::parse($source->starts_at), CarbonImmutable::parse($target->starts_at));
        if (DB::table('shift_swap_reservations')->whereIn('assignment_id', $ids)->exists()) {
            throw new ApiProblem(409, 'ASSIGNMENT_RESERVED');
        }
        $this->validateExchange($source, $target, $department->id, $owners, $policy, $time);
        if ($firstStart < $time->addMinutes($policy['request_notice_minutes'])) {
            throw new ApiProblem(409, 'NOTICE_TOO_SHORT');
        }
        $id = DB::table('shift_swap_requests')->insertGetId([
            'requester_id' => $actor->id, 'target_employee_id' => $target->employee_id,
            'source_assignment_id' => $sourceId, 'target_assignment_id' => $targetId,
            'department_id' => $department->id, 'reason' => $reason, 'status' => 'PENDING_PEER',
            'source_version_at_request' => $source->version, 'target_version_at_request' => $target->version,
            'expires_at' => ScheduleRules::utc(min($firstStart, $time->addHours($policy['swap_expiration_hours']))),
            'created_at' => ScheduleRules::utc($time), 'updated_at' => ScheduleRules::utc($time),
        ]);
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $assignmentId) {
            DB::table('shift_swap_reservations')->insert([
                'assignment_id' => $assignmentId, 'swap_request_id' => $id,
                'side' => $assignmentId === $sourceId ? 'SOURCE' : 'TARGET',
            ]);
        }
        $request = DB::table('shift_swap_requests')->find($id);
        $this->record($request, $actor->id, null, 'CREATED', 'REQUESTED', $time);

        return $request;
    }

    public function decide(object $actor, int $id, string $action): object
    {
        $result = DB::transaction(function () use ($actor, $id, $action) {
            $swap = DB::table('shift_swap_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($swap && $this->visible($actor)->where('id', $id)->exists(), 404);
            $department = DB::table('departments')->where('id', $swap->department_id)->sharedLock()->first();
            $policy = $action === 'approve' ? $this->policy() : [];
            $owners = $this->users([$actor->id, $swap->requester_id, $swap->target_employee_id]);
            $this->checkActor($actor, $owners[$actor->id] ?? null);
            $allowed = match ($action) {
                'accept', 'peer-reject' => $actor->role === 'EMPLOYEE' && $actor->id === $swap->target_employee_id,
                'approve', 'reject' => $actor->role === 'SUPERVISOR' && $actor->department_id === $swap->department_id,
                'cancel' => $actor->role === 'EMPLOYEE' && $actor->id === $swap->requester_id,
            };
            abort_unless($allowed && $department->active, 403);
            $time = CarbonImmutable::now();
            if ($this->expireLocked($swap, $time) || $swap->status === 'EXPIRED') {
                return new ApiProblem(409, 'SWAP_EXPIRED');
            }
            $validState = match ($action) {
                'accept', 'peer-reject' => $swap->status === 'PENDING_PEER',
                'approve', 'reject' => $swap->status === 'PENDING_APPROVAL',
                'cancel' => in_array($swap->status, self::PENDING, true),
            };
            if (! $validState) {
                throw new ApiProblem(409, 'INVALID_TRANSITION');
            }

            return match ($action) {
                'accept' => $this->transition($swap, 'PENDING_APPROVAL', $actor->id, 'PEER_ACCEPTED', $time,
                    ['peer_responded_at' => ScheduleRules::utc($time)]),
                'peer-reject' => $this->transition($swap, 'REJECTED', $actor->id, 'PEER_REJECTED', $time,
                    ['peer_responded_at' => ScheduleRules::utc($time)]),
                'reject' => $this->transition($swap, 'REJECTED', $actor->id, 'SUPERVISOR_REJECTED', $time,
                    ['supervisor_id' => $actor->id, 'supervisor_decided_at' => ScheduleRules::utc($time)]),
                'cancel' => $this->transition($swap, 'CANCELLED', $actor->id, 'REQUESTER_CANCELLED', $time),
                'approve' => $this->approve($swap, $actor->id, $owners, $policy),
            };
        }, 3);
        if ($result instanceof ApiProblem) {
            throw $result;
        }

        return $result;
    }

    private function approve(object $swap, int $actor, $owners, array $policy): object
    {
        $ids = [$swap->source_assignment_id, $swap->target_assignment_id];
        $assignments = $this->assignments($ids);
        $source = $assignments[$swap->source_assignment_id];
        $target = $assignments[$swap->target_assignment_id];
        $time = CarbonImmutable::now();
        if ($this->expireLocked($swap, $time)) {
            return new ApiProblem(409, 'SWAP_EXPIRED');
        }
        $decision = ['supervisor_id' => $actor, 'supervisor_decided_at' => ScheduleRules::utc($time)];
        try {
            if ($source->employee_id !== $swap->requester_id || $target->employee_id !== $swap->target_employee_id
                || $source->version !== $swap->source_version_at_request || $target->version !== $swap->target_version_at_request) {
                throw new ApiProblem(409, 'ASSIGNMENT_CHANGED');
            }
            $reservations = DB::table('shift_swap_reservations')->where('swap_request_id', $swap->id)->pluck('assignment_id', 'side');
            if ($reservations->get('SOURCE') !== $source->id || $reservations->get('TARGET') !== $target->id) {
                throw new ApiProblem(409, 'ASSIGNMENT_RESERVED');
            }
            $this->validateExchange($source, $target, $swap->department_id, $owners, $policy, $time);
        } catch (ApiProblem $problem) {
            $this->transition($swap, 'CANCELLED', $actor, $problem->problemCode, $time, $decision);

            return $problem;
        }
        DB::statement('SET CONSTRAINTS assignments_no_overlap DEFERRED');
        foreach ([[$source, $target->employee_id], [$target, $source->employee_id]] as [$assignment, $newOwner]) {
            DB::table('shift_assignments')->where('id', $assignment->id)->update([
                'employee_id' => $newOwner, 'version' => $assignment->version + 1, 'updated_at' => ScheduleRules::utc($time),
            ]);
            $this->audit($actor, $swap->department_id, 'assignments', $assignment->id, 'SWAP_OWNERSHIP_CHANGED', $time, [
                'swap_request_id' => $swap->id, 'before' => ['employee_id' => $assignment->employee_id, 'version' => $assignment->version],
                'after' => ['employee_id' => $newOwner, 'version' => $assignment->version + 1],
            ]);
        }
        DB::statement('SET CONSTRAINTS assignments_no_overlap IMMEDIATE');

        return $this->transition($swap, 'APPROVED', $actor, 'SUPERVISOR_APPROVED', $time, $decision);
    }

    private function validateExchange(object $source, object $target, int $department, $owners, array $policy, CarbonImmutable $time): void
    {
        $ids = [$source->id, $target->id];
        $types = DB::table('shift_types')->where('department_id', $department)
            ->whereIn('id', [$source->shift_type_id, $target->shift_type_id])->get()->keyBy('id');
        foreach ([[$source, $target->employee_id], [$target, $source->employee_id]] as [$row, $newOwner]) {
            $owner = $owners[$newOwner] ?? null;
            $type = $types[$row->shift_type_id] ?? null;
            $start = CarbonImmutable::parse($row->starts_at);
            $end = CarbonImmutable::parse($row->ends_at);
            if ($row->department_id !== $department || $row->status !== 'SCHEDULED' || $start <= $time || ! $type?->active) {
                throw new ApiProblem(409, 'ASSIGNMENT_CHANGED');
            }
            if (! $owner?->active || $owner->department_id !== $department || $owner->role !== 'EMPLOYEE') {
                throw new ApiProblem(409, 'INELIGIBLE_EMPLOYEE');
            }
            if ($start->diffInMinutes($end) > $policy['max_shift_minutes']) {
                throw new ApiProblem(409, 'SHIFT_DURATION_LIMIT');
            }
            ScheduleRules::check($newOwner, $type, $start, $end, $ids, $policy['minimum_rest_minutes']);
        }
    }

    public function visible(object $actor)
    {
        $query = DB::table('shift_swap_requests');
        if ($actor->role !== 'ADMIN') {
            $query->where('department_id', $actor->department_id);
        }
        if ($actor->role === 'EMPLOYEE') {
            $query->where(fn ($q) => $q->where('requester_id', $actor->id)->orWhere('target_employee_id', $actor->id));
        }

        return $query;
    }

    public function expire(int $id): void
    {
        DB::transaction(function () use ($id) {
            $swap = DB::table('shift_swap_requests')->where('id', $id)->lockForUpdate()->first();
            if ($swap) {
                $this->expireLocked($swap, CarbonImmutable::now());
            }
        }, 3);
    }

    private function expireLocked(object $swap, CarbonImmutable $time): bool
    {
        if (! in_array($swap->status, self::PENDING, true) || CarbonImmutable::parse($swap->expires_at) > $time) {
            return false;
        }
        $this->transition($swap, 'EXPIRED', null, 'SWAP_EXPIRED', $time);

        return true;
    }

    private function expiredFor(array $ids)
    {
        return DB::table('shift_swap_reservations as reservation')
            ->join('shift_swap_requests as swap', 'swap.id', '=', 'reservation.swap_request_id')
            ->whereIn('reservation.assignment_id', $ids)->whereIn('swap.status', self::PENDING)
            ->where('swap.expires_at', '<=', ScheduleRules::utc(CarbonImmutable::now()))
            ->distinct()->orderBy('swap.id')->pluck('swap.id');
    }

    private function transition(object $swap, string $to, ?int $actor, string $reason, CarbonImmutable $time, array $metadata = []): object
    {
        $terminal = ! in_array($to, self::PENDING, true);
        DB::table('shift_swap_requests')->where('id', $swap->id)->update($metadata + [
            'status' => $to, 'terminal_reason' => $terminal ? $reason : null, 'updated_at' => ScheduleRules::utc($time),
        ]);
        if ($terminal) {
            DB::table('shift_swap_reservations')->where('swap_request_id', $swap->id)->delete();
        }
        $updated = DB::table('shift_swap_requests')->find($swap->id);
        $this->record($updated, $actor, $swap->status, $to, $reason, $time);

        return $updated;
    }

    private function record(object $swap, ?int $actor, ?string $from, string $event, string $reason, CarbonImmutable $time): void
    {
        DB::table('shift_swap_events')->insert([
            'swap_request_id' => $swap->id, 'actor_id' => $actor, 'event_type' => $event,
            'from_status' => $from, 'to_status' => $swap->status, 'reason_code' => $reason, 'created_at' => ScheduleRules::utc($time),
        ]);
        $this->audit($actor, $swap->department_id, 'swaps', $swap->id, $event, $time, [
            'from_status' => $from, 'to_status' => $swap->status, 'reason_code' => $reason,
        ]);
    }

    private function audit(?int $actor, int $department, string $type, int $id, string $action, CarbonImmutable $time, array $context): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor, 'department_id' => $department, 'action' => $action, 'entity_type' => $type,
            'entity_id' => $id, 'outcome' => 'SUCCESS', 'created_at' => ScheduleRules::utc($time),
            'metadata' => json_encode($context, JSON_THROW_ON_ERROR),
        ]);
    }

    private function users(array $ids)
    {
        return DB::table('users')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function assignments(array $ids)
    {
        return DB::table('shift_assignments')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function policy(): array
    {
        return DB::table('system_settings')->whereIn('setting_key', ['minimum_rest_minutes', 'max_shift_minutes', 'request_notice_minutes', 'swap_expiration_hours'])
            ->orderBy('setting_key')->sharedLock()->pluck('setting_value', 'setting_key')->map(fn ($value) => (int) $value)->all();
    }

    private function checkActor(object $actor, ?object $locked): void
    {
        abort_unless($locked?->active && $locked->role === $actor->role && $locked->department_id === $actor->department_id, 403);
    }
}
