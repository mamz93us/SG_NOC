<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Handle an incoming request.
     * Usage: Route::middleware('role:super_admin')
     *        Route::middleware('role:super_admin,admin')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user() || !in_array($request->user()->role, $roles)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            return redirect()->route('admin.dashboard')
                ->with('error', 'You do not have permission to access that page.');
        }

        return $next($request);
    }
}
