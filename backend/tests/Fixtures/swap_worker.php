<?php

use App\Http\ApiProblem;
use App\Models\User;
use App\Services\ShiftSwaps;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $mode, $actorId, $first, $second] = $argv;
$actor = User::query()->findOrFail($actorId);
echo "READY\n";
flush();
DB::select('SELECT pg_advisory_lock_shared(543219)');
try {
    $swaps = $app->make(ShiftSwaps::class);
    if ($mode === 'patch') {
        Auth::guard('web')->setUser($actor);
        $body = json_encode(['version' => 1, 'starts_at' => $second, 'ends_at' => CarbonImmutable::parse($second)->addHours(8)->toIso8601String()]);
        $request = Request::create("/api/v1/assignments/{$first}", 'PATCH', [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        ], $body);
        $response = $app->make(HttpKernel::class)->handle($request);
        $payload = json_decode($response->getContent(), true);
        echo json_encode(['status' => $response->getStatusCode(), 'code' => $payload['error']['code'] ?? null])."\n";
    } else {
        $row = $mode === 'create' ? $swaps->create((object) $actor->getAttributes(), (int) $first, (int) $second, 'Pergantian jadwal')
            : $swaps->decide((object) $actor->getAttributes(), (int) $first, 'approve');
        echo json_encode(['status' => $mode === 'create' ? 201 : 200, 'state' => $row->status])."\n";
    }
} catch (ApiProblem $e) {
    echo json_encode(['status' => $e->getStatusCode(), 'code' => $e->problemCode])."\n";
} finally {
    DB::select('SELECT pg_advisory_unlock_shared(543219)');
}
