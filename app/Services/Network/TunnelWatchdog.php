<?php

namespace App\Services\Network;

use App\Models\BranchTunnel;
use App\Models\NocEvent;
use App\Models\TunnelHealthCheck;
use App\Models\TunnelProbe;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

/**
 * Watchdog for the branch VPN tunnels on the Azure VPN gateway.
 *
 * Every cycle it probes each tunnel's gateway (the branch firewall) plus every
 * subnet the tunnel is meant to carry, rolls the results up into one state, and
 * raises/resolves NocEvents on transitions.
 *
 * Why per-subnet probes and not just the gateway: on 2026-07-05 the NOC moved to
 * a new subnet and the JED tunnel's traffic selector was rebuilt covering only
 * 10.1.0.0/24. The firewall at 10.1.0.1 answered ICMP the whole time, so a
 * gateway-only check reported JED healthy while 10.1.8.0/24 — and the UCM on it
 * — was unreachable for a month. "degraded" exists precisely to catch that.
 *
 * Everything is probed in parallel (fping for ICMP, async connects for TCP) so a
 * full sweep of every branch finishes in a couple of seconds and can safely run
 * on a 1-minute schedule.
 */
class TunnelWatchdog
{
    /** Consecutive failures before an event/notification fires. Guards against a single dropped packet. */
    public const ALERT_AFTER_FAILURES = 2;

    /**
     * How long a resolved event stays "the same incident".
     *
     * A tunnel that oscillates between down and degraded resolves one event and
     * raises the other on every 1-minute sweep. Because raise() can only match
     * an OPEN event, each flap used to mint a brand-new row with
     * wasRecentlyCreated = true — and therefore a fresh notification to every
     * recipient. RYD produced 238 emails in three days that way, 63 of them in a
     * single hour. Inside this window the original event is re-opened instead,
     * silently: the incident is still the incident, and nobody is told twice.
     */
    private const FLAP_WINDOW_MINUTES = 60;

    /** Per-target budget. Kept tight so a whole sweep fits comfortably inside one minute. */
    private const ICMP_TIMEOUT_MS = 1000;

    private const TCP_TIMEOUT_SEC = 2.0;

    /**
     * UDP gets longer than TCP: there is no handshake to fail fast on, and the
     * ICMP port-unreachable an RTP probe relies on is rate-limited by the far
     * host's kernel (Linux defaults to roughly one per second).
     */
    private const UDP_TIMEOUT_SEC = 3.0;

    public function __construct(private NotificationService $notifications) {}

    /**
     * Probe every active tunnel and persist the results.
     *
     * @return Collection<int, BranchTunnel> the tunnels as re-evaluated this cycle
     */
    public function run(?int $onlyTunnelId = null): Collection
    {
        // `branch` is eager-loaded because event messages name it.
        $query = BranchTunnel::active()->with(['activeProbes', 'branch'])->ordered();
        if ($onlyTunnelId !== null) {
            $query->where('id', $onlyTunnelId);
        }
        $tunnels = $query->get();

        if ($tunnels->isEmpty()) {
            return $tunnels;
        }

        // ── Collect every target up front so each transport runs as one batch ──
        $icmpTargets = [];
        $tcpTargets = [];
        $udpTargets = [];

        foreach ($tunnels as $tunnel) {
            $icmpTargets[] = $tunnel->firewall_ip;

            foreach ($tunnel->activeProbes as $probe) {
                if ($probe->isTcp() && $probe->port) {
                    $tcpTargets["probe-{$probe->id}"] = [$probe->target, (int) $probe->port];
                } elseif ($probe->isUdp() && $probe->port) {
                    $udpTargets["probe-{$probe->id}"] = [$probe->target, (int) $probe->port, $probe->check_type];
                } else {
                    $icmpTargets[] = $probe->target;
                }
            }
        }

        $icmp = $this->icmpBatch(array_values(array_unique(array_filter($icmpTargets))));
        $tcp = $this->tcpBatch($tcpTargets);
        $udp = $this->udpBatch($udpTargets);

        $now = now();

        foreach ($tunnels as $tunnel) {
            // UDP results share the probe-{id} keyspace with TCP, so one merged
            // lookup keeps evaluate() indifferent to which transport ran.
            $this->evaluate($tunnel, $icmp, $tcp + $udp, $now);
        }

        return $tunnels;
    }

