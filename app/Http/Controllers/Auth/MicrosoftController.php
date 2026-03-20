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

        // If user has 2FA enabled, redirect to challenge instead of logging in
        if ($user->hasTwoFactorEnabled()) {
            session()->put('2fa_user_id', $user->id);
            session()->put('2fa_remember', true);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, true);

        // Use route() directly — intended() can point back to /login if user
        // navigated there manually, causing an infinite redirect loop.
        return redirect()->route('admin.dashboard');
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
