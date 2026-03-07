<?php

namespace App\Http\Middleware;

use App\Models\RolePermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Handle an incoming request.
     * Usage: Route::middleware('permission:manage-settings')
     *        Route::middleware('permission:manage-users')
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        foreach ($permissions as $permission) {
            if (RolePermission::roleHas($user->role, $permission)) {
                return $next($request);
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return redirect()->route('admin.dashboard')
            ->with('error', 'You do not have permission to access that page.');
    }
}
