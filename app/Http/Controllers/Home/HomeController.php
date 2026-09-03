<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\Knowbe4Score;
use App\Models\Setting;
use App\Services\EmployeeCard\SamsungWalletService;
use App\Services\EmployeeCard\WalletPassService;
use App\Services\Home\CoreSystems;
use App\Services\Home\Greeter;
use App\Services\Home\PaydayCalculator;
use App\Services\Ticketing\TicketRequestService;
use App\Support\HomePortal;
use App\Support\HrPortal;
use App\Support\VCard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The employee home portal — the page every company PC opens on.
 *
 * Sign-in is silent: a guest is sent once through Microsoft with `prompt=none`,
 * which an Entra-joined Windows machine satisfies from its Primary Refresh
 * Token without showing anything. See the class docblock on
 * Auth\MicrosoftController.
 *
 * Two constraints shape everything below:
 *
 *  1. The whole company loads this within minutes of 9am, against a database
 *     that is also the cache, session and queue store. Shared panels are cached;
 *     per-user queries are single indexed lookups. Nothing here calls an
 *     external API — Graph and KnowBe4 are scheduled syncs reading local tables.
 *  2. Every panel must survive its data being absent. A missing Employee row, an
 *     unconfigured integration or an empty table hides its card; it never blanks
 *     the page or throws.
 */
class HomeController extends Controller
{
    public function __construct(
        private Greeter $greeter,
        private PaydayCalculator $payday,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return $this->handleGuest($request);
        }

