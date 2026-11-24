<?php

use App\Http\ApiProblem;
use App\Http\Middleware\ValidateApiJson;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->prependToGroup('api', ValidateApiJson::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            if ($e instanceof ValidationException) {
                return response()->json(['error' => ['code' => 'VALIDATION_FAILED',
                    'message' => 'Periksa data yang dikirim.', 'details' => ['fields' => $e->errors()]]], 422);
            }
            if ($e instanceof AuthenticationException) {
                return response()->json(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Masuk terlebih dahulu.']], 401);
            }
            if ($e instanceof QueryException) {
                $conflict = match ($e->errorInfo[0] ?? null) {
                    '23P01' => [409, 'ASSIGNMENT_OVERLAP'],
                    '40P01', '40001' => [503, 'TEMPORARILY_UNAVAILABLE'],
                    default => null,
                };
                if ($conflict) {
                    return response()->json(['error' => ['code' => $conflict[1], 'message' => 'Operasi gagal.']], $conflict[0]);
                }
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];
            $code = match ($status) {
                403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 419 => 'CSRF_TOKEN_MISMATCH',
                429 => 'RATE_LIMITED', 500 => 'INTERNAL_ERROR', default => 'REQUEST_FAILED',
            };
            if ($e instanceof ApiProblem) {
                $code = $e->problemCode;
            }

            return response()->json(['error' => ['code' => $code, 'message' => 'Permintaan tidak dapat diproses.']], $status, $headers);
        });
    })->create();
