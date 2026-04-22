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
     * If the redirect is initiated from the Remote Browser portal login page
     * (?portal=1), remember that in the session so the callback can (a) default
     * brand-new users to the 'browser_user' role and (b) route them to the
     * portal — not the admin dashboard — after authentication.
     */
    public function redirect(\Illuminate\Http\Request $request)
    {
        $this->configureSocialite();

        if ($request->boolean('portal')) {
            $request->session()->put('sso_context', 'portal');
        } else {
            $request->session()->forget('sso_context');
        }

        return Socialite::driver('microsoft')->redirect();
    }

    /**
     * Handle the Microsoft OAuth callback.
     */
    public function callback(\Illuminate\Http\Request $request)
    {
        $this->configureSocialite();

        try {
            $msUser = Socialite::driver('microsoft')->user();
        } catch (\Exception $e) {
            $context = $request->session()->pull('sso_context');
            $loginRoute = $context === 'portal' ? 'portal.login' : 'login';
            return redirect()->route($loginRoute)
                ->with('error', 'Microsoft login failed: ' . $e->getMessage());
        }

        $context = $request->session()->pull('sso_context'); // 'portal' or null

        // Portal-originated sign-ins default brand-new users to browser_user
        // (no admin access); regular SSO falls back to sso_default_role.
        $defaultRole = $context === 'portal'
            ? 'browser_user'
            : (Setting::get()->sso_default_role ?? 'viewer');

        // Find or create the user
        $user = User::firstOrCreate(
            ['email' => $msUser->getEmail()],
            [
                'name'              => $msUser->getName() ?? $msUser->getEmail(),
                'password'          => \Illuminate\Support\Str::random(32), // unusable random password
                'role'              => $defaultRole,
                'email_verified_at' => now(),
            ]
        );

        Auth::login($user, true);

        // Make sure this session is not yet considered 2FA-verified; the
        // RequireTwoFactor middleware will route the user to the challenge
        // (if enrolled) or the forced enrollment page (if not).
        session()->forget('2fa_verified');

        // Remember the intended post-auth destination so the 2FA flow
        // (challenge or enrollment) can route the user back to the portal
        // instead of the admin dashboard.
        if ($context === 'portal') {
            $request->session()->put('url.intended', route('portal.index'));
        }

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.challenge');
        }

        // Need to enroll in 2FA first. After enrollment, TwoFactorController@confirm
        // redirects to $user->homeRoute() — portal for browser_user, admin otherwise.
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