        return view('home.index', $this->pageData($request));
    }

    /**
     * The SSO-only sign-in page, for browsers that cannot sign in silently.
     */
    public function login(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('home.index');
        }

        return view('home.login');
    }

    /**
     * Guests get exactly one silent attempt, then the signed-out page.
     *
     * The cookie is the loop guard. Without it, any browser that can never
     * satisfy `prompt=none` — a personal device, a private window, a machine
     * that is not Entra-joined — bounces against login.microsoftonline.com on
     * every single browser launch, forever. Do not remove it.
     */
    private function handleGuest(Request $request): RedirectResponse|View
    {
        if ($request->cookie(HomePortal::SILENT_OFF_COOKIE)) {
            return view('home.login', ['silentDeclined' => true]);
        }

        return redirect()->route('auth.microsoft', ['from' => 'home', 'silent' => 1]);
    }

    /**
     * Lets someone force the interactive flow from the signed-out page — it
     * clears the guard cookie so the next visit can go back to being silent.
     */
    public function signIn(Request $request): RedirectResponse
    {
        return redirect()
            ->route('auth.microsoft', ['from' => 'home'])
            ->withoutCookie(HomePortal::SILENT_OFF_COOKIE);
    }

    /**
     * The signed-in employee's own Apple Wallet pass.
     *
     * A home-scoped route rather than the existing `employee.card.wallet`:
     * EnforceHomePortalHostIsolation only admits the `home.*` namespace, and
     * widening that to a route which serves ANY token's pass would be a poor
     * trade on the one host that sits open on unattended desks. This one can
     * only ever return the pass belonging to whoever is signed in.
     */
    public function walletPass(Request $request, WalletPassService $wallet)
    {
        $employee = Employee::with(['identityUser', 'branch'])
            ->where('email', $request->user()->email)
            ->where('status', 'active')
            ->first();

        abort_unless($employee && $employee->card_token, 404, 'No employee card is available for your account.');

        return $this->streamPass($employee, $wallet);
    }

    /**
     * The same pass, fetched by a phone that has no session here.
     *
     * A Wallet pass is useless on the Windows PC the portal is open on — it has
     * to reach the handset. The page therefore shows a QR, and the phone that
     * scans it arrives as an anonymous client.
     *
     * A short-lived SIGNED url rather than relaxing the auth gate: the signature
     * is minted only while the owner is signed in on the desktop, is
     * tamper-proof, names one employee, and expires in minutes. The existing
     * `/card/{token}/wallet` route keeps its `auth` middleware untouched — that
     * gate was added deliberately (see commit 1b8e13d).
     */
    public function signedWalletPass(Employee $employee, WalletPassService $wallet)
    {
        abort_unless($employee->status === 'active' && $employee->card_token, 404);

        $employee->loadMissing(['identityUser', 'branch']);

        return $this->streamPass($employee, $wallet);
    }

    /**
     * The same idea for Samsung, reached by the phone that scanned the QR.
     *
     * There is nothing to stream: Samsung's card is a signed link, so this
     * redirects to it. The link is minted here rather than encoded in the QR
     * directly because the `cdata` token runs to well over a thousand
     * characters — a QR carrying it would be dense enough to fail scanning on
     * an ordinary phone camera across a desk.
     */
    public function signedSamsungWallet(Employee $employee, SamsungWalletService $samsung): RedirectResponse
    {
        abort_unless($employee->status === 'active' && $employee->card_token, 404);

        if (! $samsung->isConfigured()) {
            abort(503, 'Samsung Wallet is not configured on this server.');
        }

        $employee->loadMissing(['identityUser', 'branch', 'department']);

        return redirect()->away($samsung->addToWalletUrl($employee));
    }

    private function streamPass(Employee $employee, WalletPassService $wallet)
    {
        if (! $wallet->isConfigured()) {
            abort(503, 'Apple Wallet is not configured on this server.');
        }

        $path = $wallet->generate($employee);
        $filename = Str::slug($employee->name).'.pkpass';

        $response = response()->file($path, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);

        // The pass and its extracted PEMs live in a temp dir — clear it once the
        // response is on the wire, or every download leaks key material to disk.
        $tmpDir = dirname($path);
        register_shutdown_function(function () use ($tmpDir) {
            if (is_dir($tmpDir)) {
                array_map('unlink', glob("$tmpDir/*") ?: []);
                @rmdir($tmpDir);
            }
        });

        return $response;
    }

    private function pageData(Request $request): array
    {
        $user = $request->user();
        $settings = Setting::get();

        // The ID card, and the audience filter for announcements. Absent for
        // anyone identity sync has not matched to an HR record — every consumer
        // below has to tolerate null.
        $employee = Employee::with(['branch', 'department'])
            ->where('email', $user->email)
            ->first();

        $announcements = $this->announcements($employee);
        $walletAvailable = app(WalletPassService::class)->isConfigured();
        $samsungAvailable = app(SamsungWalletService::class)->isConfigured();

        return [
            'user' => $user,
            'employee' => $employee,
            'greeting' => $this->greeter->for($employee?->name ?: $user->name),
            'announcements' => $announcements,
            'unreadCount' => $this->unreadCount($user->id, $announcements),
            'events' => $this->events($settings),
            'security' => $this->securityScore($settings, $employee, $user->email),
            'payday' => $this->payday->next(),
            // Settings first, then the config/env default, then the HR portal.
            'payrollUrl' => $settings->home_portal_payroll_url
                ?: (config('home_portal.payday.url') ?: HrPortal::url()),
            'cardToken' => $employee?->card_token,
            // The QR points at the business-card subdomain, which is the
            // canonical public URL for a card (VCard::cardUrl falls back to the
            // current host when that subdomain is switched off).
            'cardUrl' => $employee?->card_token ? VCard::cardUrl($employee->card_token) : null,
            'walletAvailable' => $walletAvailable,
            // Scanned from the phone, so it must survive having no session
            // here. Short-lived on purpose: it is minted fresh on every page
            // load, and this page sits open all day on an unattended desk.
            'coreSystems' => app(CoreSystems::class)->visible($settings),
            'assetCount' => HomeAssetsController::activeCountFor($employee),
            // Drives the two IT cards. Cached per audience and never throws —
            // an empty library shows the cards with their static wording
            // rather than hiding the shelf people are told to go and read.
            'docCounts' => HomeDocumentController::tileCounts($employee),
            // Cached for 5 minutes and never throws — this page is opened
            // unattended on every company PC, so the ticketing system
            // being slow must not be felt here.
            'openTicketCount' => app(TicketRequestService::class)->liveCountFor((string) $user->email),
            'trainingUrl' => $settings->knowbe4_training_url ?: null,
            // Outlook on the web. Blank in config hides the card rather than
            // shipping a tile that goes nowhere.
            'webmailUrl' => trim((string) config('home_portal.webmail_url')) ?: null,
            'walletQrUrl' => ($walletAvailable && $employee)
                ? URL::temporarySignedRoute(
                    'home.card.pass',
                    now()->addMinutes(15),
                    ['employee' => $employee->id],
                )
                : null,
            // Samsung's link is minted on the phone side too, for the same
            // reason: adding a card to a wallet only means anything on the
            // handset, and this page is open on a Windows PC.
            'samsungAvailable' => $samsungAvailable,
            'samsungQrUrl' => ($samsungAvailable && $employee)
                ? URL::temporarySignedRoute(
                    'home.card.samsung',
                    now()->addMinutes(15),
                    ['employee' => $employee->id],
                )
                : null,
        ];
    }

    /**
     * Live announcements for this person.
     *
     * Cached per audience rather than per user — the audience triple is what the
     * query actually varies on, so a thousand employees in one branch share one
     * cache entry instead of generating a thousand.
     */
    private function announcements(?Employee $employee)
    {
        $key = sprintf(
            'home.announcements.%s.%s',
            $employee?->branch_id ?? 'nb',
            $employee?->department_id ?? 'nd'
        );

        try {
            return Cache::remember(
                $key,
                now()->addMinutes(5),
                fn () => Announcement::liveFor($employee)->limit(12)->get()
            );
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * How many of the announcements on screen this person has not opened.
     *
     * Scoped to the ones actually shown, so the badge can never claim more
     * unread items than exist on the page.
     */
    private function unreadCount(int $userId, $announcements): int
    {
        $ids = $announcements->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        try {
            $read = AnnouncementRead::where('user_id', $userId)
                ->whereIn('announcement_id', $ids)
                ->count();

            return max(0, $ids->count() - $read);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Next few company calendar entries, or an empty collection when off. */
    private function events(Setting $settings)
    {
        if (! $settings->company_calendar_enabled) {
            return collect();
        }

        try {
            return Cache::remember(
                'home.company_events',
                now()->addMinutes(10),
                fn () => CompanyEvent::upcoming()->limit(5)->get()
            );
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * This person's own KnowBe4 row, and only ever their own.
     *
     * Returns null when the integration is off or has never synced, which hides
     * the card — showing dashes would imply a perfect score.
     */
    private function securityScore(Setting $settings, ?Employee $employee, string $email): ?Knowbe4Score
    {
        if (! $settings->knowbe4_enabled) {
            return null;
        }

        try {
            // Grouped deliberately: the OR must stay inside its own bracket so
            // that adding any further condition later cannot widen this into
            // "anyone's row where the email matches".
            return Knowbe4Score::query()
                ->where(function ($q) use ($employee, $email) {
                    $q->where('email', $email);

                    if ($employee) {
                        $q->orWhere('employee_id', $employee->id);
                    }
                })
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
