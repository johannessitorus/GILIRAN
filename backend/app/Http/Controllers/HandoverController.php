<?php

namespace App\Http\Controllers;

use App\Http\ApiProblem;
use App\Services\ScheduleRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class HandoverController extends Controller
{
    private const CONTENT = ['summary' => 'nullable|string', 'issues' => 'nullable|string'];

    private const TASK_CONTENT = ['title' => 'required|string|max:255', 'details' => 'nullable|string'];

    private const VERSION = ['version' => 'required|integer|min:1'];

    public function index(Request $request)
    {
        return $this->page($request, $this->visible($this->actor($request)), 'r.id');
    }

    public function show(Request $request, string $id)
    {
        $row = $this->visible($this->actor($request))->where('r.id', $id)->first();
        abort_unless($row, 404);

        return $this->result($row);
    }

    public function auditIndex(Request $request)
    {
        $actor = $this->actor($request);
        abort_unless(in_array($actor->role, ['ADMIN', 'SUPERVISOR'], true), 403);
        $query = DB::table('audit_logs')->when($actor->role === 'SUPERVISOR',
            fn ($q) => $q->where('department_id', $actor->department_id));

        return $this->page($request, $query->orderByDesc('created_at'), 'id');
    }

    public function store(Request $request)
    {
        $actor = $this->actor($request);
        abort_unless($actor->role === 'EMPLOYEE', 403);
        $data = $this->input($request, self::CONTENT + [
            'source_assignment_id' => 'required|integer|min:1',
            'recipient_assignment_id' => 'sometimes|integer|min:1',
        ]);

        return DB::transaction(function () use ($actor, $data) {
            $this->lockContext($actor);
            $source = DB::table('shift_assignments')->where('department_id', $actor->department_id)
                ->lockForUpdate()->find($data['source_assignment_id']);
            abort_unless($source, 404);
            abort_unless($source->employee_id === $actor->id, 403);
            $this->requireState($source->status !== 'CANCELLED');
            $this->requireState(! DB::table('handover_reports')->where('source_assignment_id', $source->id)->exists(), 'HANDOVER_EXISTS');
            $recipient = $this->recipient($source, $data['recipient_assignment_id'] ?? null);
            $id = DB::table('handover_reports')->insertGetId(Arr::only($data, array_keys(self::CONTENT)) + [
                'source_assignment_id' => $source->id, 'recipient_assignment_id' => $recipient,
                'draft_owner_id' => $actor->id,
            ]);
            $row = DB::table('handover_reports')->find($id);
            $this->audit($actor, 'handovers', 'DRAFT_CREATED', null, $row);

            return $this->result($row, 201)->header('Location', "/api/v1/handovers/{$id}");
        }, 3);
    }

    public function change(Request $request, string $id, string $action = 'update')
    {
        $actor = $this->actor($request);
        abort_unless($actor->role === 'EMPLOYEE', 403);
        $rules = self::VERSION;
        if (in_array($action, ['update', 'submit'], true)) {
            $rules += ['recipient_assignment_id' => 'sometimes|integer|min:1'];
        }
        if ($action === 'update') {
            $rules += self::CONTENT;
        }
        $data = $this->input($request, $rules);

        return DB::transaction(function () use ($actor, $id, $action, $data) {
            [$report, $source, $recipient] = $this->lockReport($actor, $id);
            $time = CarbonImmutable::now();
            if ($action === 'acknowledge') {
                abort_unless($recipient->employee_id === $actor->id, 403);
                $this->requireState($report->status === 'SUBMITTED' && $recipient->status !== 'CANCELLED');
                $this->requireState(CarbonImmutable::parse($recipient->starts_at) <= $time, 'RECIPIENT_SHIFT_NOT_STARTED');
                $values = [
                    'status' => 'ACKNOWLEDGED', 'acknowledged_by' => $actor->id,
                    'acknowledged_at' => ScheduleRules::utc($time),
                ];
            } else {
                abort_unless($source->employee_id === $actor->id, 403);
                $this->requireState($report->status === 'DRAFT' && $source->status !== 'CANCELLED');
                $values = $this->draftChange($actor, $report, $source, $data, $action, $time);
            }
            $this->checkVersion($report, $data);
            DB::table('handover_reports')->where('id', $id)->update($values + ['version' => $report->version + 1]);
            $row = DB::table('handover_reports')->find($id);
            $event = match ($action) {
                'update' => 'DRAFT_UPDATED',
                'adopt' => 'DRAFT_ADOPTED',
                'submit' => 'SUBMITTED',
                'acknowledge' => 'ACKNOWLEDGED',
            };
            $this->audit($actor, 'handovers', $event, $report, $row);

            return $this->result($row);
        }, 3);
    }

    public function task(Request $request, string $id, ?string $taskId = null)
    {
        $actor = $this->actor($request);
        abort_unless(in_array($actor->role, ['EMPLOYEE', 'SUPERVISOR'], true), 403);
        $data = $this->input($request, match ($request->method()) {
            'POST' => self::TASK_CONTENT,
            'DELETE' => self::VERSION,
            default => self::VERSION + ['status' => 'sometimes|in:OPEN,IN_PROGRESS,DONE',
                'title' => 'sometimes|required|string|max:255', 'details' => 'nullable|string'],
        });

        return DB::transaction(function () use ($request, $actor, $id, $taskId, $data) {
            [$report, $source, $recipient] = $this->lockReport($actor, $id);
            $draft = $report->status === 'DRAFT';
            if ($draft) {
                abort_unless($actor->role === 'EMPLOYEE' && $source->employee_id === $actor->id, 403);
                $this->requireState($report->draft_owner_id === $actor->id, 'DRAFT_ADOPTION_REQUIRED');
                $this->requireState($source->status !== 'CANCELLED');
            } else {
                abort_unless($actor->role === 'SUPERVISOR' || $recipient->employee_id === $actor->id, 403);
                $this->requireState($request->isMethod('PATCH'));
            }

            $tasks = DB::table('handover_tasks')->where('handover_report_id', $id);
            $old = $taskId === null ? null : (clone $tasks)->lockForUpdate()->find($taskId);
            abort_if($taskId !== null && ! $old, 404);
            if ($old) {
                $this->checkVersion($old, $data);
            }
            if ($request->isMethod('DELETE')) {
                $tasks->where('id', $taskId)->delete();
                $this->audit($actor, 'handover_tasks', 'DELETED', $old, null);

                return response()->noContent();
            }
            $values = $draft ? $this->taskContent($data) : $this->taskStatus($old, $data);
            $values['updated_by'] = $actor->id;
            if ($old) {
                $tasks->where('id', $taskId)->update($values + ['version' => $old->version + 1]);
            } else {
                $taskId = $tasks->insertGetId($values + ['handover_report_id' => $id, 'created_by' => $actor->id]);
            }
            $row = DB::table('handover_tasks')->find($taskId);
            $event = $old ? ($draft ? 'UPDATED' : 'STATUS_CHANGED') : 'CREATED';
            $this->audit($actor, 'handover_tasks', $event, $old, $row);

            return response()->json(['data' => $this->project($row)], $old ? 200 : 201);
        }, 3);
    }

    private function draftChange(object $actor, object $report, object $source, array $data, string $action, CarbonImmutable $time): array
    {
        if ($action === 'adopt') {
            $this->requireState($report->draft_owner_id !== $actor->id);

            return ['draft_owner_id' => $actor->id];
        }

        $this->requireState($report->draft_owner_id === $actor->id, 'DRAFT_ADOPTION_REQUIRED');
        $recipient = $this->recipient($source, $data['recipient_assignment_id'] ?? $report->recipient_assignment_id);
        if ($action === 'submit') {
            if (trim($report->summary ?? '') === '') {
                throw ValidationException::withMessages(['summary' => 'Isi ringkasan sebelum mengirim laporan.']);
            }

            return ['recipient_assignment_id' => $recipient, 'status' => 'SUBMITTED',
                'submitted_by' => $actor->id, 'submitted_at' => ScheduleRules::utc($time)];
        }

        return Arr::only($data, array_keys(self::CONTENT)) + ['recipient_assignment_id' => $recipient];
    }

    private function recipient(object $source, ?int $selected): int
    {
        $candidates = DB::table('shift_assignments as a')->join('users as u', 'a.employee_id', '=', 'u.id')
            ->where('a.department_id', $source->department_id)->where('u.department_id', $source->department_id)
            ->where('u.active', true)->where('u.role', 'EMPLOYEE')->where('a.status', '<>', 'CANCELLED')
            ->where('a.id', '<>', $source->id)->where('a.starts_at', '>=', $source->ends_at)
            ->select('a.id', 'a.starts_at');
        $first = (clone $candidates)->orderBy('a.starts_at')->orderBy('a.id')->first();
        $this->requireState($first !== null, 'NO_RECIPIENT');
        $earliest = $candidates->where('a.starts_at', $first->starts_at);
        if ($selected === null) {
            $this->requireState($earliest->count() === 1, 'RECIPIENT_SELECTION_REQUIRED');

            return $first->id;
        }
        $this->requireState($earliest->where('a.id', $selected)->exists(), 'INVALID_RECIPIENT');

        return $selected;
    }

    private function lockContext(object $actor): void
    {
        $department = DB::table('departments')->lockForUpdate()->find($actor->department_id);
        abort_unless($department?->active, 403);
        $current = DB::table('users')->lockForUpdate()->find($actor->id);
        abort_unless($current?->active && $current->role === $actor->role
            && $current->department_id === $actor->department_id, 403);
    }

    private function lockReport(object $actor, string $id): array
    {
        $this->lockContext($actor);
        $snapshot = DB::table('handover_reports as r')
            ->join('shift_assignments as s', 'r.source_assignment_id', '=', 's.id')
            ->where('s.department_id', $actor->department_id)->where('r.id', $id)->select('r.*')->first();
        abort_unless($snapshot, 404);
        $assignments = DB::table('shift_assignments')->whereIn('id', [
            $snapshot->source_assignment_id, $snapshot->recipient_assignment_id,
        ])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [
            DB::table('handover_reports')->lockForUpdate()->find($id),
            $assignments[$snapshot->source_assignment_id], $assignments[$snapshot->recipient_assignment_id],
        ];
    }

    private function visible(object $actor)
    {
        $query = DB::table('handover_reports as r')
            ->join('shift_assignments as s', 'r.source_assignment_id', '=', 's.id')
            ->join('shift_assignments as t', 'r.recipient_assignment_id', '=', 't.id')->select('r.*');
        if ($actor->role !== 'ADMIN') {
            $query->where('s.department_id', $actor->department_id);
        }
        if ($actor->role === 'EMPLOYEE') {
            $query->where(function ($q) use ($actor) {
                $q->where('s.employee_id', $actor->id)->orWhere(function ($sent) use ($actor) {
                    $sent->where('r.status', '<>', 'DRAFT')->where(fn ($related) => $related
                        ->where('t.employee_id', $actor->id)->orWhere('r.submitted_by', $actor->id)
                        ->orWhere('r.acknowledged_by', $actor->id));
                });
            });
        }

        return $query;
    }

    private function taskContent(array $data): array
    {
        $this->requireState(! array_key_exists('status', $data));
        $values = Arr::only($data, ['title', 'details']);
        if (isset($values['title'])) {
            $values['title'] = trim($values['title']);
            if ($values['title'] === '') {
                throw ValidationException::withMessages(['title' => 'Isi judul tugas.']);
            }
        }
        if ($values === []) {
            throw ValidationException::withMessages(['body' => 'Isi judul atau detail tugas.']);
        }

        return $values;
    }

    private function taskStatus(object $old, array $data): array
    {
        $this->requireState(array_key_exists('status', $data) && ! array_key_exists('title', $data)
            && ! array_key_exists('details', $data));
        $allowed = match ($old->status) {
            'OPEN' => ['IN_PROGRESS', 'DONE'],
            'IN_PROGRESS' => ['OPEN', 'DONE'],
            'DONE' => ['OPEN'],
        };
        $this->requireState(in_array($data['status'], $allowed, true));

        return ['status' => $data['status']];
    }

    private function checkVersion(object $row, array $data): void
    {
        $this->requireState($row->version === (int) $data['version'], 'VERSION_CONFLICT');
    }

    private function requireState(bool $condition, string $code = 'INVALID_TRANSITION'): void
    {
        if (! $condition) {
            throw new ApiProblem(409, $code);
        }
    }

    private function audit(object $actor, string $type, string $action, ?object $before, ?object $after): void
    {
        $keys = ['source_assignment_id', 'recipient_assignment_id', 'draft_owner_id', 'submitted_by',
            'submitted_at', 'acknowledged_by', 'acknowledged_at', 'handover_report_id', 'created_by',
            'updated_by', 'status', 'version'];
        DB::table('audit_logs')->insert([
            'actor_id' => $actor->id, 'department_id' => $actor->department_id, 'entity_type' => $type,
            'entity_id' => ($after ?? $before)->id, 'action' => $action, 'outcome' => 'SUCCESS',
            'metadata' => json_encode([
                'before' => Arr::only((array) $before, $keys), 'after' => Arr::only((array) $after, $keys),
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function actor(Request $request): object
    {
        if (! $request->user()?->active) {
            throw new ApiProblem(401, 'UNAUTHENTICATED');
        }

        return (object) $request->user()->getAttributes();
    }

    private function input(Request $request, array $rules): array
    {
        $body = $request->json()->all();
        $extra = array_diff_key($body, $rules);
        if ($extra !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_keys($extra), 'Atribut tidak dikenal.'));
        }

        return Validator::make($body, $rules)->validate();
    }

    private function result(object $row, int $status = 200)
    {
        $tasks = DB::table('handover_tasks')->where('handover_report_id', $row->id)->orderBy('id')->get();

        return response()->json(['data' => $this->project($row) + [
            'tasks' => $tasks->map(fn ($task) => $this->project($task)),
        ]], $status);
    }

    private function page(Request $request, $query, string $order)
    {
        $input = $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|min:1|max:100']);
        $page = $query->orderByDesc($order)->paginate($input['per_page'] ?? 25, ['*'], 'page', $input['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => $this->project($row)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    private function project(object $row): array
    {
        $data = (array) $row;
        foreach ($data as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_by'))) {
                $data[$key] = (string) $value;
            }
            if ($value !== null && str_ends_with($key, '_at')) {
                $data[$key] = ScheduleRules::utc(CarbonImmutable::parse($value));
            }
        }
        if (isset($data['metadata'])) {
            $data['metadata'] = json_decode($data['metadata'], flags: JSON_THROW_ON_ERROR);
        }

        return $data;
    }
}
