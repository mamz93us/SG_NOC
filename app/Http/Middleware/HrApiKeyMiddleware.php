<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HrApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->header('X-HR-Api-Key');
        $expected  = config('services.hr_api.key');

        if (empty($expected) || $provided !== $expected) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
