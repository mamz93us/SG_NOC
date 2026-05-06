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

        // super_admin has implicit access to every permission. This matches
        // the role's intent (name = "can do everything") and protects against
        // accidental lockouts when a permissions-matrix save wipes a row that
        // wasn't ticked in the UI.
        if ($user->role === 'super_admin') {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if (RolePermission::roleHas($user->role, $permission)) {
                return $next($request);
            }
        }

        // Audit the denial for privilege-escalation forensics.
        try {
            \App\Models\ActivityLog::create([
                'model_type' => \App\Models\User::class,
                'model_id'   => $user->id,
                'action'     => 'permission_denied',
                'changes'    => [
                    'required' => $permissions,
                    'role'     => $user->role,
                    'path'     => $request->path(),
                    'method'   => $request->method(),
                ],
                'user_id'    => $user->id,
            ]);
        } catch (\Throwable) {
            // Never let logging failures bubble up to a 500.
        }

        if ($request->expectsJson()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // Portal-only roles (browser_user, hr) should never be sent to admin.dashboard —
        // /admin/ bounces them back to /portal, causing an infinite redirect. Instead,
        // send them to the portal hub (which is unguarded) with the error message.
        if (method_exists($user, 'usesPortal') && $user->usesPortal()) {
            return redirect()->route('portal.index')
                ->with('error', 'You do not have permission to access that page.');
        }

        return redirect()->route('admin.dashboard')
            ->with('error', 'You do not have permission to access that page.');
    }
}
