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
     */
    public function redirect()
    {
        $this->configureSocialite();

        return Socialite::driver('microsoft')->redirect();
    }

    /**
     * Handle the Microsoft OAuth callback.
     */
    public function callback()
    {
        $this->configureSocialite();

        try {
            $msUser = Socialite::driver('microsoft')->user();
        } catch (\Exception $e) {
            return redirect()->route('login')
                ->with('error', 'Microsoft login failed: ' . $e->getMessage());
        }

        // Find or create the user
        $user = User::firstOrCreate(
            ['email' => $msUser->getEmail()],
            [
                'name'              => $msUser->getName() ?? $msUser->getEmail(),
                'password'          => \Illuminate\Support\Str::random(32), // unusable random password
                'role'              => Setting::get()->sso_default_role ?? 'viewer',
                'email_verified_at' => now(),
            ]
        );

        Auth::login($user, true);

        // Make sure this session is not yet considered 2FA-verified; the
        // RequireTwoFactor middleware will route the user to the challenge
        // (if enrolled) or the forced enrollment page (if not).
        session()->forget('2fa_verified');

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.challenge');
        }

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
