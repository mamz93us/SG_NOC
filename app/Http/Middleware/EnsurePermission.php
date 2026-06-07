<?php

namespace App\Http\Middleware;

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

        if (! $user) {
            return redirect()->route('login');
        }

        // super_admin has implicit access to every permission. This matches
        // the role's intent (name = "can do everything") and protects against
        // accidental lockouts when a permissions-matrix save wipes a row that
        // wasn't ticked in the UI. User::hasPermission() also short-circuits
        // here, but checking the role inline avoids the override-cache lookup.
        if ($user->role === 'super_admin') {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        // Audit the denial for privilege-escalation forensics.
        try {
            \App\Models\ActivityLog::create([
                'model_type' => \App\Models\User::class,
                'model_id' => $user->id,
                'action' => 'permission_denied',
                'changes' => [
                    'required' => $permissions,
                    'role' => $user->role,
                    'overrides_present' => $user->permissions()->exists(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                ],
                'user_id' => $user->id,
            ]);
        } catch (\Throwable) {
            // Never let logging failures bubble up to a 500.
        }

        if ($request->expectsJson()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // On the isolated marketing host an authenticated-but-unauthorized user must
        // NOT be bounced to the NOC /portal — that leaks the NOC's existence and breaks
        // the host isolation EnforceMarketingHostIsolation enforces. A brand-new SSO
        // user is created as browser_user (no marketing access) until an admin grants
        // the `marketing` role, so this is the common landing for first-time sign-ins.
        // Keep them on em with a self-explanatory "no access yet" page (no permission
        // gate on that route, so there's no redirect loop back through here).
        if ($request->getHost() === \App\Support\Marketing::domain()) {
            return redirect()->route('portal.marketing.no-access')
                ->with('error', 'Your account does not have access to the marketing portal yet. Please contact your administrator.');
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
