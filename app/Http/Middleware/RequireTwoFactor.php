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
            // Not authenticated — nothing to gate on
            if (! $user) {
                return $next($request);
            }

            if ($this->isExcludedRoute($request)) {
                return $next($request);
            }

            // The business-card subdomain is SSO-only, by design, for everyone —
            // admins and super admins included. It serves nothing but a person's
            // own card (EnforceVcardHostIsolation 404s everything else), so there
            // is no privileged surface behind this gate.
            //
            // Scoped to the HOST, not to the session: the session is never marked
            // `2fa_verified`, so the same user hitting any NOC route still gets
            // challenged normally. Do not "optimise" this into a session flag.
            if (\App\Support\VCard::enabled() && \App\Support\VCard::isHost($request)) {
                return $next($request);
            }

            // Browser-only users bypass 2FA entirely — low-privilege role,
            // kept frictionless for SSO-first remote-browser access.
            if (method_exists($user, 'isBrowserUser') && $user->isBrowserUser()) {
                return $next($request);
            }

            if ($user->hasTwoFactorEnabled()) {
                // Enrolled but this session hasn't passed the challenge yet
                if (! $request->session()->get('2fa_verified')) {
                    return redirect()->route('two-factor.challenge');
                }
            } else {
                // 2FA is mandatory — force enrollment before anything else
                return redirect()->route('admin.two-factor.setup');
            }
        } catch (\Throwable $e) {
            // 2FA columns not yet migrated — skip silently
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
