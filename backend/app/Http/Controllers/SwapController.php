<?php

namespace App\Http\Controllers;

use App\Http\ApiProblem;
use App\Services\ScheduleRules;
use App\Services\ShiftSwaps;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SwapController extends Controller
{
    public function __construct(private readonly ShiftSwaps $swaps) {}

    public function index(Request $request)
    {
        $actor = $this->actor($request);
        $page = $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|min:1|max:100']);
        $results = $this->swaps->visible($actor)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($page['per_page'] ?? 25, ['*'], 'page', $page['page'] ?? 1);

        return response()->json([
            'data' => $results->getCollection()->map(fn ($row) => $this->project($row)),
            'meta' => ['page' => $results->currentPage(), 'per_page' => $results->perPage(), 'total' => $results->total()],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $swap = $this->swaps->visible($this->actor($request))->find($id);
        abort_unless($swap, 404);
        $events = DB::table('shift_swap_events')->where('swap_request_id', $id)->orderBy('created_at')->orderBy('id')->get();

        return response()->json(['data' => $this->project($swap) + [
            'events' => $events->map(fn ($event) => $this->project($event)),
        ]]);
    }

    public function store(Request $request)
    {
        $actor = $this->actor($request);
        abort_unless($actor->role === 'EMPLOYEE', 403);
        $rules = [
            'source_assignment_id' => 'required|integer|min:1',
            'target_assignment_id' => 'required|integer|min:1',
            'reason' => 'required|string|max:1000',
        ];
        $body = $request->json()->all();
        $extra = array_diff_key($body, $rules);
        if ($extra !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_keys($extra), 'Atribut tidak dikenal.'));
        }
        $data = Validator::make($body, $rules)->validate();
        $source = (int) $data['source_assignment_id'];
        $target = (int) $data['target_assignment_id'];
        if ($source === $target) {
            throw ValidationException::withMessages(['target_assignment_id' => 'Pilih penugasan berbeda.']);
        }
        $row = $this->swaps->create($actor, $source, $target, trim($data['reason']));

        return response()->json(['data' => $this->project($row)], 201)->header('Location', "/api/v1/swaps/{$row->id}");
    }

    public function decide(Request $request, string $id, string $action)
    {
        $actor = $this->actor($request);
        if ($request->json()->all() !== []) {
            throw ValidationException::withMessages(['body' => 'Tindakan ini tidak menerima atribut.']);
        }
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            abort(404);
        }
        $swap = $this->swaps->decide($actor, (int) $id, $action);

        return response()->json(['data' => $this->project($swap)]);
    }

    private function actor(Request $request): object
    {
        $user = $request->user();
        if (! $user?->active) {
            throw new ApiProblem(401, 'UNAUTHENTICATED');
        }

        return (object) $user->getAttributes();
    }

    private function project(object $row): array
    {
        $data = (array) $row;
        unset($data['source_version_at_request'], $data['target_version_at_request']);
        if (in_array($data['status'] ?? null, ShiftSwaps::PENDING, true)
            && CarbonImmutable::parse($data['expires_at']) <= CarbonImmutable::now()) {
            $data['status'] = 'EXPIRED';
            $data['terminal_reason'] = 'SWAP_EXPIRED';
        }
        foreach ($data as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id'))) {
                $data[$key] = (string) $value;
            }
            if ($value !== null && str_ends_with($key, '_at')) {
                $data[$key] = ScheduleRules::utc(CarbonImmutable::parse($value));
            }
        }

        return $data;
    }
}
