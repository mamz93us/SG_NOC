<?php

namespace App\Http\Controllers\Admin\BrowserPortal;

use App\Http\Controllers\Controller;
use App\Models\BrowserSession;
use App\Services\BrowserPortal\DockerClient;
use App\Services\BrowserPortal\SessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

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
        $this->sessions->stop($session);
        return back()->with('success', "Stopped session {$session->session_id} (user: " . ($session->user?->email ?? 'n/a') . ').');
    }
}
