<?php

namespace App\Http\Controllers;

use App\Jobs\Ticketing\LogTicketVisitJob;
use App\Models\Setting;
use App\Services\Ticketing\TicketVisitRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * it.samirgroup.net. Two entry points:
 *  - landing()  : the branded landing page (web + mobile app links). Renders
 *                 only — it does NOT log, so bots/CT-scanners that load the page
 *                 aren't counted. The "Open Web App" button points at /go.
 *  - forward()  : /go — records one analytics event, then forwards to the
 *                 ticketing app. This is the tracked click-through.
 *
 * Hard rules for forward():
 *  - Logging must NEVER block or break the forward. Any failure is swallowed
 *    (logged to laravel.log) and the visitor is still sent on.
 *  - The destination comes only from config (env), never the request, so this
 *    cannot be abused as an open redirect.
 */
class TicketForwardController extends Controller
{
    public function __construct(private TicketVisitRecorder $recorder) {}

    /** Branded landing page. No logging here — only /go is tracked. */
    public function landing(Request $request)
    {
        // If the landing is disabled, fall straight through to the forward so
        // it.samirgroup.net/ still reaches the ticketing app.
        if (! config('ticket_tracking.landing_enabled', true)) {
            return $this->forward($request);
        }

        $config = config('ticket_tracking');

        // Resolve the logo to a usable src, or null (→ inline SVG fallback in the
        // view). Priority: explicit config override → the NOC company logo from
        // Settings → SVG fallback. The config override accepts a full URL or a
        // path under public/ that actually exists (so a missing file doesn't
        // render a broken image).
        $logo = $config['logo_url'] ?? null;
        $logoSrc = null;
        if ($logo) {
            if (Str::startsWith($logo, ['http://', 'https://'])) {
                $logoSrc = $logo;
            } elseif (is_file(public_path($logo))) {
                $logoSrc = asset($logo);
            }
        }
        if (! $logoSrc) {
            try {
                if ($companyLogo = Setting::get()->company_logo ?? null) {
                    $logoSrc = Storage::url($companyLogo);
                }
            } catch (\Throwable $e) {
                // Settings unavailable — fall back to the inline SVG wordmark.
            }
        }

        return view('ticket.landing', [
            'webAppUrl' => url('/go'),
            'apps' => $config['apps'] ?? [],
            'logoSrc' => $logoSrc,
        ]);
    }

    public function forward(Request $request)
    {
        $config = config('ticket_tracking');
        $destination = (string) $config['destination_url'];

        // Resolve / mint the visitor session id (so we can count uniques).
        $cookieName = $config['session_cookie'] ?? 'tv_sid';
        $sessionId = $request->cookie($cookieName);
        $newCookie = false;
        if (! $sessionId) {
            $sessionId = Str::random(40);
            $newCookie = true;
        }

        // ── Analytics (best-effort, never fatal) ───────────────────────────
        try {
            $raw = [
                'ip' => $request->ip(),           // honours X-Forwarded-For (trusted proxies)
                'user_agent' => $request->userAgent(),
                'referrer' => $request->headers->get('referer'),
                'session_id' => $sessionId,
                'visited_at' => now()->toIso8601String(),
            ];

            if (($config['async_logging'] ?? false)) {
                LogTicketVisitJob::dispatch($raw);
            } else {
                $this->recorder->record($raw);
            }
        } catch (\Throwable $e) {
            Log::error('[TicketForward] visit logging failed (forwarding anyway): '.$e->getMessage());
        }

        // ── Forward ────────────────────────────────────────────────────────
        $response = ($config['forward_mode'] ?? 'redirect') === 'proxy'
            ? $this->proxy($request, $destination)
            : redirect()->away($destination, 302);

        if ($newCookie) {
            $minutes = ((int) ($config['session_cookie_days'] ?? 365)) * 24 * 60;
            $response->withCookie(cookie($cookieName, $sessionId, $minutes));
        }

        return $response;
    }

    /**
     * EXPERIMENTAL best-effort reverse proxy so it.samirgroup.net stays in the
     * address bar.
     *
     * Caveats (Oracle ADF/JSF is stateful):
     *  - JSF view state, ADF window-ids and session cookies are scoped to the
     *    origin host. A single GET proxy works for the login page, but the POST
     *    round-trips after login will need full cookie + form passthrough and
     *    base-href rewriting to behave. Treat this as a starting point.
     *  - We rewrite the upstream Location header back onto our host; relative
     *    asset paths are left to a <base> tag we inject. Absolute asset URLs to
     *    sgprd.samirgroup.com are NOT rewritten (kept simple on purpose).
     *
     * For production, prefer forward_mode=redirect unless/until this is hardened.
     */
    private function proxy(Request $request, string $destination)
    {
        try {
            $client = new \GuzzleHttp\Client([
                'http_errors' => false,
                'allow_redirects' => false,
                'timeout' => 20,
                'verify' => true,
            ]);

            $upstream = $client->request('GET', $destination, [
                'headers' => [
                    'User-Agent' => $request->userAgent() ?? 'SG-NOC-Proxy',
                    'Accept' => $request->headers->get('accept', '*/*'),
                    'Accept-Language' => $request->headers->get('accept-language', ''),
                ],
                'stream' => true,
            ]);

            $status = $upstream->getStatusCode();
            $contentType = $upstream->getHeaderLine('Content-Type') ?: 'text/html; charset=utf-8';
            $base = preg_replace('#/[^/]*$#', '/', $destination); // dir of the destination

            // For non-HTML (assets), stream straight through.
            $body = (string) $upstream->getBody();
            if (str_contains(strtolower($contentType), 'text/html')) {
                // Inject a <base> so the page's relative asset/links resolve upstream.
                if (! preg_match('/<base\s/i', $body)) {
                    $body = preg_replace('/<head(\s[^>]*)?>/i', '$0<base href="'.e($base).'">', $body, 1);
                }
            }

            $headers = ['Content-Type' => $contentType];
            if ($upstream->hasHeader('Location')) {
                // Rewrite upstream redirect target back onto our host where it points at the backend.
                $headers['Location'] = $upstream->getHeaderLine('Location');
            }

            return response($body, $status, $headers);
        } catch (\Throwable $e) {
            // If proxying fails for any reason, fall back to a plain redirect so
            // the user still reaches the ticketing app.
            Log::error('[TicketForward] proxy failed, falling back to redirect: '.$e->getMessage());

            return redirect()->away($destination, 302);
        }
    }
}
