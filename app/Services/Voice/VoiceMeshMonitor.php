<?php

namespace App\Services\Voice;

use App\Models\NocEvent;
use App\Models\Setting;
use App\Models\VoiceMeshNode;
use App\Models\VoiceMeshPair;
use App\Models\VoiceMeshResult;
use App\Models\VoiceMeshRun;
use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receives the synthetic call mesh's health reports, keeps per-leg and per-node
 * state, and raises NocEvents.
 *
 * Unlike TunnelWatchdog this service does not probe anything itself — the
 * calling is done by a Python/pjsua service under systemd on this host (see
 * deployment/voice-mesh/), because it needs to REGISTER to each branch's UCM
 * and place real calls. This class is the NOC half: ingest, roll-up, alert.
 *
 * The alerting shape is the part that matters. A mesh of N nodes has N*(N-1)
 * legs, so ONE dead UCM fails 2*(N-1) of them — 12 legs at seven branches.
 * Raising an event per failing leg would mean a dozen events and a dozen emails
 * for a single fault, which is the failure mode TunnelWatchdog's flap window was
 * written to stop after RYD produced 238 emails in three days. So failures roll
 * up to the node first, and leg-level events are suppressed for any leg whose
 * caller or dest already has a node-level event explaining it.
 */
class VoiceMeshMonitor
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Ingest one posted report.
     *
     * @param  array<string, mixed>  $payload  already validated by the controller
     */
    public function ingest(array $payload, ?string $sourceIp = null): VoiceMeshRun
    {
        $results = $this->matchableResults($payload['results'] ?? []);

        $nodes = VoiceMeshNode::whereIn('code', $results->pluck('codes')->flatten()->unique())
            ->get()
            ->keyBy(fn (VoiceMeshNode $n) => strtoupper($n->code));

        [$matched, $unknownCodes] = $this->partitionByKnownNodes($results, $nodes);

        [$run, $pairs] = DB::transaction(function () use ($payload, $matched, $unknownCodes, $nodes, $sourceIp) {
            $run = $this->createRun($payload, $matched, $unknownCodes, $nodes, $sourceIp);
            $pairs = $this->applyResults($run, $matched, $nodes);

            return [$run, $pairs];
        });

        // After commit: a notification must never fire for a rolled-back run.
        $this->handleEvents($matched, $nodes, $pairs);

        $this->clearSweepRequest();

        return $run;
    }

    /**
     * A "run now" from the admin UI is satisfied by the report it produced, so
     * it fires once rather than on every wake until it expires.
     */
    private function clearSweepRequest(): void
    {
        try {
            $settings = Setting::get();

            if ($settings->voice_mesh_sweep_requested_at) {
                $settings->forceFill([
                    'voice_mesh_sweep_requested_at' => null,
                    'voice_mesh_sweep_scope' => null,
                ])->save();
            }
        } catch (\Throwable) {
            // Never let this fail an otherwise good ingest — the request
            // expires on its own anyway.
        }
    }

    /**
     * Normalise the posted rows and drop the meaningless ones. A self-call
     * (caller === dest) isn't an error worth a 422 — the mesh just has nothing
     * to say about it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function matchableResults(array $rows)
    {
        return collect($rows)
            ->map(function (array $row) {
                $caller = strtoupper(trim((string) ($row['caller'] ?? '')));
                $dest = strtoupper(trim((string) ($row['dest'] ?? '')));

                return [
                    'caller' => $caller,
                    'dest' => $dest,
                    'codes' => [$caller, $dest],
                    'dest_ext' => $row['dest_ext'] ?? null,
                    'ok' => (bool) ($row['ok'] ?? false),
                    'rx_pkt' => isset($row['rx_pkt']) ? (int) $row['rx_pkt'] : null,
                    'duration_sec' => isset($row['duration_sec']) ? (float) $row['duration_sec'] : null,
                    'reference_sec' => isset($row['reference_duration_sec'])
                        ? (float) $row['reference_duration_sec']
                        : null,
                    'reason' => $row['reason'] ?? null,
                ];
            })
            ->filter(fn (array $row) => $row['caller'] !== '' && $row['dest'] !== '' && $row['caller'] !== $row['dest'])
            ->values();
    }

    /**
     * Split the rows into those whose endpoints both exist and those that name
     * a code we've never heard of.
     *
     * Unknown codes are recorded on the run and dropped — never auto-created.
     * Minting a node from a payload string would let one typo in the prober's
     * config create a phantom branch with no credentials that then alerts
     * forever.
     */
    private function partitionByKnownNodes($results, $nodes): array
    {
        $unknown = [];

        $matched = $results->filter(function (array $row) use ($nodes, &$unknown) {
            $missing = array_values(array_filter(
                [$row['caller'], $row['dest']],
                fn (string $code) => ! $nodes->has($code)
            ));

            foreach ($missing as $code) {
                $unknown[$code] = true;
            }

            return $missing === [];
        })->values();

        return [$matched, array_keys($unknown)];
    }

    private function createRun(array $payload, $matched, array $unknownCodes, $nodes, ?string $sourceIp): VoiceMeshRun
    {
        $failed = $matched->reject(fn (array $r) => $r['ok'])->count();

        return VoiceMeshRun::create([
            'runner_name' => $payload['runner_name'] ?? config('voice_mesh.runner_name'),
            'probe_version' => $payload['probe_version'] ?? null,
            'reported_at' => $this->clampReportedAt($payload['timestamp'] ?? null),
            'received_at' => now(),
            // Trust our own arithmetic over the prober's top-level flag: it can
            // only see the legs it managed to attempt.
            'ok' => $failed === 0 && $matched->isNotEmpty() && $unknownCodes === [],
            'pairs_total' => $matched->count(),
            'pairs_ok' => $matched->count() - $failed,
            'pairs_failed' => $failed,
            'nodes_total' => $matched->pluck('codes')->flatten()->unique()->count(),
            'source_ip' => $sourceIp,
            'unknown_nodes' => $unknownCodes ?: null,
            'payload' => $payload,
        ]);
    }

    /**
     * The prober's own clock, bounded. A probe host whose time is badly wrong
     * must not be able to reorder or poison the history; anything outside a day
     * either side is discarded in favour of "now".
     */
    private function clampReportedAt(?string $raw): Carbon
    {
        if (! $raw) {
            return now();
        }

        try {
            $parsed = Carbon::parse($raw);
        } catch (\Throwable) {
            return now();
        }

        return $parsed->betweenIncluded(now()->subDay(), now()->addDay()) ? $parsed : now();
    }

    /**
     * Upsert each leg's current state and append the history rows.
     *
     * Legs that weren't in this report keep whatever state they had — a short
     * run is a prober problem, not a branch problem, and voice_mesh_stale is
     * what covers the prober going quiet. The UI greys them out on age instead.
     *
     * @return array<string, VoiceMeshPair> keyed "CALLER|DEST", for handleEvents
     */
    private function applyResults(VoiceMeshRun $run, $matched, $nodes): array
    {
        $checkedAt = $run->reported_at ?? $run->received_at;
        $history = [];
        $pairs = [];

        foreach ($matched as $row) {
            $caller = $nodes->get($row['caller']);
            $dest = $nodes->get($row['dest']);

            $pair = VoiceMeshPair::firstOrCreate([
                'caller_node_id' => $caller->id,
                'dest_node_id' => $dest->id,
            ]);

            $status = $row['ok'] ? VoiceMeshPair::STATUS_OK : VoiceMeshPair::STATUS_FAIL;

            // saveQuietly for the same reason TunnelWatchdog uses it: 42 legs
            // every sweep would otherwise mean 42 observer-driven ActivityLog
            // rows an hour, all of them noise.
            $pair->forceFill([
                'status' => $status,
                'last_reason' => $row['reason'],
                'last_dest_ext' => $row['dest_ext'] ?? $dest->ivr_ext,
                'last_rx_pkt' => $row['rx_pkt'],
                'last_duration_sec' => $row['duration_sec'],
                'last_reference_sec' => $row['reference_sec'],
                'last_checked_at' => $checkedAt,
                'last_ok_at' => $row['ok'] ? $checkedAt : $pair->last_ok_at,
                'status_changed_at' => $pair->status === $status ? $pair->status_changed_at : $checkedAt,
                'consecutive_failures' => $row['ok'] ? 0 : $pair->consecutive_failures + 1,
            ])->saveQuietly();

            $pairs["{$row['caller']}|{$row['dest']}"] = $pair;

            $history[] = [
                'voice_mesh_run_id' => $run->id,
                'caller_node_id' => $caller->id,
                'dest_node_id' => $dest->id,
                'caller_code' => $caller->code,
                'dest_code' => $dest->code,
                'dest_ext' => $row['dest_ext'] ?? $dest->ivr_ext,
                'ok' => $row['ok'],
                'rx_pkt' => $row['rx_pkt'],
                'duration_sec' => $row['duration_sec'],
                'reference_sec' => $row['reference_sec'],
                'reason' => $row['reason'],
                'checked_at' => $checkedAt,
            ];
        }

        if ($history !== []) {
            VoiceMeshResult::insert($history);
        }

        $this->rollUpNodes($matched, $nodes, $checkedAt);

        return $pairs;
    }

    /**
     * A node's state from the legs it took part in, in both directions.
     */
    private function rollUpNodes($matched, $nodes, $checkedAt): void
    {
        foreach ($nodes as $node) {
            $legs = $matched->filter(
                fn (array $r) => $r['caller'] === strtoupper($node->code) || $r['dest'] === strtoupper($node->code)
            );

            if ($legs->isEmpty()) {
                continue;   // wasn't in this run — leave its state alone
            }

            $failed = $legs->reject(fn (array $r) => $r['ok'])->count();

            $state = match (true) {
                $failed === $legs->count() => VoiceMeshNode::STATE_DOWN,
                $failed > 0 => VoiceMeshNode::STATE_DEGRADED,
                default => VoiceMeshNode::STATE_UP,
            };

            $node->forceFill([
                'state' => $state,
                'state_changed_at' => $node->state === $state ? $node->state_changed_at : $checkedAt,
                'consecutive_failures' => $state === VoiceMeshNode::STATE_UP ? 0 : $node->consecutive_failures + 1,
                'last_result_at' => $checkedAt,
            ])->saveQuietly();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Events
    // ─────────────────────────────────────────────────────────────

    /**
     * Three tiers, deliberately ordered so one fault produces one alert:
     *
     *   1. caller_down — every outbound leg from a node failed. That branch
     *      cannot originate voice at all, or (the likelier cause on day one)
     *      the NOC's registration to its UCM is being refused.
     *   2. dest_down   — every inbound leg to a node failed while at least one
     *      caller is otherwise healthy, i.e. its IVR is unreachable rather than
     *      the whole mesh being broken.
     *   3. pair_failed — a single leg failed while both its endpoints are
     *      otherwise fine. Only raised after alert_after_failures consecutive
     *      sweeps, and only when neither endpoint already has a tier-1/2 event,
     *      because that event already names this leg.
     */
    private function handleEvents($matched, $nodes, array $pairs): void
    {
        if ($matched->isEmpty()) {
            return;
        }

        $involved = $matched->pluck('codes')->flatten()->unique()->filter(fn ($c) => $nodes->has($c));

        // Pass 1 — callers. Must complete before any dest is judged: "nobody
        // could reach Y" only means something about Y once we know which
        // callers were capable of reaching anything at all.
        $callersDown = $this->raiseCallerEvents($matched, $nodes, $involved);

        // Pass 2 — dests, discounting the callers we already know are down.
        $destsDown = $this->raiseDestEvents($matched, $nodes, $involved, $callersDown);

        // Pass 3 — individual legs, suppressed wherever a node event already
        // explains them. This is what keeps one dead UCM to one email instead
        // of twelve.
        $this->raiseLegEvents($matched, $nodes, $pairs, $callersDown, $destsDown);
    }

    /** @return array<string, VoiceMeshNode> codes whose every outbound leg failed */
    private function raiseCallerEvents($matched, $nodes, $involved): array
    {
        $down = [];

        foreach ($involved as $code) {
            $out = $matched->where('caller', $code);

            if ($out->isEmpty()) {
                continue;   // not a caller this run — nothing to say either way
            }

            $node = $nodes->get($code);

            if ($out->reject(fn ($r) => $r['ok'])->count() !== $out->count()) {
                $this->resolve($node, 'voice_mesh_caller_down');

                continue;
            }

            $down[$code] = $node;

            $this->raise($node, 'voice_mesh_caller_down', 'critical',
                "Branch cannot place calls: {$node->code}",
                "Every outbound test call from {$node->name} ({$node->code}) failed. Either the NOC's SIP "
                    ."registration to its UCM at {$node->sip_server} is being refused, or the branch cannot "
                    .'originate voice at all. Check the UCM SIP ACL for the NOC, the probe extension '
                    ."{$node->sip_user}, and whether the tunnel policy permits UDP/5060 and the RTP range."
            );
        }

        return $down;
    }

    /** @return array<string, VoiceMeshNode> codes unreachable from otherwise-healthy callers */
    private function raiseDestEvents($matched, $nodes, $involved, array $callersDown): array
    {
        $down = [];

        foreach ($involved as $code) {
            // Only legs whose caller was able to place calls at all count as
            // evidence about this destination.
            $in = $matched->where('dest', $code)
                ->reject(fn ($r) => isset($callersDown[$r['caller']]));

            if ($in->isEmpty()) {
                continue;
            }

            $node = $nodes->get($code);

            if ($in->reject(fn ($r) => $r['ok'])->count() !== $in->count()) {
                $this->resolve($node, 'voice_mesh_dest_down');

                continue;
            }

            $down[$code] = $node;

            $this->raise($node, 'voice_mesh_dest_down', 'critical',
                "Branch IVR unreachable: {$node->code}",
                "Every test call to {$node->name} ({$node->code}) IVR extension {$node->ivr_ext} failed, from "
                    .'branches that are themselves placing calls successfully. Check that the IVR exists and '
                    .'answers, and that inbound routing from the trunks reaches it.'
            );
        }

        return $down;
    }

    private function raiseLegEvents($matched, $nodes, array $pairs, array $callersDown, array $destsDown): void
    {
        $threshold = (int) config('voice_mesh.alert_after_failures', 2);

        foreach ($matched as $row) {
            $pair = $pairs["{$row['caller']}|{$row['dest']}"] ?? null;

            if (! $pair) {
                continue;
            }

            $explained = isset($callersDown[$row['caller']]) || isset($destsDown[$row['dest']])
                || isset($callersDown[$row['dest']]) || isset($destsDown[$row['caller']]);

            if ($row['ok'] || $explained) {
                $this->resolvePair($pair);

                continue;
            }

            if ($pair->consecutive_failures < $threshold) {
                continue;   // one bad sweep is not worth an email
            }

            $caller = $nodes->get($row['caller']);
            $dest = $nodes->get($row['dest']);

            $this->raisePair($pair,
                "Call path failed: {$caller->code} → {$dest->code}",
                "Test calls from {$caller->name} ({$caller->code}) to {$dest->name} ({$dest->code}) IVR extension "
                    .($row['dest_ext'] ?? $dest->ivr_ext)." have failed {$pair->consecutive_failures} consecutive "
                    .'sweeps, while both branches are otherwise reachable in the mesh. Reported reason: '
                    .($row['reason'] ?: 'none given').'.'
            );
        }
    }

    private function raise(VoiceMeshNode $node, string $sourceType, string $severity, string $title, string $message): void
    {
        $this->upsertEvent($sourceType, $node->id, $severity, $title, $message);
    }

    private function raisePair(VoiceMeshPair $pair, string $title, string $message): void
    {
        $this->upsertEvent('voice_mesh_pair_failed', $pair->id, 'warning', $title, $message);
    }

    /**
     * The dedup ladder, same as TunnelWatchdog::raise(): an already-open (or
     * acknowledged) event is refreshed in place; one resolved inside the flap
     * window is re-opened rather than duplicated; otherwise a new row.
     */
    private function upsertEvent(string $sourceType, int $sourceId, string $severity, string $title, string $message): void
    {
        $latest = NocEvent::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderByDesc('id')
            ->first();

        $isOpen = $latest && in_array($latest->status, ['open', 'acknowledged'], true);

        $isFlap = $latest
            && ! $isOpen
            && $latest->resolved_at
            && $latest->resolved_at->gt(now()->subMinutes((int) config('voice_mesh.flap_window_minutes', 180)));

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
                'source_id' => $sourceId,
                'status' => 'open',
                'module' => 'voip',
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'first_seen' => now(),
                'last_seen' => now(),
            ]);
        }

        // One notification per incident. The stamp carries across a re-opened
        // flap, so a leg oscillating pass/fail can't page anyone every sweep.
        if ($event->email_sent_at) {
            return;
        }

        $event->update(['email_sent_at' => now()]);

        $this->notifications->notifyViaRules(
            $sourceType, $title, $message, url('/admin/network/voice-mesh'), $severity
        );
    }

    private function resolve(VoiceMeshNode $node, string $sourceType): void
    {
        $this->resolveEvents($sourceType, $node->id);
    }

    private function resolvePair(VoiceMeshPair $pair): void
    {
        $this->resolveEvents('voice_mesh_pair_failed', $pair->id);
    }

    /** Only ever closes `open` rows — an acknowledged event is someone's to own. */
    private function resolveEvents(string $sourceType, int $sourceId): void
    {
        NocEvent::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', 'open')
            ->get()
            ->each(fn (NocEvent $ev) => $ev->update(['status' => 'resolved', 'resolved_at' => now()]));
    }

    // ─────────────────────────────────────────────────────────────
    // Liveness of the prober itself
    // ─────────────────────────────────────────────────────────────

    /**
     * The mesh has two independent schedulers — Laravel's, and the systemd
     * timer running the prober. This is the only thing that notices when the
     * systemd side dies, so a silent matrix doesn't read as a healthy one.
     */
    public function checkStale(): ?string
    {
        $latest = VoiceMeshRun::orderByDesc('received_at')->first();

        if (! $latest) {
            return null;    // nothing has ever reported — a fresh install, not an outage
        }

        $staleAfter = (int) config('voice_mesh.stale_after_minutes', 75);
        $ageMinutes = (int) $latest->received_at->diffInMinutes(now());

        if ($ageMinutes < $staleAfter) {
            $this->resolveEvents('voice_mesh_stale', 0);

            return null;
        }

        $message = "No voice mesh report has been received for {$ageMinutes} minutes (expected every "
            .config('voice_mesh.interval_minutes', 30).' minutes). The prober on the NOC host has stopped '
            .'reporting. Check `systemctl status voice-mesh-verify.timer`, that pjsua is still installed at '
            .'/usr/local/bin/pjsua, and that the ingest secret in deployment/voice-mesh/config.conf still '
            .'matches the one in Settings.';

        $this->upsertEvent('voice_mesh_stale', 0, 'warning', 'Voice mesh prober not reporting', $message);

        return $message;
    }

    /** Retention for the append-only tables. Runs from data:prune. */
    public function prune(int $days): array
    {
        $cutoff = now()->subDays(max(1, $days));

        $results = VoiceMeshResult::where('checked_at', '<', $cutoff)->delete();
        $runs = VoiceMeshRun::where('received_at', '<', $cutoff)->delete();

        return ['results' => $results, 'runs' => $runs];
    }
}
