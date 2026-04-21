<?php

namespace App\Http\Controllers\Admin\BrowserPortal;

use App\Http\Controllers\Controller;
use App\Models\BrowserSession;
use App\Models\BrowserSessionEvent;
use App\Services\BrowserPortal\DockerClient;
use App\Services\BrowserPortal\SessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class AdminBrowserPortalController extends Controller
{
    public function __construct(
        protected SessionManager $sessions,
        protected DockerClient $docker,
    ) {}

    /**
     * List every active session with live docker stats, cached 10 s so that
     * a refresh storm doesn't spam `docker stats`.
     */
    public function index(): View
    {
        $sessions = BrowserSession::active()
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();

        $stats = Cache::remember('browser-portal:docker-stats', 10, function () use ($sessions) {
            $names = $sessions->pluck('container_name')->filter()->values()->all();
            return $this->docker->stats($names);
        });

        return view('admin.browser-portal.admin', compact('sessions', 'stats'));
    }

    /**
     * Force-stop any user's session.
     */
    public function destroy(string $sessionId): RedirectResponse
    {
        $session = BrowserSession::where('session_id', $sessionId)->firstOrFail();
        $this->sessions->stop($session, 'force_stopped', Auth::id());
        return back()->with('success', "Stopped session {$session->session_id} (user: " . ($session->user?->email ?? 'n/a') . ').');
    }

    /**
     * GET /events — full event stream across all users, filterable by type & user.
     */
    public function events(Request $request): View
    {
        $q = BrowserSessionEvent::with('user:id,name,email')
            ->orderByDesc('created_at');

        if ($t = $request->string('type')->trim()->toString()) {
            $q->where('event_type', $t);
        }
        if ($uid = (int) $request->input('user_id')) {
            $q->where('user_id', $uid);
        }
        if ($sid = $request->string('session_id')->trim()->toString()) {
            $q->where('session_id', $sid);
        }

        $events = $q->paginate(100)->withQueryString();

        $types = array_keys(BrowserSessionEvent::eventTypeLabels());

        return view('admin.browser-portal.events', compact('events', 'types'));
    }

    /**
     * GET /{id}/logs — Blade viewer that opens an EventSource against ./stream.
     */
    public function logs(string $sessionId): View
    {
        $session = BrowserSession::where('session_id', $sessionId)->firstOrFail();
        return view('admin.browser-portal.logs', compact('session'));
    }

    /**
     * GET /{id}/logs/stream — SSE of `docker logs -f neko-{id}`.
     * The connection is closed when the browser disconnects or after 10 min.
     */
    public function logStream(string $sessionId): StreamedResponse
    {
        $session = BrowserSession::where('session_id', $sessionId)->firstOrFail();
        $container = $session->container_name;

        // Whitelist: neko-<12 alnum>. Prevents shell-args injection even though
        // Symfony Process argv-mode would block it anyway.
        if (!preg_match('/^neko-[a-z0-9]{12}$/', $container)) {
            abort(400, 'invalid container name');
        }

        $response = new StreamedResponse(function () use ($container) {
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) { ob_end_flush(); }

            $process = new Process(['sudo', '/usr/bin/docker', 'logs', '--tail', '500', '-f', $container]);
            $process->setTimeout(600);   // 10 min hard cap per connection.

            $start = time();
            $process->run(function ($type, $buffer) use (&$start) {
                foreach (preg_split("/\r?\n/", rtrim($buffer, "\r\n")) as $line) {
                    if ($line === '') continue;
                    $prefix = $type === Process::ERR ? 'err' : 'out';
                    echo "event: log\n";
                    echo "data: " . json_encode(['stream' => $prefix, 'line' => $line], JSON_UNESCAPED_SLASHES) . "\n\n";
                    @flush();

                    if (connection_aborted() || (time() - $start) > 600) {
                        throw new \RuntimeException('client disconnected or timeout');
                    }
                }
            });
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');
        return $response;
    }
}