    /**
     * Score one tunnel from the batch results, write its state, append history,
     * and open/resolve events.
     *
     * @param  array<string, array{alive:bool, latency:?int}>  $icmp  keyed by host
     * @param  array<string, array{alive:bool, latency:?int, note?:string}>  $ports  TCP + UDP results, keyed probe-{id}
     */
    protected function evaluate(BranchTunnel $tunnel, array $icmp, array $ports, $now): void
    {
        // ── Gateway ──────────────────────────────────────────────────────
        $gateway = $icmp[$tunnel->firewall_ip] ?? ['alive' => false, 'latency' => null];
        $gatewayUp = $gateway['alive'];

        // ── Probes ───────────────────────────────────────────────────────
        $probesUp = 0;
        $probesTotal = 0;
        $failedLabels = [];

        foreach ($tunnel->activeProbes as $probe) {
            $probesTotal++;

            $result = ($probe->isTcp() || $probe->isUdp()) && $probe->port
                ? ($ports["probe-{$probe->id}"] ?? ['alive' => false, 'latency' => null])
                : ($icmp[$probe->target] ?? ['alive' => false, 'latency' => null]);

            $alive = $result['alive'];
            $status = $alive ? 'up' : 'down';

            if ($alive) {
                $probesUp++;
            } else {
                // The note is what makes a UDP failure actionable: "no reply" on
                // an RTP probe reads very differently from "port closed".
                $note = $result['note'] ?? null;
                $failedLabels[] = $probe->label.' ('.$probe->targetLabel().($note ? ' — '.$note : '').')';
            }

            $probe->forceFill([
                'status' => $status,
                'latency_ms' => $alive ? $result['latency'] : null,
                'result_note' => $result['note'] ?? null,
                'last_checked_at' => $now,
                'status_changed_at' => $probe->status === $status ? $probe->status_changed_at : $now,
                'consecutive_failures' => $alive ? 0 : $probe->consecutive_failures + 1,
            ])->saveQuietly();
        }

        // ── Roll-up ──────────────────────────────────────────────────────
        // A dead gateway is "down" regardless of probes — nothing is crossing.
        // A live gateway with a dead probe is "degraded": the tunnel is up but
        // it isn't carrying everything it should.
        $state = match (true) {
            ! $gatewayUp => BranchTunnel::STATE_DOWN,
            $failedLabels !== [] => BranchTunnel::STATE_DEGRADED,
            default => BranchTunnel::STATE_UP,
        };

        $previousState = $tunnel->state;
        $failures = $gatewayUp ? 0 : $tunnel->consecutive_failures + 1;

        $tunnel->forceFill([
            'state' => $state,
            'state_changed_at' => $previousState === $state ? $tunnel->state_changed_at : $now,
            'ping_status' => $gatewayUp ? 'up' : 'down',
            'ping_latency_ms' => $gatewayUp ? $gateway['latency'] : null,
            'last_ping_at' => $now,
            'consecutive_failures' => $failures,
        ])->saveQuietly();

        TunnelHealthCheck::insert([
            'branch_tunnel_id' => $tunnel->id,
            'state' => $state,
            'probes_up' => $probesUp,
            'probes_total' => $probesTotal,
            'latency_ms' => $gatewayUp ? $gateway['latency'] : null,
            'checked_at' => $now,
        ]);

        $this->handleEvents($tunnel, $state, $previousState, $failedLabels);
    }

