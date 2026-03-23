<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
class RequireTwoFactor
{
    protected array $except = [
        'two-factor.challenge',
        'two-factor.verify',
        'admin.two-factor.setup',
        'admin.two-factor.confirm',
        'admin.two-factor.disable',
        'logout',
    ];
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = $request->user();
            // Not authenticated yet (mid-challenge flow) — do not interfere
            if (! $user) {
                return $next($request);
            }
            if (
                $user->hasTwoFactorEnabled()
                && ! $request->session()->get('2fa_verified')
                && ! $this->isExcludedRoute($request)
            ) {
                $request->session()->put('2fa_user_id', $user->id);
                \Illuminate\Support\Facades\Auth::logout();
                return redirect()->route('two-factor.challenge');
            }
        } catch (\Throwable $e) {
            // 2FA columns not yet migrated — skip silently
        }
        return $next($request);
    }
    protected function isExcludedRoute(Request $request): bool
    {
        $routeName = $request->route()?->getName();
        if (! $routeName) return false;
        foreach ($this->except as $name) {
            if ($routeName === $name) return true;
        }
        return false;
    }
}
