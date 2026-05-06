<?php

namespace App\Http\Controllers\Admin;

/*
 * SECURITY NOTES:
 *  1. Only devices in the `devices` table can be accessed — no arbitrary IPs.
 *     Device is resolved by Laravel model binding on every request.
 *  2. All routes require auth middleware (enforced in routes/web.php).
 *  3. SSH credentials are stored in the Laravel cache (short-lived token) ONLY —
 *     never persisted to the database.
 *  4. The proxy only connects to $device->ip_address from DB — never user-supplied host.
 *  5. Sessions are tracked in device_ssh_sessions; credentials are not stored there.
 *  6. Rate-limited: throttle:60,1 per user (see routes/web.php).
 */

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceAccessLog;
use App\Models\DeviceSshSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DeviceSshController extends Controller
{
    // ── Connect form ──────────────────────────────────────────────────────

    /**
     * Show the SSH credentials form for a device.
     * Pre-fills ssh_username from the device record if stored.
     */
    public function connect(Request $request, Device $device): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        abort_unless($device->ip_address, 422, 'Device has no IP address configured.');

        // If the device has a stored ssh_username we can pre-fill; password is never stored.
        return view('admin.devices.ssh_connect', compact('device'));
    }

    // ── Launch terminal ───────────────────────────────────────────────────

    /**
     * Validate credentials, create a session record, generate a one-time token,
     * and redirect to the full-screen terminal view.
     *
     * SSH credentials are stored in the Laravel cache only (TTL = 30 min) and are
     * NEVER written to the database.
     */
    public function terminal(Request $request, Device $device): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        abort_unless($device->ip_address, 422, 'Device has no IP address configured.');

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        // Per-user concurrent-session cap. Dangling "active" rows from crashed
        // terminals would lock users out forever, so auto-close rows older than
        // the SSH token TTL before counting — the real session can't outlive
        // its credential cache.
        $maxConcurrent = (int) config('telnet.max_concurrent_sessions', 3);
        DeviceSshSession::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->where('started_at', '<', now()->subHours(2))
            ->update(['status' => 'expired', 'ended_at' => now()]);

        $active = DeviceSshSession::where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->count();
        if ($active >= $maxConcurrent) {
            return redirect()->route('admin.devices.ssh.connect', $device)
                ->withErrors(['limit' => "You already have {$active} active SSH session(s). Close one before opening another (max {$maxConcurrent})."]);
        }

        // Create session record (credentials NOT stored here)
        $session = DeviceSshSession::create([
            'device_id'    => $device->id,
            'user_id'      => $request->user()->id,
            'status'       => 'active',
            'ssh_username' => $validated['username'],
            'client_ip'    => $request->ip(),
            'started_at'   => now(),
        ]);

        // Log session start
        DeviceAccessLog::log(
            $device, $request->user(), 'ssh', 'session_start', $request->ip(),
            ['ssh_session_id' => $session->id, 'ssh_username' => $validated['username']]
        );

        // Store connection details in cache — credentials live 5 min only (terminal WS grabs
        // them on first connect; after that they can expire).
        $token = Str::random(40);
        Cache::put("telnet_token:{$token}", [
            'host'       => $device->ip_address,   // ALWAYS device's own stored IP
            'port'       => $device->ssh_port ?? 22,
            'protocol'   => 'ssh',
            'username'   => $validated['username'],
            'password'   => $validated['password'], // only in cache, never in DB
            'user_id'    => $request->user()->id,
            'session_id' => $session->id,
        ], now()->addMinutes(5));

        $wsUrl = rtrim(config('telnet.ws_url', 'wss://noc.samirgroup.net/ws/telnet'), '/');

        return view('admin.devices.terminal', [
            'device'   => $device,
            'session'  => $session,
            'wsUrl'    => "{$wsUrl}?token={$token}",
            'label'    => $device->name,
            'protocol' => 'ssh',
        ]);
    }

    // ── Disconnect ────────────────────────────────────────────────────────

    /**
     * Mark a session as closed and log the end event.
     * Called by the terminal JS when the WebSocket closes cleanly.
     */
    public function disconnect(Request $request, Device $device, DeviceSshSession $session): \Illuminate\Http\JsonResponse
    {
        abort_unless($session->device_id === $device->id, 404);
        abort_unless(
            $session->user_id === $request->user()->id || $request->user()->can('manage-devices'),
            403
        );

        if ($session->status === 'active') {
            $session->close('closed');

            DeviceAccessLog::log(
                $device, $request->user(), 'ssh', 'session_end', $request->ip(),
                [
                    'ssh_session_id'   => $session->id,
                    'duration_seconds' => $session->duration_seconds,
                ]
            );
        }

        return response()->json(['ok' => true, 'duration' => $session->durationLabel()]);
    }
}