    /**
     * Raise or resolve NocEvents for a state transition.
     *
     * Down and degraded are tracked as separate source_types so recovering from
     * "down" to "degraded" closes the outage and opens the partial-reachability
     * warning, rather than silently looking resolved.
     *
     * @param  array<int, string>  $failedLabels
     */
    protected function handleEvents(BranchTunnel $tunnel, string $state, ?string $previousState, array $failedLabels): void
    {
        $where = $tunnel->branch?->name ?? $tunnel->name;

        if ($state === BranchTunnel::STATE_DOWN) {
            $this->resolve($tunnel, 'tunnel_degraded');

            // Hold off until the gateway has missed enough cycles to be real.
            if ($tunnel->consecutive_failures < self::ALERT_AFTER_FAILURES) {
                return;
            }

            $this->raise(
                $tunnel,
                'tunnel_down',
                'critical',
                "VPN Tunnel Down: {$tunnel->name}",
                "The {$where} tunnel is not passing traffic — the firewall at {$tunnel->firewall_ip} "
                    ."has not answered {$tunnel->consecutive_failures} consecutive checks."
            );

            return;
        }

        if ($state === BranchTunnel::STATE_DEGRADED) {
            $this->resolve($tunnel, 'tunnel_down');

            // The event is raised on the first degraded sweep — partial
            // reachability must never look healthy on the dashboard, which is
            // the whole point of this state. The *notification* waits for a
            // second consecutive degraded sweep: the same "don't trust one
            // cycle" rule tunnel_down gets from consecutive_failures, which
            // degraded cannot use because the gateway is answering and the
            // counter is zero. A single dropped probe is not worth an email.
            $this->raise(
                $tunnel,
                'tunnel_degraded',
                'warning',
                "VPN Tunnel Degraded: {$tunnel->name}",
                "The {$where} tunnel is up — the firewall at {$tunnel->firewall_ip} is answering — but "
                    .(count($failedLabels) === 1 ? 'this target is' : 'these targets are').' unreachable through it: '
                    .implode(', ', $failedLabels).'. '
                    .'Usually means the subnet is missing from the tunnel traffic selector on the branch firewall — '
                    .'or, when only the SIP/RTP probes fail, that the firewall policy over the tunnel is not permitting UDP voice traffic.',
                mayNotify: $previousState === BranchTunnel::STATE_DEGRADED,
            );

            return;
        }

        // Fully up — close anything we opened.
        if ($previousState !== BranchTunnel::STATE_UP) {
            $this->resolve($tunnel, 'tunnel_down');
            $this->resolve($tunnel, 'tunnel_degraded');
        }
    }

    /**
     * Open (or keep open) the event for this tunnel, and notify at most once
     * per incident.
     *
     * @param  bool  $mayNotify  False suppresses only the notification; the event
     *                           is still raised so the dashboard stays truthful.
     */
    protected function raise(
        BranchTunnel $tunnel,
        string $sourceType,
        string $severity,
        string $title,
        string $message,
        bool $mayNotify = true,
    ): void {
        $latest = NocEvent::where('source_type', $sourceType)
            ->where('source_id', $tunnel->id)
            ->orderByDesc('id')
            ->first();

        // Acknowledged counts as open — resolve() leaves those alone, so they
        // must not spawn a duplicate row.
        $isOpen = $latest && in_array($latest->status, ['open', 'acknowledged'], true);

        // Resolved a moment ago means the same incident is flapping, not a new
        // one: re-open the existing row rather than minting another.
        $isFlap = $latest
            && ! $isOpen
            && $latest->resolved_at
            && $latest->resolved_at->gt(now()->subMinutes(self::FLAP_WINDOW_MINUTES));

        if ($isOpen) {
            $latest->update(['last_seen' => now(), 'message' => $message]);
            $event = $latest;
        } elseif ($isFlap) {
            $latest->update([
                'status' => 'open',
                'resolved_at' => null,
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'last_seen' => now(),
            ]);
            $event = $latest;
        } else {
            $event = NocEvent::create([
                'source_type' => $sourceType,
                'source_id' => $tunnel->id,
                'status' => 'open',
                'module' => 'vpn',
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'first_seen' => now(),
                'last_seen' => now(),
            ]);
        }

        // One notification per incident. email_sent_at is the stamp — it carries
        // across a re-opened flap, which is what keeps a tunnel oscillating
        // between down and degraded from paging everyone every single minute.
        if (! $mayNotify || $event->email_sent_at) {
            return;
        }

        $event->update(['email_sent_at' => now()]);

        $this->notifications->notifyViaRules($sourceType, $title, $message, url('/admin/network/tunnel-health'), $severity);
    }

