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
     * SSO is exclusively for the Remote Browser Portal — admins use password
     * login. This method is always treated as a portal-initiated sign-in.
     */
    public function redirect(\Illuminate\Http\Request $request)
    {
        $this->configureSocialite();

        return Socialite::driver('microsoft')->redirect();
    }

    /**
     * Handle the Microsoft OAuth callback.
     *
     * Every SSO sign-in lands in the Remote Browser Portal. New users get the
     * `browser_user` role. Existing users keep whatever role they already have
     * (so we don't accidentally demote someone who was manually promoted), but
     * they are still routed to the portal — never the admin dashboard.
     */
    public function callback(\Illuminate\Http\Request $request)
    {
        $this->configureSocialite();

        try {
            $msUser = Socialite::driver('microsoft')->user();
        } catch (\Exception $e) {
            return redirect()->route('portal.login')
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

        // Every SSO sign-in is meant for the portal; remember that as the
        // intended destination so the 2FA flow (challenge or enrollment)
        // lands the user there, never the admin dashboard.
        $request->session()->put('url.intended', route('portal.index'));

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.challenge');
        }

        // First-time users enroll in 2FA, then the confirm handler picks up
        // url.intended and sends them to the portal.
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
