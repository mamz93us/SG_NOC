<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InternalIpOnly
{
    public function handle(Request $request, Closure $next)
    {
        $allowed = array_merge(['127.0.0.1', '::1'], config('app.internal_ips', []));
        if (!in_array($request->ip(), $allowed)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        return $next($request);
    }
}
