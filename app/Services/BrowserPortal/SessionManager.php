<?php

namespace App\Services\BrowserPortal;

use App\Models\BrowserPortalSettings;
use App\Models\BrowserSession;
use App\Models\BrowserSessionEvent;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Orchestrates the full lifecycle of a Neko Chromium session:
 *   - Allocates a unique session_id and a WebRTC UDP port chunk.
 *   - Runs `docker run ...` via DockerClient.
 *   - Resolves the container's bridge IP.
 *   - Writes the per-session Nginx snippet via NginxSnippetWriter.
 *   - Persists a BrowserSession row.
 *   - Stops + cleans up when asked.
 *
 * Hard-coded limits here mirror the VPS infra set up in Steps 1-4:
 *   - UDP range 52000-52100 (published at host level via UFW)
 *   - 10 ports per session → 10 concurrent sessions
 *   - browser-net Docker network (subnet 172.30.0.0/16)
 */
class SessionManager
{
    public const NETWORK = 'browser-net';

    public function __construct(
        protected DockerClient $docker,
        protected NginxSnippetWriter $nginx,
    ) {}

    protected function settings(): BrowserPortalSettings
    {
        return BrowserPortalSettings::current();
    }

    /**
     * Record a lifecycle event against a session (or user, if session-less).
     * Never throws — logging failures must not break the main flow.
     */
    public function logEvent(
        ?BrowserSession $session,
        int $userId,
        string $eventType,
        ?string $message = null,
        array $metadata = [],
        ?string $ipAddress = null,
    ): void {
        try {
            BrowserSessionEvent::create([
                'user_id'            => $userId,
                'browser_session_id' => $session?->id,
                'session_id'         => $session?->session_id,
                'event_type'         => $eventType,
                'message'            => $message,
                'metadata'           => $metadata ?: null,
                'ip_address'         => $ipAddress,
                'created_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SessionManager: event log failed', [
                'event' => $eventType, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Launch a session for the given user. If the user already has an active
     * session, return that one instead of spawning a duplicate.
     */
    public function launchFor(User $user): BrowserSession
    {
        $settings = $this->settings();

        if ($existing = BrowserSession::where('user_id', $user->id)->active()->first()) {
            return $existing;
        }

        $this->logEvent(null, $user->id, 'launch_requested', null, [], request()?->ip());

        if (BrowserSession::active()->count() >= $settings->max_concurrent_sessions) {
            $this->logEvent(null, $user->id, 'launch_failed', 'Max concurrent sessions reached', [
                'max' => $settings->max_concurrent_sessions,
            ], request()?->ip());
            throw new \RuntimeException('Maximum concurrent browser sessions reached. Please try again later.');
        }

        [$portStart, $portEnd] = $this->allocatePortChunk();

        // Retry 3 times in the (astronomically unlikely) event of a session_id collision.
        $sessionId = null;
        for ($i = 0; $i < 3; $i++) {
            $candidate = strtolower(Str::random(12));
            $candidate = preg_replace('/[^a-z0-9]/', '', $candidate);
            if (strlen($candidate) < 12) continue;
            if (!BrowserSession::where('session_id', $candidate)->exists()) {
                $sessionId = $candidate;
                break;
            }
        }
        if (!$sessionId) {
            throw new \RuntimeException('Failed to allocate a unique session id');
        }

        $containerName = "neko-$sessionId";
        $volumeName    = "neko-user-{$user->id}";
        $nekoPassword  = Str::random(24);

        $session = BrowserSession::create([
            'session_id'              => $sessionId,
            'user_id'                 => $user->id,
            'container_name'          => $containerName,
            'volume_name'             => $volumeName,
            'webrtc_port_start'       => $portStart,
            'webrtc_port_end'         => $portEnd,
            'status'                  => 'starting',
            // Encrypted (not hashed) so we can decrypt server-side and inject into the
            // iframe URL as ?pwd=... — Neko's multiuser provider needs the plaintext.
            'neko_user_password_hash' => Crypt::encryptString($nekoPassword),
            'last_active_at'          => now(),
        ]);

        try {
            $this->dockerRun($session, $nekoPassword);

            // Give Docker a moment to attach the container to browser-net.
            $ip = null;
            for ($i = 0; $i < 15 && !$ip; $i++) {
                usleep(300_000);
                $ip = $this->docker->bridgeIp($containerName, self::NETWORK);
            }
            if (!$ip) {
                throw new \RuntimeException('Container started but bridge IP never appeared on ' . self::NETWORK);
            }

            $session->internal_ip = $ip;
            $session->status      = 'running';
            $session->save();

            $this->nginx->write($session);

            $this->logEvent($session, $user->id, 'launch_succeeded', null, [
                'ip'    => $ip,
                'ports' => "$portStart-$portEnd",
            ], request()?->ip());
        } catch (\Throwable $e) {
            Log::error('SessionManager: launch failed', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);

            // Best-effort cleanup — ignore secondary failures so we report the original.
            try { $this->docker->rm($containerName, force: true); } catch (\Throwable) {}
            try { $this->nginx->remove($sessionId); } catch (\Throwable) {}

            $session->status = 'error';
            $session->error_message = $e->getMessage();
            $session->save();

            $this->logEvent($session, $user->id, 'launch_failed', $e->getMessage(), [], request()?->ip());

            throw $e;
        }

        return $session;
    }

    /**
     * Stop a session. Removes the container and the Nginx snippet; preserves the volume.
     */
    public function stop(BrowserSession $session, string $eventType = 'stopped', ?int $actorUserId = null): void
    {
        try {
            $this->docker->stop($session->container_name);
        } catch (\Throwable $e) {
            Log::warning('SessionManager: docker stop failed (continuing)', [
                'session_id' => $session->session_id,
                'error'      => $e->getMessage(),
            ]);
        }

        try {
            $this->docker->rm($session->container_name, force: true);
        } catch (\Throwable $e) {
            Log::warning('SessionManager: docker rm failed (continuing)', [
                'session_id' => $session->session_id,
                'error'      => $e->getMessage(),
            ]);
        }

        try {
            $this->nginx->remove($session->session_id);
        } catch (\Throwable $e) {
            Log::warning('SessionManager: nginx remove failed (continuing)', [
                'session_id' => $session->session_id,
                'error'      => $e->getMessage(),
            ]);
        }

        $session->status     = 'stopped';
        $session->stopped_at = now();
        $session->save();

        $this->logEvent($session, $actorUserId ?? $session->user_id, $eventType, null, [
            'by_user_id' => $actorUserId,
        ], request()?->ip());
    }

    /**
     * Count-free UDP port chunk within the configured range.
     * Strategy: step in PORTS_PER_SESSION-sized windows and find one where
     * no active session has overlapping ports.
     */
    protected function allocatePortChunk(): array
    {
        $s = $this->settings();
        $rangeStart = (int) $s->udp_port_range_start;
        $rangeEnd   = (int) $s->udp_port_range_end;
        $chunk      = (int) $s->ports_per_session;

        $taken = BrowserSession::active()
            ->get(['webrtc_port_start', 'webrtc_port_end'])
            ->map(fn ($r) => [(int) $r->webrtc_port_start, (int) $r->webrtc_port_end])
            ->all();

        for ($start = $rangeStart; $start + $chunk - 1 <= $rangeEnd; $start += $chunk) {
            $end = $start + $chunk - 1;
            $overlaps = false;
            foreach ($taken as [$ps, $pe]) {
                if ($start <= $pe && $ps <= $end) { $overlaps = true; break; }
            }
            if (!$overlaps) return [$start, $end];
        }
        throw new \RuntimeException("No free UDP port chunk available in $rangeStart-$rangeEnd");
    }

    /**
     * Build the docker run argv and launch the container.
     */
    protected function dockerRun(BrowserSession $session, string $nekoPassword): void
    {
        $settings = $this->settings();
        $vpsIp = (string) config('services.browser_portal.vps_public_ip', env('BROWSER_PORTAL_VPS_IP', ''));
        if ($vpsIp === '') {
            throw new \RuntimeException('BROWSER_PORTAL_VPS_IP is not configured; Neko needs it for WebRTC NAT1TO1.');
        }

        $adminPassword = (string) env('BROWSER_PORTAL_NEKO_ADMIN_PASSWORD', '');
        if ($adminPassword === '') {
            throw new \RuntimeException('BROWSER_PORTAL_NEKO_ADMIN_PASSWORD is not configured.');
        }

        $portRange = "{$session->webrtc_port_start}-{$session->webrtc_port_end}";
        $supervisorConf = base_path('deployment/browser-portal/test/chromium-supervisor.conf');
        $policiesJson   = base_path('deployment/browser-portal/test/chromium-policies.json');

        $args = [
            '-d',
            '--name', $session->container_name,
            '--restart', 'unless-stopped',
            '--network', self::NETWORK,
            '--shm-size', '2g',
            '--cap-add', 'SYS_ADMIN',
            '-p', "$portRange:$portRange/udp",
            '-v', "{$session->volume_name}:/home/neko",
            '-v', "$supervisorConf:/etc/neko/supervisord/chromium.conf:ro",
            '-v', "$policiesJson:/etc/chromium/policies/managed/policies.json:ro",
            '-e', "NEKO_DESKTOP_SCREEN={$settings->desktop_resolution}",
            '-e', 'NEKO_SERVER_BIND=0.0.0.0:8080',
            '-e', "NEKO_SERVER_PROXY=true",
            '-e', "NEKO_SERVER_PATH_PREFIX=/s/{$session->session_id}",
            '-e', 'NEKO_MEMBER_PROVIDER=multiuser',
            '-e', "NEKO_MEMBER_MULTIUSER_USER_PASSWORD=$nekoPassword",
            '-e', "NEKO_MEMBER_MULTIUSER_ADMIN_PASSWORD=$adminPassword",
            '-e', "NEKO_WEBRTC_EPR=$portRange",
            '-e', "NEKO_WEBRTC_NAT1TO1=$vpsIp",
            '-e', 'NEKO_WEBRTC_ICELITE=true',
            $settings->neko_image,
        ];

        $this->docker->run($args);
    }

    /**
     * Stop all sessions that have been idle longer than the cutoff.
     * Returns the number of sessions stopped.
     */
    public function stopIdleSessions(\DateTimeInterface $cutoff): int
    {
        $stopped = 0;
        foreach (BrowserSession::idleSince($cutoff)->get() as $session) {
            try {
                $this->stop($session, 'idle_stopped');
                $stopped++;
            } catch (\Throwable $e) {
                Log::error('SessionManager: idle-cleanup failed', [
                    'session_id' => $session->session_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
        return $stopped;
    }
}
