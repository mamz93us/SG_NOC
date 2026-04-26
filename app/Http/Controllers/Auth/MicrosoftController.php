<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Laravel\Socialite\Facades\Socialite;

class MicrosoftController extends Controller
{
    /**
     * Configure Socialite dynamically from DB settings and redirect to Microsoft.
     *
     * SSO can be initiated from either the Remote Browser Portal or the admin
     * login screen. The `from` query param ('admin' or 'portal') is stashed in
     * the session so the callback knows where to land the user.
     */
    public function redirect(\Illuminate\Http\Request $request)
    {
        $this->configureSocialite();

        $from = $request->query('from') === 'admin' ? 'admin' : 'portal';
        $request->session()->put('sso_from', $from);

        return Socialite::driver('microsoft')->redirect();
    }

    /**
     * Handle the Microsoft OAuth callback.
     *
     * Routing depends on where SSO was initiated and what role the user has:
     *   - Portal-initiated, or any portal-only role (browser_user, hr) → portal
     *   - Admin-initiated AND user has admin/staff role → admin dashboard
     * New users always get `browser_user` (never auto-promoted to admin).
     */
    public function callback(\Illuminate\Http\Request $request)
    {
        $this->configureSocialite();

        $from = $request->session()->pull('sso_from', 'portal');

        try {
            $msUser = Socialite::driver('microsoft')->user();
        } catch (\Exception $e) {
            $errorRoute = $from === 'admin' ? 'login' : 'portal.login';
            return redirect()->route($errorRoute)
                ->with('error', 'Microsoft login failed: ' . $e->getMessage());
        }

        // Always default new SSO users to browser_user (no admin access).
        $user = User::firstOrCreate(
            ['email' => $msUser->getEmail()],
            [
                'name'              => $msUser->getName() ?? $msUser->getEmail(),
                'password'          => \Illuminate\Support\Str::random(32), // unusable random password
                'role'              => 'browser_user',
                'email_verified_at' => now(),
            ]
        );

        Auth::login($user, true);

        // Fresh auth session — 2FA gate applies.
        session()->forget('2fa_verified');

        // Portal-only users always land in the portal, regardless of where
        // SSO was initiated. Admin-initiated SSO for an admin-capable user
        // lands in the admin dashboard.
        $landing = ($from === 'admin' && !$user->usesPortal())
            ? route($user->homeRoute())
            : route('portal.index');

        $request->session()->put('url.intended', $landing);

        // Browser-only users skip 2FA — straight to the portal.
        if ($user->isBrowserUser()) {
            $request->session()->put('2fa_verified', true);
            return redirect()->intended(route('portal.index'));
        }

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.challenge');
        }

        // First-time users enroll in 2FA, then the confirm handler picks up
        // url.intended and sends them to their landing page.
        return redirect()->route('admin.two-factor.setup');
    }

    private function configureSocialite(): void
    {
        $settings = Setting::get();

        // Guard: if any required field is missing, fail early with a clear message
        // instead of letting Azure return a cryptic 401.
        $clientId     = $settings->sso_client_id;
        $clientSecret = $settings->sso_client_secret;  // decrypted by accessor
        $tenantId     = $settings->sso_tenant_id;

        if (!$clientId || !$clientSecret || !$tenantId) {
            abort(redirect()->route('login')->with(
                'error',
                'SSO is not fully configured. ' .
                'Please check Settings → SSO (Tenant ID, Client ID, and Client Secret must all be set). ' .
                ($clientSecret === null && $settings->getRawOriginal('sso_client_secret')
                    ? 'The stored client secret could not be decrypted — please re-enter it.'
                    : '')
            ));
        }

        Config::set('services.microsoft', [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect'      => url('/auth/microsoft/callback'),
            'tenant'        => $tenantId,
        ]);
    }
}
