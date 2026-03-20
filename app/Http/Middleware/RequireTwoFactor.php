<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    /**
     * Routes that should be excluded from the 2FA check to avoid redirect loops.
     */
    protected array $except = [
        'two-factor.challenge',
        'two-factor.verify',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only enforce if the user is authenticated, has 2FA enabled, and hasn't verified yet
        if (
            $user
            && $user->hasTwoFactorEnabled()
            && ! $request->session()->get('2fa_verified')
            && ! $this->isExcludedRoute($request)
        ) {
            // Store the user ID in session so the challenge controller can find them,
            // then log them out so they must complete 2FA first.
            $request->session()->put('2fa_user_id', $user->id);
            \Illuminate\Support\Facades\Auth::logout();

            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }

    protected function isExcludedRoute(Request $request): bool
    {
        $routeName = $request->route()?->getName();

        if (! $routeName) {
            return false;
        }

        foreach ($this->except as $name) {
            if ($routeName === $name) {
                return true;
            }
        }

        return false;
    }
}
