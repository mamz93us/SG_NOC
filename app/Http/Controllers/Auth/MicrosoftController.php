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
     * SSO can be initiated from the Remote Browser Portal, the admin login
     * screen, or the employee home portal. The `from` query param ('admin',
     * 'portal' or 'home') is stashed in the session so the callback knows where
     * to land the user.
     *
     * `?silent=1` adds `prompt=none`, which asks Entra to complete the sign-in
     * only if it can do so without showing anything. On an Entra-joined Windows
     * machine the browser presents the Primary Refresh Token from the Windows
     * sign-in, so this returns a code with no UI at all — that is how the home
     * portal signs people in as their Windows account without a click. When
     * Entra cannot, it redirects back with `?error=login_required` instead of
     * prompting, which the callback below turns into a normal signed-out page.
     */
    public function redirect(\Illuminate\Http\Request $request)
    {
        $this->configureSocialite();

        $from = match ($request->query('from')) {
            'admin' => 'admin',
            'home' => 'home',
            default => 'portal',
        };
        $request->session()->put('sso_from', $from);

        $silent = $request->boolean('silent');
        $request->session()->put('sso_silent', $silent);

        $driver = Socialite::driver('microsoft');

        if ($silent) {
            $driver = $driver->with(['prompt' => 'none']);
        }

        return $driver->redirect();
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
        $from = $request->session()->pull('sso_from', 'portal');
        $wasSilent = (bool) $request->session()->pull('sso_silent', false);

        // Entra reports a declined `prompt=none` as ?error=login_required with
        // NO code, so Socialite would throw a confusing state error if we let it
        // run. Check for the error first.
        //
        // This is the expected outcome on a personal device, a private window,
        // or any browser without a Primary Refresh Token — not a fault. Drop the
        // loop-guard cookie and hand back a normal signed-out page. Without that
        // cookie the home portal would bounce against login.microsoftonline.com
        // on every browser launch, forever.
        //
        // Deliberately BEFORE configureSocialite(): handling an error needs no
        // Socialite config, and configureSocialite() aborts to the ADMIN login
        // when SSO settings are incomplete — which would land employees on the
        // NOC sign-in page from the home portal.
        if ($request->has('error')) {
            return $this->handleAuthorizeError($request, $from, $wasSilent);
        }

        $this->configureSocialite();

        try {
            $msUser = Socialite::driver('microsoft')->user();
        } catch (\Exception $e) {
            $errorRoute = match (true) {
                $from === 'admin' => 'login',
                $request->getHost() === \App\Support\Marketing::domain() => 'portal.marketing.login',
                \App\Support\VCard::isHost($request) => 'vcard.login',
                \App\Support\HrPortal::isHost($request) => 'portal.hr.login',
                \App\Support\HomePortal::isHost($request) => 'home.login',
                default => 'portal.login',
            };

            return redirect()->route($errorRoute)
                ->with('error', 'Microsoft login failed: '.$e->getMessage());
        }

        // Always default new SSO users to browser_user (no admin access).
        $user = User::firstOrCreate(
            ['email' => $msUser->getEmail()],
            [
                'name' => $msUser->getName() ?? $msUser->getEmail(),
                'password' => \Illuminate\Support\Str::random(32), // unusable random password
                'role' => 'browser_user',
                'email_verified_at' => now(),
            ]
        );

        Auth::login($user, true);

        // Record the sign-in for the Access Analytics dashboard. Best-effort —
        // never let access logging interrupt the login flow.
        try {
            $loginApp = match (true) {
                $request->getHost() === \App\Support\Marketing::domain() => 'em',
                \App\Support\VCard::isHost($request) => 'vcard',
                \App\Support\HrPortal::isHost($request) => 'hr',
                \App\Support\HomePortal::isHost($request) => 'home',
                $user->usesPortal() => 'portal',
                default => 'noc',
            };
            app(\App\Services\Access\AccessVisitRecorder::class)->record([
                'user_id' => $user->getKey(),
                'user_name' => $user->name,
                'user_email' => $user->email,
                'app' => $loginApp,
                'event' => 'login',
                'path' => '/'.ltrim($request->path(), '/'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[MicrosoftController] access login log failed: '.$e->getMessage());
        }

        // Fresh auth session — 2FA gate applies.
        session()->forget('2fa_verified');

        // Portal-only users always land in the portal, regardless of where
        // SSO was initiated. Admin-initiated SSO for an admin-capable user
        // lands in the admin dashboard.
        // On the marketing subdomain everyone lands on the marketing portal — it
        // is the only thing served there. Marketing-role users land there too,
        // even when they sign in from NOC.
        $onMarketingHost = $request->getHost() === \App\Support\Marketing::domain();
        $onVcardHost = \App\Support\VCard::isHost($request);
        $onHrHost = \App\Support\HrPortal::isHost($request);
        $onHomeHost = \App\Support\HomePortal::isHost($request);

        $landing = match (true) {
            $onVcardHost => route('vcard.mine'),
            $onHrHost => route('portal.hr.index'),
            $onHomeHost => route('home.index'),
            $onMarketingHost, $user->isMarketing() => route('portal.marketing.dashboard'),
            $from === 'admin' && ! $user->usesPortal() => route($user->homeRoute()),
            default => route('portal.index'),
        };

        $request->session()->put('url.intended', $landing);

        // Card host: SSO is the whole gate. Return before the 2FA branches below —
        // deliberately WITHOUT setting `2fa_verified`, so this session still gets
        // challenged if it is ever used against NOC. See RequireTwoFactor.
        //
        // The HR host works the same way: SSO only, no 2FA, and the session is
        // never marked verified — so the same session hitting NOC is challenged.
        // The home portal is in this set for the same reason, plus one of its
        // own: it is the page every PC opens unattended, so a 2FA challenge
        // there would defeat the point of signing in silently.
        if ($onVcardHost || $onHrHost || $onHomeHost) {
            return redirect()->intended($landing);
        }

        // Browser-only users bypass the app's 2FA. Everyone else — including
        // marketing — goes through the mandatory 2FA flow below; its standalone
        // enrolment + challenge pages are explicitly allowed on the marketing host.
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

    /**
     * Entra came back with ?error= instead of ?code=.
     *
     * The interesting case is a declined `prompt=none`: the browser had no
     * usable session (personal device, private window, no Primary Refresh
     * Token), so Entra refused to sign the person in rather than prompting.
     * That is an ordinary outcome for the home portal, not a failure worth
     * showing anyone — set the loop-guard cookie so we stop asking for a while,
     * and let the page render signed-out with a Sign in button.
     *
     * Anything else (a real consent or configuration problem, or an error on an
     * interactive attempt) surfaces on the right login page as usual.
     */
    private function handleAuthorizeError(
        \Illuminate\Http\Request $request,
        string $from,
        bool $wasSilent,
    ) {
        $error = (string) $request->query('error');
        $onHomeHost = \App\Support\HomePortal::isHost($request);

        // The three ways Entra says "I could have signed them in, but not
        // without showing them something".
        $needsInteraction = in_array(
            $error,
            ['login_required', 'interaction_required', 'consent_required'],
            true
        );

        if ($wasSilent && $needsInteraction && $onHomeHost) {
            return redirect()->route('home.index')->withCookie(cookie(
                \App\Support\HomePortal::SILENT_OFF_COOKIE,
                '1',
                \App\Support\HomePortal::silentRetryMinutes(),
            ));
        }

        // A silent attempt that failed for some OTHER reason is worth knowing
        // about — it usually means the app registration is wrong, and it would
        // otherwise be invisible because nobody sees a silent redirect.
        if ($wasSilent) {
            \Illuminate\Support\Facades\Log::warning('[MicrosoftController] silent SSO failed', [
                'error' => $error,
                'description' => (string) $request->query('error_description'),
                'host' => $request->getHost(),
            ]);
        }

        $errorRoute = match (true) {
            $from === 'admin' => 'login',
            $request->getHost() === \App\Support\Marketing::domain() => 'portal.marketing.login',
            \App\Support\VCard::isHost($request) => 'vcard.login',
            \App\Support\HrPortal::isHost($request) => 'portal.hr.login',
            $onHomeHost => 'home.login',
            default => 'portal.login',
        };

        $description = (string) $request->query('error_description');

        return redirect()->route($errorRoute)->with(
            'error',
            'Microsoft login failed: '.($description !== '' ? $description : $error)
        );
    }

    private function configureSocialite(): void
    {
        $settings = Setting::get();

        // Guard: if any required field is missing, fail early with a clear message
        // instead of letting Azure return a cryptic 401.
        $clientId = $settings->sso_client_id;
        $clientSecret = $settings->sso_client_secret;  // decrypted by accessor
        $tenantId = $settings->sso_tenant_id;

        if (! $clientId || ! $clientSecret || ! $tenantId) {
            abort(redirect()->route('login')->with(
                'error',
                'SSO is not fully configured. '.
                'Please check Settings → SSO (Tenant ID, Client ID, and Client Secret must all be set). '.
                ($clientSecret === null && $settings->getRawOriginal('sso_client_secret')
                    ? 'The stored client secret could not be decrypted — please re-enter it.'
                    : '')
            ));
        }

        Config::set('services.microsoft', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect' => url('/auth/microsoft/callback'),
            'tenant' => $tenantId,
        ]);
    }
}
