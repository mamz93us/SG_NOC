<?php

namespace App\Http\Controllers\Admin\BrowserPortal;

use App\Http\Controllers\Controller;
use App\Models\BrowserSession;
use App\Services\BrowserPortal\SessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class BrowserSessionController extends Controller
{
    public function __construct(
        protected SessionManager $sessions,
    ) {}

    /**
     * Dashboard — shows user's current session (if any) and a Launch button.
     */
    public function index(): View
    {
        $active = BrowserSession::where('user_id', Auth::id())->active()->first();
        return view('admin.browser-portal.index', compact('active'));
    }

    /**
     * POST — launch (or return existing) session and redirect to the viewer.
     */
    public function store(): RedirectResponse
    {
        try {
            $session = $this->sessions->launchFor(Auth::user());
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not launch browser session: ' . $e->getMessage());
        }
        return redirect()->route('admin.browser-portal.show', $session->session_id);
    }

    /**
     * GET — Blade page that iframes the Neko stream at /s/{session_id}/.
     */
    public function show(string $sessionId): View|RedirectResponse
    {
        $session = BrowserSession::where('session_id', $sessionId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        if (!$session->isActive()) {
            return redirect()->route('admin.browser-portal.index')
                ->with('error', 'This session is no longer active. Launch a new one.');
        }

        return view('admin.browser-portal.session', compact('session'));
    }

    /**
     * POST /heartbeat — called every 60s from the viewer page JS while open.
     */
    public function heartbeat(Request $request)
    {
        $session = BrowserSession::where('session_id', $request->input('session_id'))
            ->where('user_id', Auth::id())
            ->active()
            ->first();

        if ($session) {
            $session->last_active_at = now();
            $session->save();
        }
        return response()->json(['ok' => (bool) $session]);
    }

    /**
     * DELETE — user stops their own session.
     */
    public function destroy(string $sessionId): RedirectResponse
    {
        $session = BrowserSession::where('session_id', $sessionId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $this->sessions->stop($session);
        return redirect()->route('admin.browser-portal.index')
            ->with('success', 'Browser session stopped. Your profile data is preserved for next time.');
    }
}
