<?php

namespace App\Http\Controllers;

use App\Http\ApiProblem;
use App\Services\ScheduleRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class FoundationController extends Controller
{
    private const ACCOUNT = ['id', 'name', 'email', 'role', 'department_id', 'active'];

    private const ASSIGNMENT = ['id', 'department_id', 'employee_id', 'shift_type_id', 'starts_at', 'ends_at', 'status', 'version'];

    public function login(Request $request)
    {
        $input = $this->input($request, [
            'email' => 'required|string|max:255|email',
            'password' => ['required', 'string', $this->passwordBytes(1)],
        ]);
        if (! $request->hasSession()) {
            throw new ApiProblem(419, 'CSRF_TOKEN_MISMATCH');
        }
        if (Auth::guard('web')->check()) {
            throw new ApiProblem(409, 'INVALID_TRANSITION');
        }
        $key = 'login:'.hash('sha256', $input['email'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);
        if (! Auth::guard('web')->attempt($input + ['active' => true])) {
            throw new ApiProblem(401, 'INVALID_CREDENTIALS');
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return $this->me($request);
    }

    public function me(Request $request)
    {
        $actor = $this->actor($request);

        return response()->json(['data' => $this->ids(Arr::only((array) $actor, self::ACCOUNT) + [
            'organization_timezone' => $this->settings()['organization_timezone'],
            'business_name' => DB::table('businesses')->where('id', $actor->business_id)->value('name'),
        ])]);
    }

    public function logout(Request $request)
    {
        $this->actor($request);
        $this->input($request, []);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function shiftTypes(Request $request)
    {
        $actor = $this->actor($request);
        $filters = Validator::make($request->query(), [
            'page' => 'integer|min:1', 'per_page' => 'integer|min:1|max:100',
        ])->validate();
        $query = DB::table('shift_types')->select('id', 'department_id', 'name', 'active');
        if ($actor->role === 'ADMIN') {
            $query->whereIn('department_id', DB::table('departments')->select('id')->where('business_id', $actor->business_id));
        } else {
            $query->where('department_id', $actor->department_id);
        }
        $page = $query->orderBy('id')->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => $this->ids((array) $row))->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function index(Request $request, string $resource)
    {
        $actor = $this->actor($request);
        $rules = ['page' => 'integer|min:1', 'per_page' => 'integer|min:1|max:100'];
        $rules += match ($resource) {
            'departments' => ['active' => 'in:true,false,1,0'],
            'users' => ['active' => 'in:true,false,1,0', 'role' => 'in:ADMIN,SUPERVISOR,EMPLOYEE', 'department_id' => 'integer|min:1'],
            'assignments' => [
                'from' => ['required', $this->timestamp()], 'to' => ['required', $this->timestamp()],
                'status' => 'in:SCHEDULED,CANCELLED,COMPLETED', 'view' => 'in:team',
                'department_id' => 'integer|min:1', 'employee_id' => 'integer|min:1',
            ],
        };
        $filters = Validator::make($request->query(), $rules)->validate();
        abort_if($resource === 'users' && $actor->role === 'EMPLOYEE', 403);
        $query = $this->visible($resource, $actor, isset($filters['view']));
        if ($resource === 'assignments') {
            abort_if(isset($filters['view']) && $actor->role !== 'EMPLOYEE', 403);
            abort_if(isset($filters['department_id']) && $actor->role !== 'ADMIN', 403);
            abort_if(isset($filters['employee_id']) && $actor->role === 'EMPLOYEE', 403);
            if (isset($filters['employee_id'])) {
                abort_unless($this->visible('users', $actor)->where('id', $filters['employee_id'])->where('role', 'EMPLOYEE')->exists(), 404);
            }
            $from = CarbonImmutable::parse($filters['from']);
            $to = CarbonImmutable::parse($filters['to']);
            if ($from >= $to || $from->addHours(31 * 24) < $to) {
                throw ValidationException::withMessages(['to' => 'Gunakan rentang hingga 31 hari.']);
            }
            $query->where('starts_at', '<', $this->utc($to))
                ->where('ends_at', '>', $this->utc($from))->orderBy('starts_at');
        }
        if (isset($filters['department_id'])) {
            abort_unless($this->visible('departments', $actor)->where('id', $filters['department_id'])->exists(), 404);
        }
        foreach (Arr::only($filters, ['active', 'role', 'status', 'department_id', 'employee_id']) as $column => $value) {
            $query->where($column, $column === 'active' ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $value);
        }
        $page = $query->orderBy('id')->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => $this->project($resource, $row, $actor)),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $id, string $resource)
    {
        $actor = $this->actor($request);
        $row = $this->visible($resource, $actor, true)->find($id);
        abort_unless($row, 404);

        return response()->json(['data' => $this->project($resource, $row, $actor)]);
    }

    public function store(Request $request, string $resource)
    {
        return $this->write($request, $resource, null);
    }

    public function update(Request $request, string $id, string $resource)
    {
        return $this->write($request, $resource, $id);
    }

    public function cancel(Request $request, string $id)
    {
        return $this->write($request, 'assignments', $id, true);
    }

    private function write(Request $request, string $resource, ?string $id, bool $cancel = false)
    {
        $actor = $this->actor($request);
        if ($id !== null) {
            abort_unless($this->visible($resource, $actor, true)->where('id', $id)->exists(), 404);
        }
        $role = $resource === 'assignments' ? 'SUPERVISOR' : 'ADMIN';
        abort_unless($actor->role === $role, 403);
        $required = $id === null ? 'required|' : 'sometimes|required|';
        $rules = match ($resource) {
            'departments' => ['name' => $required.'string|max:120', 'active' => 'boolean'],
            'users' => [
                'name' => $required.'string|max:160', 'email' => $required.'string|email|max:255',
                'password' => ['required', 'string', $this->passwordBytes(12)],
                'department_id' => 'nullable|integer|min:1', 'role' => $required.'in:ADMIN,SUPERVISOR,EMPLOYEE', 'active' => 'boolean',
            ],
            'assignments' => $cancel ? ['version' => 'required|integer|min:1'] : [
                'employee_id' => $required.'integer|min:1', 'shift_type_id' => $required.'integer|min:1',
                'starts_at' => [$id === null ? 'required' : 'sometimes', $this->timestamp()],
                'ends_at' => [$id === null ? 'required' : 'sometimes', $this->timestamp()],
            ] + ($id === null ? [] : ['version' => 'required|integer|min:1']),
        };
        if ($resource === 'users' && $id !== null) {
            $rules['password'] = ['sometimes', 'required', 'string', $this->passwordBytes(12)];
        }
        $input = $this->input($request, $rules);
        if (array_diff_key($input, ['version' => true]) === [] && ! $cancel) {
            throw ValidationException::withMessages(['body' => 'Kirim setidaknya satu atribut.']);
        }
        try {
            $row = DB::transaction(function () use ($resource, $actor, $role, $id, $input, $cancel) {
                if ($resource === 'assignments') {
                    return $this->saveAssignment($actor, $id, $input, $cancel);
                }

                return $this->saveDirectory($resource, $actor, $role, $id, $input);
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23505' || $resource === 'assignments') {
                throw $e;
            }
            throw new ApiProblem(409, $resource === 'users' ? 'DUPLICATE_EMAIL' : 'DUPLICATE_DEPARTMENT_NAME');
        }

        $response = response()->json(['data' => $this->project($resource, $row, $actor)], $id === null ? 201 : 200);
        if ($id === null) {
            $response->header('Location', "/api/v1/{$resource}/{$row->id}");
        }

        return $response;
    }

    private function saveDirectory(string $resource, object $actor, string $role, ?string $id, array $values): object
    {
        $snapshot = $id === null ? null : DB::table($resource)->where('business_id', $actor->business_id)->find($id);
        $departments = $resource === 'departments' ? [$id] : [$snapshot->department_id ?? null, $values['department_id'] ?? null];
        $locked = DB::table('departments')->where('business_id', $actor->business_id)
            ->whereIn('id', array_filter($departments))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $users = $this->lockUsers([$actor->id, $resource === 'users' ? $id : null]);
        $this->checkActor($users->get($actor->id), $actor, $role);
        $old = $resource === 'users' ? $users->get($id) : $locked->get($id);
        abort_if($id !== null && (! $old || $old->business_id !== $actor->business_id), 404);
        if ($resource === 'departments' && array_key_exists('active', $values) && ! $values['active'] && $old) {
            if (DB::table('users')->where('department_id', $id)->where('active', true)->exists()
                || DB::table('shift_types')->where('department_id', $id)->where('active', true)->exists()
                || DB::table('shift_assignments')->where('department_id', $id)->where('status', 'SCHEDULED')->where('ends_at', '>', now())->exists()) {
                throw new ApiProblem(409, 'DEPARTMENT_IN_USE');
            }
        }
        if ($resource === 'users') {
            $next = $values + (array) $old + ['active' => true, 'department_id' => null];
            if ($old && $snapshot->department_id !== $old->department_id) {
                throw new ApiProblem(409, 'VERSION_CONFLICT');
            }
            if ($next['role'] !== 'ADMIN' && $next['department_id'] === null) {
                throw ValidationException::withMessages(['department_id' => 'Departemen wajib untuk peran ini.']);
            }
            if ($next['department_id'] !== null) {
                $department = $locked->get($next['department_id']);
                abort_unless($department, 404);
                if ($next['role'] !== 'ADMIN' && $next['active'] && ! $department->active) {
                    throw new ApiProblem(409, 'DEPARTMENT_IN_USE');
                }
            }
            if ($old && (! $next['active'] || $next['role'] !== $old->role || $next['department_id'] != $old->department_id)
                && DB::table('shift_assignments')->where('employee_id', $id)->where('status', 'SCHEDULED')->where('ends_at', '>', now())->exists()) {
                throw new ApiProblem(409, 'ACCOUNT_HAS_DUTIES');
            }
            if (isset($values['password'])) {
                $values['password_hash'] = Hash::make($values['password']);
                unset($values['password']);
            }
            if ($old && (isset($values['password_hash']) || (array_key_exists('active', $values) && ! $values['active']))) {
                DB::table('sessions')->where('user_id', $id)->delete();
            }
        }
        if ($id === null) {
            $values['business_id'] = $actor->business_id;
        }
        $row = $this->save($resource, $id, $values);
        $safe = $resource === 'users' ? ['department_id', 'role', 'active'] : ['active'];
        $this->audit($actor->id, $row->department_id ?? ($resource === 'departments' ? $row->id : null),
            $resource, $row->id, $id === null ? 'CREATED' : 'UPDATED',
            Arr::only((array) $old, $safe), Arr::only((array) $row, $safe));

        return $row;
    }

    private function saveAssignment(object $actor, ?string $id, array $input, bool $cancel): object
    {
        $department = DB::table('departments')->where('id', $actor->department_id)->lockForUpdate()->first();
        abort_unless($department && $department->active, 403);
        $settings = $this->settings(true);
        $snapshot = $id === null ? null : DB::table('shift_assignments')->where('department_id', $department->id)->find($id);
        abort_if($id !== null && ! $snapshot, 404);
        $users = $this->lockUsers([$actor->id, $snapshot->employee_id ?? null, $input['employee_id'] ?? null]);
        $this->checkActor($users->get($actor->id), $actor, 'SUPERVISOR');
        $old = $snapshot ? DB::table('shift_assignments')->where('id', $id)->lockForUpdate()->first() : null;
        $now = CarbonImmutable::now();
        if ($old) {
            if ($old->version !== (int) $input['version']) {
                throw new ApiProblem(409, 'VERSION_CONFLICT');
            }
            if ($old->status !== 'SCHEDULED' || CarbonImmutable::parse($old->starts_at) <= $now) {
                throw new ApiProblem(409, 'INVALID_TRANSITION');
            }
            $reschedules = (isset($input['shift_type_id']) && (int) $input['shift_type_id'] !== $old->shift_type_id)
                || (isset($input['starts_at']) && ! CarbonImmutable::parse($input['starts_at'])->equalTo(CarbonImmutable::parse($old->starts_at)))
                || (isset($input['ends_at']) && ! CarbonImmutable::parse($input['ends_at'])->equalTo(CarbonImmutable::parse($old->ends_at)));
            if (($cancel || $reschedules) && DB::table('handover_reports')->where('status', '<>', 'DRAFT')
                ->where(fn ($q) => $q->where('source_assignment_id', $old->id)->orWhere('recipient_assignment_id', $old->id))
                ->exists()) {
                throw new ApiProblem(409, 'ASSIGNMENT_HAS_HANDOVER');
            }
        }
        if ($cancel) {
            $row = $this->save('shift_assignments', $id, ['status' => 'CANCELLED', 'version' => $old->version + 1]);
        } else {
            $next = Arr::except($input, ['version']) + (array) $old;
            $owner = $users->get($next['employee_id']);
            $type = DB::table('shift_types')->where('department_id', $department->id)->find($next['shift_type_id']);
            abort_unless($owner && $owner->role === 'EMPLOYEE' && $owner->department_id === $department->id && $type, 404);
            if (! $owner->active || ! $type->active) {
                throw new ApiProblem(409, 'INVALID_TRANSITION');
            }
            $start = CarbonImmutable::parse($next['starts_at']);
            $end = CarbonImmutable::parse($next['ends_at']);
            if ($start <= $now || $end <= $start || $start->diffInMinutes($end) > $settings['max_shift_minutes']) {
                throw ValidationException::withMessages(['starts_at' => 'Gunakan interval mendatang dengan durasi yang diizinkan.']);
            }
            ScheduleRules::check($owner->id, $type, $start, $end, $id === null ? [] : [$id], $settings['minimum_rest_minutes']);
            $values = ['department_id' => $department->id, 'employee_id' => $owner->id, 'shift_type_id' => $type->id,
                'starts_at' => $this->utc($start), 'ends_at' => $this->utc($end), 'status' => 'SCHEDULED'];
            if ($old && $old->employee_id === $owner->id && $old->shift_type_id === $type->id
                && $start->equalTo(CarbonImmutable::parse($old->starts_at)) && $end->equalTo(CarbonImmutable::parse($old->ends_at))) {
                return $old;
            }
            $row = $this->save('shift_assignments', $id, $values + ($old ?
                ['version' => $old->version + 1] : ['created_by' => $actor->id]));
        }
        $safe = ['employee_id', 'shift_type_id', 'starts_at', 'ends_at', 'status', 'version'];
        $this->audit($actor->id, $department->id, 'assignments', $row->id,
            $cancel ? 'CANCELLED' : ($old ? 'UPDATED' : 'CREATED'),
            Arr::only((array) $old, $safe), Arr::only((array) $row, $safe));

        return $row;
    }

    private function visible(string $resource, object $actor, bool $team = false)
    {
        $table = $resource === 'assignments' ? 'shift_assignments' : $resource;
        $query = DB::table($table);

        return match (true) {
            $actor->role === 'ADMIN' && $resource === 'assignments' => $query->whereIn('department_id',
                DB::table('departments')->select('id')->where('business_id', $actor->business_id)),
            $actor->role === 'ADMIN' => $query->where('business_id', $actor->business_id),
            $resource === 'departments' => $query->where('id', $actor->department_id),
            $resource === 'users' && $actor->role === 'EMPLOYEE' => $query->where('id', $actor->id),
            $resource === 'users' => $query->where('department_id', $actor->department_id)->where('role', 'EMPLOYEE'),
            $actor->role === 'EMPLOYEE' && ! $team => $query->where('employee_id', $actor->id)->where('department_id', $actor->department_id),
            default => $query->where('department_id', $actor->department_id),
        };
    }

    private function project(string $resource, object $row, object $actor): array
    {
        $data = Arr::only((array) $row, match ($resource) {
            'departments' => ['id', 'name', 'active'],
            'users' => $actor->role === 'SUPERVISOR' ? ['id', 'name', 'active'] : self::ACCOUNT,
            'assignments' => self::ASSIGNMENT,
        });
        if ($resource === 'assignments') {
            foreach (['starts_at', 'ends_at'] as $field) {
                $data[$field] = $this->utc(CarbonImmutable::parse($row->$field));
            }
            if ($actor->role === 'EMPLOYEE' && $actor->id !== $row->employee_id) {
                $data = Arr::except($data, ['employee_id', 'department_id', 'version']);
                $data['display_name'] = $row->display_name ?? DB::table('users')->where('id', $row->employee_id)->value('name');
            } else {
                $data['available_actions'] = $actor->role === 'SUPERVISOR' && $row->status === 'SCHEDULED'
                    && CarbonImmutable::parse($row->starts_at)->isFuture() ? ['edit', 'cancel'] : [];
            }
        }

        return $this->ids($data);
    }

    private function ids(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id'))) {
                $data[$key] = (string) $value;
            }
        }

        return $data;
    }

    private function actor(Request $request): object
    {
        $actor = $request->user();
        if (! $actor || ! $actor->active) {
            throw new ApiProblem(401, 'UNAUTHENTICATED');
        }

        return (object) $actor->getAttributes();
    }

    private function checkActor(?object $locked, object $actor, string $role): void
    {
        abort_unless($locked && $locked->active && $locked->role === $role && $locked->department_id === $actor->department_id, 403);
    }

    private function lockUsers(array $ids)
    {
        return DB::table('users')->whereIn('id', array_filter($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    private function settings(bool $lock = false): array
    {
        $query = DB::table('system_settings')->orderBy('setting_key');
        if ($lock) {
            $query->sharedLock();
        }

        return $query->pluck('setting_value', 'setting_key')->map(fn ($value) => json_decode($value, true))->all();
    }

    private function save(string $table, ?string $id, array $values): object
    {
        $values['updated_at'] = now();
        if ($id === null) {
            $id = DB::table($table)->insertGetId($values + ['created_at' => now()]);
        } else {
            DB::table($table)->where('id', $id)->update($values);
        }

        return DB::table($table)->find($id);
    }

    private function audit(int $actor, ?int $department, string $type, int $id, string $action, array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actor, 'department_id' => $department, 'entity_type' => $type, 'entity_id' => $id,
            'action' => $action, 'outcome' => 'SUCCESS',
            'metadata' => json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR),
        ]);
    }

    private function input(Request $request, array $rules): array
    {
        $body = $request->json()->all();
        if (isset($body['email']) && is_string($body['email'])) {
            $body['email'] = strtolower(trim($body['email']));
        }
        $unknown = array_diff_key($body, $rules);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_keys($unknown), 'Atribut tidak dikenal.'));
        }

        return Validator::make($body, $rules)->validate();
    }

    private function timestamp(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value)) {
                $fail('Gunakan waktu RFC 3339 dengan zona waktu.');

                return;
            }
            $parsed = date_parse($value);
            if ($parsed['warning_count'] || $parsed['error_count']) {
                $fail('Waktu tidak valid.');
            }
        };
    }

    private function passwordBytes(int $minimum): \Closure
    {
        return function ($attribute, $value, $fail) use ($minimum) {
            if (! is_string($value) || strlen($value) < $minimum || strlen($value) > 72 || str_contains($value, "\0")) {
                $fail("Gunakan kata sandi sepanjang {$minimum}–72 bita.");
            }
        };
    }

    private function utc(CarbonImmutable $date): string
    {
        return $date->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
