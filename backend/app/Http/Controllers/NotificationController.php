<?php

namespace App\Http\Controllers;

use App\Http\ApiProblem;
use App\Services\ScheduleRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $input = $request->validate(['page' => 'integer|min:1', 'per_page' => 'integer|min:1|max:100']);
        $page = $this->inbox($request)->orderByDesc('id')->paginate($input['per_page'] ?? 25, ['*'], 'page', $input['page'] ?? 1);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($row) => [
                'id' => (string) $row->id,
                'event' => $row->event,
                'subject_id' => (string) $row->subject_id,
                'created_at' => ScheduleRules::utc(CarbonImmutable::parse($row->created_at)),
                'read_at' => $row->read_at === null ? null : ScheduleRules::utc(CarbonImmutable::parse($row->read_at)),
            ]),
            'meta' => ['total' => $page->total()],
        ]);
    }

    public function read(Request $request, string $id)
    {
        $notification = $this->inbox($request)->where('id', $id);
        abort_unless((clone $notification)->exists(), 404);
        $notification->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }

    private function inbox(Request $request): Builder
    {
        $user = $request->user();
        if (! $user?->active) {
            throw new ApiProblem(401, 'UNAUTHENTICATED');
        }

        return DB::table('work_notifications')->where('user_id', $user->id)->where('department_id', $user->department_id);
    }
}
