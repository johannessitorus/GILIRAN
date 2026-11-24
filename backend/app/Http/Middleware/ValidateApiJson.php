<?php

namespace App\Http\Middleware;

use App\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;

final class ValidateApiJson
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->getContent() !== '' && ! $request->isMethodSafe()) {
            if (! $request->isJson()) {
                throw new ApiProblem(415, 'UNSUPPORTED_MEDIA_TYPE');
            }
            if (! json_validate($request->getContent())) {
                throw new ApiProblem(400, 'MALFORMED_JSON');
            }
            if (! is_object(json_decode($request->getContent()))) {
                throw new ApiProblem(422, 'VALIDATION_FAILED');
            }
        }

        return $next($request);
    }
}
