<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\Knowbe4Score;
use App\Models\Setting;
use App\Services\Home\Greeter;
use App\Services\Home\PaydayCalculator;
use App\Support\HomePortal;
use App\Support\HrPortal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        return [
            'user' => $user,
            'employee' => $employee,
            'greeting' => $this->greeter->for($employee?->name ?: $user->name),
            'announcements' => $announcements,
            'unreadCount' => $this->unreadCount($user->id, $announcements),
            'events' => $this->events($settings),
            'security' => $this->securityScore($settings, $employee, $user->email),
            'payday' => $this->payday->next(),
            'payrollUrl' => config('home_portal.payday.url') ?: HrPortal::url(),
            'cardToken' => $employee?->card_token,
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