    protected function resolve(BranchTunnel $tunnel, string $sourceType): void
    {
        NocEvent::where('source_type', $sourceType)
            ->where('source_id', $tunnel->id)
            ->where('status', 'open')
            ->get()
            ->each(fn (NocEvent $ev) => $ev->update(['status' => 'resolved', 'resolved_at' => now()]));
    }

    // ─────────────────────────────────────────────────────────────
    // Transports
    // ─────────────────────────────────────────────────────────────

    /**
     * Ping many hosts at once. Prefers fping (one process for the whole batch);
     * falls back to concurrent `ping` processes when fping isn't installed —
     * which it currently isn't on the NOC VM, so the fallback is the hot path
     * and must not be sequential. 20-odd unreachable targets pinged one after
     * another would not fit inside the 1-minute schedule.
     *
     * @param  array<int, string>  $hosts
     * @return array<string, array{alive:bool, latency:?int}>
     */
    protected function icmpBatch(array $hosts): array
    {
        if ($hosts === []) {
            return [];
        }

        $timeout = (string) self::ICMP_TIMEOUT_MS;

        try {
            // -e round-trip time, -r 1 one retry, -t per-try timeout
            $proc = new Process(array_merge(['fping', '-e', '-r', '1', '-t', $timeout], $hosts));
            $proc->setTimeout(60);
            $proc->run();
        } catch (\Throwable) {
            return $this->icmpParallel($hosts);
        }

        $output = $proc->getOutput()."\n".$proc->getErrorOutput();
        $results = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(\S+)\s+is alive(?:\s+\(([\d.]+)\s*ms\))?/i', $line, $m)) {
                $results[$m[1]] = ['alive' => true, 'latency' => isset($m[2]) ? (int) round((float) $m[2]) : null];
            } elseif (preg_match('/^(\S+)\s+is unreachable/i', $line, $m)) {
                $results[$m[1]] = ['alive' => false, 'latency' => null];
            }
        }

        // No parsable output at all (fping missing, or a build that prints
        // something unexpected) — don't hand back a set of silent gaps the
        // caller would read as "everything down".
        return $results === [] ? $this->icmpParallel($hosts) : $results;
    }

    /**
     * Ping every host with its own concurrent `ping` process. Started all at
     * once and harvested afterwards, so wall-clock is one ping's duration
     * rather than the sum.
     *
     * @param  array<int, string>  $hosts
     * @return array<string, array{alive:bool, latency:?int}>
     */
    protected function icmpParallel(array $hosts): array
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $waitMs = (string) self::ICMP_TIMEOUT_MS;

        /** @var array<string, Process> $running */
        $running = [];
        $results = [];

        foreach ($hosts as $host) {
            // Reject anything that isn't a bare host before it reaches the shell.
            if (! filter_var($host, FILTER_VALIDATE_IP) && ! preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
                $results[$host] = ['alive' => false, 'latency' => null];

                continue;
            }

            $command = $isWindows
                ? ['ping', '-n', '2', '-w', $waitMs, $host]
                : ['ping', '-c', '2', '-W', '1', '-q', $host];

            try {
                $proc = new Process($command);
                $proc->setTimeout(10);
                $proc->start();
                $running[$host] = $proc;
            } catch (\Throwable) {
                $results[$host] = ['alive' => false, 'latency' => null];
            }
        }

        foreach ($running as $host => $proc) {
            try {
                $proc->wait();
                $results[$host] = $this->parsePing($proc->getOutput(), $isWindows);
            } catch (\Throwable) {
                $results[$host] = ['alive' => false, 'latency' => null];
            }
        }

        return $results;
    }

    /** @return array{alive:bool, latency:?int} */
    protected function parsePing(string $output, bool $isWindows): array
    {
        // 100% loss with a reported average still means unreachable, so the loss
        // check gates the verdict rather than the presence of a latency figure.
        $lost = preg_match('/(\d+)%\s*(?:packet\s*)?loss/i', $output, $l) ? (int) $l[1] : 100;

        $latency = null;
        if ($isWindows) {
            if (preg_match('/Average = (\d+)ms/i', $output, $m)) {
                $latency = (int) $m[1];
            }
        } elseif (preg_match('#min/avg/max/mdev = [\d.]+/([\d.]+)/#', $output, $m)) {
            $latency = (int) round((float) $m[1]);
        }

        return ['alive' => $lost < 100, 'latency' => $lost < 100 ? $latency : null];
    }

    /**
     * Open all TCP connects at once and wait on them together, so N probes cost
     * one timeout instead of N. Connect success/failure is read off the write-
     * ready socket via stream_socket_get_name(), which returns false when the
     * async connect failed.
     *
     * @param  array<string, array{0:string, 1:int}>  $targets  keyed by caller-supplied id
     * @return array<string, array{alive:bool, latency:?int}>
     */
    protected function tcpBatch(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $results = [];
        $sockets = [];
        $startedAt = [];

        foreach ($targets as $key => [$host, $port]) {
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                self::TCP_TIMEOUT_SEC,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
            );

            if ($socket === false) {
                $results[$key] = ['alive' => false, 'latency' => null];

                continue;
            }

            stream_set_blocking($socket, false);
            $sockets[$key] = $socket;
            $startedAt[$key] = microtime(true);
        }

        $deadline = microtime(true) + self::TCP_TIMEOUT_SEC;

        while ($sockets !== [] && microtime(true) < $deadline) {
            $write = array_values($sockets);
            $read = $except = null;

            $remaining = max(0.0, $deadline - microtime(true));
            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                break; // deadline hit with nothing else resolving
            }

            foreach ($write as $socket) {
                $key = array_search($socket, $sockets, true);
                if ($key === false) {
                    continue;
                }

                // Writable means the connect resolved; get_name() tells us how.
                $connected = @stream_socket_get_name($socket, true) !== false;

                $results[$key] = [
                    'alive' => $connected,
                    'latency' => $connected ? (int) round((microtime(true) - $startedAt[$key]) * 1000) : null,
                ];

                fclose($socket);
                unset($sockets[$key]);
            }
        }

        // Anything still open when the deadline passed never connected.
        foreach ($sockets as $key => $socket) {
            $results[$key] = ['alive' => false, 'latency' => null];
            fclose($socket);
        }

        return $results;
    }

    /**
     * Probe UDP services — SIP signalling and RTP media — through the tunnel.
     *
     * UDP has no handshake, so "did it connect" is not a question that exists.
     * Three things can happen after the datagram goes out, and each means
     * something different:
     *
     *   • a reply comes back        → the path works and something is listening
     *   • ICMP port unreachable     → the datagram REACHED the host; nothing is
     *                                 bound to that port. For RTP that is the
     *                                 normal idle state and counts as healthy,
     *                                 because it still proves traversal.
     *   • silence                   → nothing got through, or nothing came back:
     *                                 the tunnel is not carrying this traffic.
     *
     * Reading the ICMP error requires a *connected* socket — an unconnected one
     * has no association to report the error against. ext-sockets surfaces it as
     * ECONNREFUSED on the next recv; the stream fallback surfaces it as a failed
     * read. All sockets are sent on first and harvested together, so N probes
     * cost one timeout.
     *
     * Caveats worth knowing before trusting a red RTP probe: the far host's
     * kernel rate-limits ICMP unreachables (Linux ~1/s), and a firewall that
     * suppresses ICMP type 3 makes a healthy path look silent. Keep media probes
     * to one per host.
     *
     * @param  array<string, array{0:string, 1:int, 2:string}>  $targets  [host, port, check_type] keyed by caller id
     * @return array<string, array{alive:bool, latency:?int, note:string}>
     */
    protected function udpBatch(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        if (! function_exists('socket_create')) {
            return $this->udpBatchStreams($targets);
        }

        $results = [];
        $sockets = [];
        $startedAt = [];

        foreach ($targets as $key => [$host, $port, $mode]) {
            $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

            // connect() is what binds the ICMP error back to this socket.
            if ($socket === false || @socket_connect($socket, $host, $port) === false) {
                if ($socket !== false) {
                    socket_close($socket);
                }
                $results[$key] = $this->interpretUdp($mode, null, false, null);

                continue;
            }

            socket_set_nonblock($socket);

            $payload = $this->udpPayload($mode, $socket, $host);
            @socket_send($socket, $payload, strlen($payload), 0);

            $sockets[$key] = $socket;
            $startedAt[$key] = microtime(true);
        }

        $deadline = microtime(true) + self::UDP_TIMEOUT_SEC;

        while ($sockets !== [] && microtime(true) < $deadline) {
            $read = array_values($sockets);
            $write = $except = null;

            $remaining = max(0.0, $deadline - microtime(true));
            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1_000_000);

            $ready = @socket_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                break;
            }

            foreach ($read as $socket) {
                $key = array_search($socket, $sockets, true);
                if ($key === false) {
                    continue;
                }

                socket_clear_error($socket);
                $buffer = '';
                $bytes = @socket_recv($socket, $buffer, 2048, 0);

                // Readable-but-failed is the ICMP error surfacing.
                $refused = $bytes === false
                    && in_array(socket_last_error($socket), [SOCKET_ECONNREFUSED, SOCKET_EHOSTUNREACH, SOCKET_ENETUNREACH], true);

                $results[$key] = $this->interpretUdp(
                    $targets[$key][2],
                    $bytes > 0 ? (string) $buffer : null,
                    $refused,
                    (int) round((microtime(true) - $startedAt[$key]) * 1000),
                );

                socket_close($socket);
                unset($sockets[$key]);
            }
        }

        foreach ($sockets as $key => $socket) {
            $results[$key] = $this->interpretUdp($targets[$key][2], null, false, null);
            socket_close($socket);
        }

        return $results;
    }

    /**
     * Same probe without ext-sockets. Streams cannot report the ICMP error code,
     * only that the read failed — which is enough to tell "the host answered the
     * datagram with a rejection" from "nothing came back at all".
     *
     * @param  array<string, array{0:string, 1:int, 2:string}>  $targets
     * @return array<string, array{alive:bool, latency:?int, note:string}>
     */
    protected function udpBatchStreams(array $targets): array
    {
        $results = [];
        $sockets = [];
        $startedAt = [];

        foreach ($targets as $key => [$host, $port, $mode]) {
            $socket = @stream_socket_client("udp://{$host}:{$port}", $errno, $errstr, self::UDP_TIMEOUT_SEC);

            if ($socket === false) {
                $results[$key] = $this->interpretUdp($mode, null, false, null);

                continue;
            }

            stream_set_blocking($socket, false);

            $payload = $this->udpPayload($mode, null, $host);
            @fwrite($socket, $payload);

            $sockets[$key] = $socket;
            $startedAt[$key] = microtime(true);
        }

        $deadline = microtime(true) + self::UDP_TIMEOUT_SEC;

        while ($sockets !== [] && microtime(true) < $deadline) {
            $read = array_values($sockets);
            $write = $except = null;

            $remaining = max(0.0, $deadline - microtime(true));
            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                break;
            }

            foreach ($read as $socket) {
                $key = array_search($socket, $sockets, true);
                if ($key === false) {
                    continue;
                }

                $reply = @stream_socket_recvfrom($socket, 2048);

                $results[$key] = $this->interpretUdp(
                    $targets[$key][2],
                    is_string($reply) && $reply !== '' ? $reply : null,
                    $reply === false,
                    (int) round((microtime(true) - $startedAt[$key]) * 1000),
                );

                fclose($socket);
                unset($sockets[$key]);
            }
        }

        foreach ($sockets as $key => $socket) {
            $results[$key] = $this->interpretUdp($targets[$key][2], null, false, null);
            fclose($socket);
        }

        return $results;
    }

    /**
     * Score one UDP outcome. Split out from the transport so the rules are
     * testable without a network.
     *
     * SIP is a service check — only a SIP/2.0 response passes, because a closed
     * 5060 means the branch has no signalling even if the packet arrived. RTP is
     * a path check — no listener exists between calls, so a rejection is as good
     * an answer as a reply.
     *
     * @param  string|null  $reply  datagram received, if any
     * @param  bool  $refused  the host answered with ICMP unreachable
     * @return array{alive:bool, latency:?int, note:string}
     */
    protected function interpretUdp(string $mode, ?string $reply, bool $refused, ?int $latency): array
    {
        if ($mode === TunnelProbe::TYPE_SIP) {
            if ($reply !== null) {
                return str_contains($reply, 'SIP/2.0')
                    ? ['alive' => true, 'latency' => $latency, 'note' => 'SIP OPTIONS answered']
                    : ['alive' => false, 'latency' => null, 'note' => 'non-SIP reply'];
            }

            // Silence on 5060 while other ports on the same host answer ICMP
            // unreachable means something IS bound there and chose not to reply —
            // on a Grandstream UCM, that is its SIP ACL, not a broken tunnel.
            return $refused
                ? ['alive' => false, 'latency' => null, 'note' => 'port closed — host reachable, SIP not listening']
                : ['alive' => false, 'latency' => null, 'note' => 'no reply — SIP ACL or tunnel policy'];
        }

        if ($reply !== null) {
            return ['alive' => true, 'latency' => $latency, 'note' => 'answered'];
        }

        // Port unreachable still proves the datagram crossed the tunnel, which is
        // the whole question a media probe is asking.
        // Silence is either "nothing crossed" or "something is listening and
        // ignoring us" — don't claim to know which.
        return $refused
            ? ['alive' => true, 'latency' => $latency, 'note' => 'port closed — path OK']
            : ['alive' => false, 'latency' => null, 'note' => 'no reply — path blocked or port filtered'];
    }

    /**
     * What to put on the wire. A real SIP OPTIONS ping for signalling; a valid
     * 12-byte RTP header for media, so DPI on the path sees plausible traffic
     * rather than a stray empty datagram.
     *
     * @param  resource|null  $socket  connected socket, used to learn our own source address
     */
    protected function udpPayload(string $mode, $socket, string $host): string
    {
        if ($mode !== TunnelProbe::TYPE_SIP) {
            // V=2, PT=0 (PCMU), sequence 0, timestamp 0, random SSRC.
            return pack('CCnNN', 0x80, 0x00, 0, 0, random_int(1, 0x7FFFFFFF));
        }

        $localIp = '127.0.0.1';
        $localPort = 5060;

        if ($socket !== null && @socket_getsockname($socket, $addr, $port)) {
            $localIp = $addr;
            $localPort = $port;
        }

        $tag = bin2hex(random_bytes(6));

        return implode("\r\n", [
            "OPTIONS sip:{$host} SIP/2.0",
            "Via: SIP/2.0/UDP {$localIp}:{$localPort};branch=z9hG4bK{$tag};rport",
            'Max-Forwards: 70',
            "From: \"NOC watchdog\" <sip:watchdog@{$localIp}>;tag={$tag}",
            "To: <sip:{$host}>",
            "Call-ID: {$tag}@{$localIp}",
            'CSeq: 1 OPTIONS',
            "Contact: <sip:watchdog@{$localIp}:{$localPort}>",
            'Accept: application/sdp',
            'User-Agent: SG-NOC-TunnelWatchdog',
            'Content-Length: 0',
            '',
            '',
        ]);
    }

    /**
     * Drop history beyond the retention window. Called from the scheduled prune,
     * not on every cycle.
     */
    public function prune(int $days = 7): int
    {
        return TunnelHealthCheck::where('checked_at', '<', now()->subDays(max(1, $days)))->delete();
    }

    /** Probe types the UI is allowed to create. */
    public static function checkTypes(): array
    {
        return [
            TunnelProbe::TYPE_ICMP => 'ICMP ping',
            TunnelProbe::TYPE_TCP => 'TCP port',
            TunnelProbe::TYPE_SIP => 'SIP (UDP OPTIONS)',
            TunnelProbe::TYPE_UDP => 'UDP port (RTP/media)',
        ];
    }
}
