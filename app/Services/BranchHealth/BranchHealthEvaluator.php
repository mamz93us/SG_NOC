<?php

namespace App\Services\BranchHealth;

use App\Models\Device;
use App\Models\Printer;
use App\Support\SecretScrubber;
use Illuminate\Support\Collection;

/**
 * Turns one branch's telemetry into the 100-point score.
 *
 * PURE. This class runs no queries, reads no clock beyond now(), and reaches
 * for nothing outside the slice it is handed. That is what guarantees
 * scoreForBranch() and allBranches() agree: they differ only in how many
 * branches the loader was asked for, never in how a branch is judged.
 *
 * Three rules are applied identically by every check:
 *
 *   1. points = max_points * passing / expected
 *   2. No configured inventory means `unknown` — zero points AND zero coverage.
 *      An empty set is not a healthy set.
 *   3. Stale telemetry means `unknown`, never healthy. A device we have stopped
 *      hearing from is not a device that is up.
 *
 * Configured-but-unmonitored items stay in the denominator. They earn nothing
 * and drag coverage down, which is what lets the UI tell "this is broken" apart
 * from "we never watched this".
 */
class BranchHealthEvaluator
{
    public function evaluate(BranchSlice $slice): array
    {
        $checks = [
            'voip' => [
                $this->ucmReachable($slice),
                $this->ucmTrunks($slice),
                $this->voiceMesh($slice),
                $this->mosCompliance($slice),
            ],
            'network' => [
                $this->firewallReachable($slice),
                $this->gatewayReachable($slice),
                $this->switchReachability($slice),
                $this->accessPointReachability($slice),
                $this->firewallAlerts($slice),
            ],
            'devices' => [
                $this->printerReachability($slice),
                $this->biometricReachability($slice),
                $this->printerToner($slice),
            ],
        ];

        return $this->assemble($checks, $slice);
    }

    // ─────────────────────────────────────────────────────────────
    // Assembly
    // ─────────────────────────────────────────────────────────────

    private function assemble(array $grouped, BranchSlice $slice): array
    {
        $labels = BranchHealthConfig::get('category_labels');
        $categories = [];
        $rawTotal = 0.0;
        $evaluable = 0.0;

        foreach ($grouped as $key => $checks) {
            $points = 0.0;
            $max = 0;
            $categoryEvaluable = 0.0;

            foreach ($checks as $check) {
                $points += $check['points'];
                $max += $check['max_points'];
                // An `unknown` check contributes its full weight to the 100-point
                // scale but nothing to what we could actually assess.
                if ($check['status'] !== 'unknown') {
                    $categoryEvaluable += $check['max_points'];
                }
            }

            $rawTotal += $points;
            $evaluable += $categoryEvaluable;

            $categories[$key] = [
                'key' => $key,
                'label' => $labels[$key] ?? ucfirst($key),
                'points' => round($points, 1),
                'max_points' => $max,
                // Percent of what this category was worth, so a category is
                // colourable on its own 0-100 scale rather than on raw points.
                'percent' => $max > 0 ? (int) round($points / $max * 100) : 0,
                'evaluable_points' => round($categoryEvaluable, 1),
                'checks' => $checks,
            ];
        }

        // Round ONCE, from the unrounded float sum. Rounding each category first
        // and adding those would let the parts disagree with the whole, and a
        // bare (int) cast would truncate 99.99999 to 99.
        $rounded = (int) round($rawTotal);
        [$total, $capReasons] = $this->applyCaps($rounded, $slice);

        // Coverage-normalized: what the branch earned out of what could be
        // judged. A branch is not marked down for kit it has not onboarded.
        $normalized = $evaluable > 0
            ? (int) round(min($total, $evaluable) / $evaluable * 100)
            : 0;

        return [
            'total' => $total,
            'raw_total' => $rounded,
            'coverage_percent' => (int) round($evaluable),
            'normalized_percent' => $evaluable > 0 ? $normalized : null,
            'status' => $this->status($normalized, $evaluable, $capReasons),
            'cap_reasons' => $capReasons,
            'categories' => $categories,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Caps are applied with min() over every cap whose condition holds, so the
     * order they are declared in cannot change the outcome and every triggered
     * reason is reported. The score is never silently altered — a capped total
     * always arrives with the explanation attached.
     */
    private function applyCaps(int $rounded, BranchSlice $slice): array
    {
        $caps = BranchHealthConfig::get('caps');
        $reasons = [];
        $ceiling = 100;

        $firewalls = $this->firewallInventory($slice);
        if ($firewalls->isNotEmpty() && $firewalls->every(fn ($f) => $f['status'] === 'down')) {
            $ceiling = min($ceiling, (int) $caps['all_firewalls_down']);
            $reasons[] = [
                'key' => 'all_firewalls_down',
                'cap' => (int) $caps['all_firewalls_down'],
                'message' => 'Every configured branch firewall is unreachable ('
                    .$firewalls->pluck('label')->implode(', ').').',
            ];
        }

        if ($slice->criticalAlerts->isNotEmpty()) {
            $ceiling = min($ceiling, (int) $caps['critical_firewall_alert']);
            $reasons[] = [
                'key' => 'critical_firewall_alert',
                'cap' => (int) $caps['critical_firewall_alert'],
                'message' => $slice->criticalAlerts->count().' open critical firewall/Sophos alert'
                    .($slice->criticalAlerts->count() === 1 ? '' : 's')
                    .': '.$slice->criticalAlerts->take(3)->pluck('title')->implode('; ').'.',
            ];
        }

        return [max(0, min($rounded, $ceiling)), $reasons];
    }

    private function status(int $normalized, float $evaluable, array $capReasons): string
    {
        // Too little measurable to make a claim. Normalizing by coverage keeps a
        // branch from being punished for kit it has not onboarded, but taken to
        // the extreme it would call a branch with one passing check "Excellent"
        // — which is precisely the "green because telemetry is missing" failure
        // this whole scoring model exists to prevent. Below the floor we say
        // unknown, and the coverage figure beside it explains why.
        if ($evaluable < (float) BranchHealthConfig::get('min_coverage_for_status', 50)) {
            return 'unknown';
        }

        $t = BranchHealthConfig::get('status_thresholds');

        return match (true) {
            // A capped branch is never "healthy" no matter what it scored: the
            // cap exists because something is on fire.
            $normalized >= (int) $t['healthy'] && $capReasons === [] => 'healthy',
            $normalized >= (int) $t['degraded'] => 'degraded',
            $normalized >= (int) $t['at_risk'] => 'at_risk',
            default => 'critical',
        };
    }

    // ─────────────────────────────────────────────────────────────
    // VoIP
    // ─────────────────────────────────────────────────────────────

    private function ucmReachable(BranchSlice $slice): array
    {
        $server = $slice->ucmServer;

        if (! $server) {
            return $this->unknown('ucm_reachable', 'UCM Reachable',
                'No UCM is assigned to this branch.', route('admin.noc.dashboard'));
        }

        // Read from the durable columns SyncUcmExtensionsJob stamps every 15s.
        // Never a live API call — the dashboard must not dial seven PBXs to render.
        if (! $server->healthIsFresh()) {
            return $this->unknown('ucm_reachable', 'UCM Reachable',
                $server->name.' has not reported in — last seen '
                .($server->last_health_at?->diffForHumans() ?? 'never').'.',
                route('admin.noc.dashboard'), 1);
        }

        $ok = (bool) $server->last_health_ok;

        return $this->result(
            'ucm_reachable', 'UCM Reachable',
            $ok ? 1 : 0, 1, 0,
            $ok ? $server->name.' is responding.' : $server->name.' is not responding.',
            route('admin.noc.dashboard'),
            $server->last_health_at,
            // Scrubbed again on the way out. SyncUcmExtensionsJob sanitizes before
            // storing, but a row written before that existed would otherwise put
            // a credential-bearing API error straight on the dashboard.
            $ok ? [] : [['label' => $server->name, 'detail' => SecretScrubber::scrub($server->last_health_error) ?: 'Unreachable']]
        );
    }

    private function ucmTrunks(BranchSlice $slice): array
    {
        $url = route('admin.noc.dashboard');

        if (! $slice->ucmServer || $slice->trunks->isEmpty()) {
            return $this->unknown('ucm_trunks', 'UCM Trunks Reachable',
                'No trunks are cached for this branch UCM.', $url);
        }

        $fresh = $slice->trunks->filter(fn ($t) => $t->isFresh());
        $stale = $slice->trunks->count() - $fresh->count();

        // A trunk whose status we cannot currently read counts against coverage,
        // not against health — but it never counts as passing either.
        $passing = $fresh->filter(fn ($t) => $t->isReachable());
        $unknown = $stale + $fresh->filter(fn ($t) => $t->isUnknownStatus())->count();

        $failing = $fresh->filter(fn ($t) => $t->isFailed())
            ->map(fn ($t) => ['label' => $t->trunk_name, 'detail' => $t->normalizedStatus() ?: 'unknown'])
            ->values()->all();

        return $this->result(
            'ucm_trunks', 'UCM Trunks Reachable',
            $passing->count(), $slice->trunks->count(), $unknown,
            $passing->count().' of '.$slice->trunks->count().' trunks are reachable'
                .($stale > 0 ? " ({$stale} stale)" : '').'.',
            $url,
            $slice->trunks->max('last_checked_at'),
            $failing
        );
    }

    private function voiceMesh(BranchSlice $slice): array
    {
        $url = route('admin.network.voice-mesh.index');

        if (! $slice->meshNode) {
            return $this->unknown('voice_mesh', 'Voice Mesh Calls Complete',
                'This branch has no active voice mesh node.', $url);
        }

        // The denominator is every OTHER active branch: the requirement is that
        // this branch can reach all of its peers.
        $expected = $slice->meshDestinations->count();

        if ($expected === 0) {
            return $this->unknown('voice_mesh', 'Voice Mesh Calls Complete',
                'No other active branches to call.', $url);
        }

        $byDest = $slice->meshPairs->keyBy('dest_node_id');
        $passing = 0;
        $unknown = 0;
        $failing = [];

        foreach ($slice->meshDestinations as $dest) {
            $pair = $byDest->get($dest->id);

            // A leg that was never probed, or whose last probe has gone stale,
            // is unknown. It is emphatically not a pass.
            if (! $pair || $pair->isStale()) {
                $unknown++;
                $failing[] = [
                    'label' => $dest->name ?: $dest->code,
                    'detail' => $pair ? 'Stale — last checked '.($pair->last_checked_at?->diffForHumans() ?? 'never') : 'Never probed',
                ];

                continue;
            }

            // RTP actually arriving is the difference between "the call was set
            // up" and "the call carried audio".
            if ($pair->status === 'ok' && (int) $pair->last_rx_pkt > 0) {
                $passing++;

                continue;
            }

            // When the prober called the leg OK but no packets arrived, the stored
            // reason still reads "OK" — report why WE are failing it, not what the
            // prober thought, or the drill-down explains nothing.
            $failing[] = [
                'label' => $dest->name ?: $dest->code,
                'detail' => $pair->status === 'ok'
                    ? 'Connected but no RTP received'
                    : ($pair->last_reason ?: $pair->status),
            ];
        }

        return $this->result(
            'voice_mesh', 'Voice Mesh Calls Complete',
            $passing, $expected, $unknown,
            $passing.' of '.$expected.' branch-to-branch calls completed with audio.',
            $url,
            $slice->meshPairs->max('last_checked_at'),
            $failing
        );
    }

    private function mosCompliance(BranchSlice $slice): array
    {
        $url = route('admin.voice-quality.index');
        $cfg = BranchHealthConfig::get('mos');
        $mos = $slice->mos;

        if (($mos['samples'] ?? 0) === 0) {
            return $this->unknown('mos_compliance', 'Call Quality (MOS-LQ >= '.$cfg['threshold'].')',
                'No call quality reports for this branch in the last '.$cfg['extended_window_days'].' days.', $url);
        }

        $check = $this->result(
            'mos_compliance', 'Call Quality (MOS-LQ >= '.$cfg['threshold'].')',
            (int) $mos['passing'], (int) $mos['samples'], 0,
            $mos['passing'].' of '.$mos['samples'].' calls met MOS-LQ '.$cfg['threshold']
                .' (avg '.($mos['avg_mos'] ?? 'n/a').', last '.$mos['window_label'].').',
            $url
        );

        $check['avg_mos'] = $mos['avg_mos'];
        $check['window'] = $mos['window_label'];

        return $check;
    }

    // ─────────────────────────────────────────────────────────────
    // Network
    // ─────────────────────────────────────────────────────────────

    /**
     * One row per physical firewall, deduplicated by IP.
     *
     * A branch firewall can be known to us twice — as a SophosFirewall we sync
     * config from, and as a BranchTunnel the watchdog pings. Scoring both would
     * double-count the same box.
     *
     * A degraded tunnel still means the firewall itself answers; the missing
     * carried subnet is reported separately rather than failing this check.
     * That distinction is the JED case: the gateway replied for a month while
     * an entire subnet behind it was dark.
     */
    private function firewallInventory(BranchSlice $slice): Collection
    {
        $tunnelWindow = BranchHealthConfig::int('freshness.branch_tunnel', 3);
        $hostWindow = BranchHealthConfig::int('freshness.monitored_host', 3);
        $rows = collect();

        foreach ($slice->firewalls as $fw) {
            $tunnel = $slice->tunnels->first(fn ($t) => filled($t->firewall_ip) && $t->firewall_ip === $fw->ip);
            $rows->push($this->firewallRow($fw->name ?: $fw->ip, $fw->ip, $tunnel, $fw->monitoredHost, $tunnelWindow, $hostWindow));
        }

        $seen = $rows->pluck('ip')->filter()->all();

        foreach ($slice->tunnels as $tunnel) {
            if (filled($tunnel->firewall_ip) && in_array($tunnel->firewall_ip, $seen, true)) {
                continue;
            }
            $rows->push($this->firewallRow($tunnel->name ?: $tunnel->firewall_ip, $tunnel->firewall_ip, $tunnel, null, $tunnelWindow, $hostWindow));
        }

        return $rows->unique(fn ($r) => $r['ip'] ?: $r['label'])->values();
    }

    private function firewallRow(string $label, ?string $ip, $tunnel, $host, int $tunnelWindow, int $hostWindow): array
    {
        // Prefer the watchdog: it probes this box every minute, which is fresher
        // and more direct than the generic host ping.
        $tunnelFresh = $tunnel && $tunnel->last_ping_at
            && $tunnel->last_ping_at->gte(now()->subMinutes($tunnelWindow));

        if ($tunnelFresh) {
            $degraded = $tunnel->state === 'degraded';
            $down = $tunnel->state === 'down';

            $downProbes = $degraded && $tunnel->relationLoaded('activeProbes')
                ? $tunnel->activeProbes->where('status', 'down')->pluck('label')->filter()->implode(', ')
                : '';

            return [
                'label' => $label,
                'ip' => $ip,
                'status' => $down ? 'down' : 'up',
                'detail' => match (true) {
                    $down => 'Unreachable',
                    $degraded => 'Reachable, but tunnel degraded'.($downProbes !== '' ? " ({$downProbes} unreachable)" : ''),
                    default => 'Reachable',
                },
                'degraded' => $degraded,
                'observed_at' => $tunnel->last_ping_at,
            ];
        }

        $hostFresh = $host && $host->last_ping_at
            && $host->last_ping_at->gte(now()->subMinutes($hostWindow));

        if ($hostFresh) {
            return [
                'label' => $label,
                'ip' => $ip,
                'status' => $host->status === 'up' ? 'up' : 'down',
                'detail' => $host->status === 'up' ? 'Reachable' : 'Unreachable',
                'degraded' => false,
                'observed_at' => $host->last_ping_at,
            ];
        }

        return [
            'label' => $label,
            'ip' => $ip,
            'status' => 'unknown',
            'detail' => 'No recent reachability check',
            'degraded' => false,
            'observed_at' => $tunnel?->last_ping_at ?? $host?->last_ping_at,
        ];
    }

    private function firewallReachable(BranchSlice $slice): array
    {
        $url = route('admin.network.tunnel-health.index');
        $rows = $this->firewallInventory($slice);

        if ($rows->isEmpty()) {
            return $this->unknown('firewall_reachable', 'Branch Firewalls Reachable',
                'No firewall or branch tunnel is configured for this branch.', $url);
        }

        $passing = $rows->where('status', 'up');
        $unknown = $rows->where('status', 'unknown')->count();
        $degraded = $passing->where('degraded', true)->count();

        $failing = $rows->whereIn('status', ['down', 'unknown'])
            ->map(fn ($r) => ['label' => $r['label'], 'detail' => $r['detail']])
            ->values()->all();

        // Degraded firewalls pass, so surface them explicitly — otherwise a
        // branch with a dark subnet reads as entirely healthy here.
        foreach ($passing->where('degraded', true) as $r) {
            $failing[] = ['label' => $r['label'], 'detail' => $r['detail'], 'severity' => 'warning'];
        }

        return $this->result(
            'firewall_reachable', 'Branch Firewalls Reachable',
            $passing->count(), $rows->count(), $unknown,
            $passing->count().' of '.$rows->count().' firewalls are reachable'
                .($degraded > 0 ? " ({$degraded} with a degraded tunnel)" : '').'.',
            $url,
            $rows->pluck('observed_at')->filter()->max(),
            $failing
        );
    }

    private function gatewayReachable(BranchSlice $slice): array
    {
        $url = route('admin.network.isp.index');

        if ($slice->ispConnections->isEmpty()) {
            return $this->unknown('gateway_reachable', 'ISP Gateways Reachable',
                'No ISP connections are configured for this branch.', $url);
        }

        $passing = 0;
        $unknown = 0;
        $failing = [];
        $latest = null;

        foreach ($slice->ispConnections as $isp) {
            // Same target SlaMonitorService pings, so the score judges exactly
            // what was measured.
            $target = $isp->gateway ?: $isp->static_ip;
            $label = ($isp->provider ?: 'ISP').($target ? " ({$target})" : '');
            $check = $slice->linkChecks->get($isp->id);

            if (! $check) {
                $unknown++;
                $failing[] = ['label' => $label, 'detail' => 'No recent link check'];

                continue;
            }

            $latest = $latest === null || $check->checked_at > $latest ? $check->checked_at : $latest;

            if ($check->success) {
                $passing++;

                continue;
            }

            $failing[] = ['label' => $label, 'detail' => 'Link check failed'];
        }

        return $this->result(
            'gateway_reachable', 'ISP Gateways Reachable',
            $passing, $slice->ispConnections->count(), $unknown,
            $passing.' of '.$slice->ispConnections->count().' ISP gateways are responding.',
            $url, $latest, $failing
        );
    }

    private function switchReachability(BranchSlice $slice): array
    {
        $url = route('admin.network.switches');

        if ($slice->switches->isEmpty()) {
            return $this->unknown('switch_reachability', 'Switches Reachable',
                'No switches are registered as assets for this branch.', $url);
        }

        $hostWindow = BranchHealthConfig::int('freshness.monitored_host', 3);
        $passing = 0;
        $unknown = 0;
        $failing = [];
        $latest = null;

        foreach ($slice->switches as $device) {
            [$state, $detail, $observedAt] = $this->switchState($device, $slice, $hostWindow);

            if ($observedAt && ($latest === null || $observedAt > $latest)) {
                $latest = $observedAt;
            }

            if ($state === 'up') {
                $passing++;

                continue;
            }

            if ($state === 'unknown') {
                $unknown++;
            }

            $failing[] = ['label' => $device->name ?: ($device->ip_address ?: 'Switch #'.$device->id), 'detail' => $detail];
        }

        return $this->result(
            'switch_reachability', 'Switches Reachable',
            $passing, $slice->switches->count(), $unknown,
            $passing.' of '.$slice->switches->count().' switches are reachable.',
            $url, $latest, $failing
        );
    }

    /**
     * Freshest wins.
     *
     * NetworkController::switches() rolls this up as `$meraki?->status ?? $snmp`,
     * so ANY Meraki value shadows SNMP — a switch reporting `online` from a sync
     * that died days ago outranks a live ping saying it is down. Here the two
     * sources are compared by observation time and the newer one is believed;
     * if neither is fresh the switch is unknown, not up.
     */
    private function switchState(Device $device, BranchSlice $slice, int $hostWindow): array
    {
        $meraki = $slice->merakiByDeviceId->get($device->id);
        $host = $slice->hostsByDeviceId->get($device->id)
            ?? (filled($device->ip_address) ? $slice->hostsByIp->get($device->ip_address) : null);

        $merakiAt = $meraki && $meraki->last_reported_at
            && $meraki->last_reported_at->gte(now()->subMinutes($slice->merakiStaleMinutes))
                ? $meraki->last_reported_at : null;

        $hostAt = $host && $host->last_ping_at
            && $host->last_ping_at->gte(now()->subMinutes($hostWindow))
                ? $host->last_ping_at : null;

        if ($merakiAt === null && $hostAt === null) {
            return ['unknown', $this->staleDetail($meraki?->last_reported_at, $host?->last_ping_at), $meraki?->last_reported_at ?? $host?->last_ping_at];
        }

        $useMeraki = $hostAt === null || ($merakiAt !== null && $merakiAt->gte($hostAt));

        if ($useMeraki) {
            $status = strtolower((string) $meraki->status);

            return [
                $status === 'online' ? 'up' : ($status === 'unknown' ? 'unknown' : 'down'),
                'Meraki reports '.($status ?: 'unknown'),
                $merakiAt,
            ];
        }

        return [
            $host->status === 'up' ? 'up' : ($host->status === 'down' ? 'down' : 'unknown'),
            'Ping reports '.($host->status ?: 'unknown'),
            $hostAt,
        ];
    }

    private function staleDetail(?\Illuminate\Support\Carbon $merakiAt, ?\Illuminate\Support\Carbon $hostAt): string
    {
        $newest = collect([$merakiAt, $hostAt])->filter()->max();

        return $newest
            ? 'No fresh data — last seen '.$newest->diffForHumans()
            : 'Not monitored';
    }

    private function accessPointReachability(BranchSlice $slice): array
    {
        $url = route('admin.network.access-points.index');

        if ($slice->accessPoints->isEmpty()) {
            return $this->unknown('access_point_reachability', 'Access Points Reachable',
                'No monitored access points are linked to this branch.', $url);
        }

        $window = BranchHealthConfig::int('freshness.access_point', 15);
        $passing = 0;
        $unknown = 0;
        $failing = [];

        foreach ($slice->accessPoints as $ap) {
            $fresh = $ap->last_ping_at && $ap->last_ping_at->gte(now()->subMinutes($window));

            if (! $fresh) {
                $unknown++;
                $failing[] = [
                    'label' => $ap->name ?: $ap->ip_address,
                    'detail' => 'No recent ping — last attempt '.($ap->last_ping_at?->diffForHumans() ?? 'never'),
                ];

                continue;
            }

            if ($ap->status === 'up') {
                $passing++;

                continue;
            }

            $failing[] = ['label' => $ap->name ?: $ap->ip_address, 'detail' => 'Not responding to ping'];
        }

        return $this->result(
            'access_point_reachability', 'Access Points Reachable',
            $passing, $slice->accessPoints->count(), $unknown,
            $passing.' of '.$slice->accessPoints->count().' access points are reachable.',
            $url,
            $slice->accessPoints->max('last_ping_at'),
            $failing
        );
    }

    /**
     * Unlike the others this is a boolean condition, not a ratio — so it is
     * always evaluable and never `unknown`. "No open critical alerts" is a
     * meaningful pass even for a branch with no firewall configured.
     */
    private function firewallAlerts(BranchSlice $slice): array
    {
        $url = route('admin.noc.alerts');
        $open = $slice->criticalAlerts;

        $failing = $open->map(fn ($e) => [
            'label' => $e->title,
            'detail' => ucfirst($e->status).' since '.($e->last_seen?->diffForHumans() ?? 'unknown'),
        ])->values()->all();

        return $this->result(
            'firewall_alerts', 'No Critical Firewall Alerts',
            $open->isEmpty() ? 1 : 0, 1, 0,
            $open->isEmpty()
                ? 'No open critical firewall or Sophos alerts.'
                : $open->count().' open critical firewall/Sophos alert'.($open->count() === 1 ? '' : 's').'.',
            $url,
            $open->max('last_seen'),
            $failing
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Devices
    // ─────────────────────────────────────────────────────────────

    private function printerReachability(BranchSlice $slice): array
    {
        $url = route('admin.printers.index');

        if ($slice->printers->isEmpty()) {
            return $this->unknown('printer_reachability', 'Printers Reachable',
                'No printers are registered for this branch.', $url);
        }

        $window = BranchHealthConfig::int('freshness.monitored_host', 3);
        $passing = 0;
        $unknown = 0;
        $failing = [];
        $latest = null;

        foreach ($slice->printers as $printer) {
            // CUPS queue state says whether the queue accepts jobs, which is a
            // different question from whether the hardware answers. Physical
            // reachability comes from the ping monitor only.
            $host = $this->hostFor($printer, $slice);
            $label = $printer->printer_name ?: ($printer->ip_address ?: 'Printer #'.$printer->id);

            if (! $host) {
                $unknown++;
                $failing[] = ['label' => $label, 'detail' => 'Not linked to a monitored host'];

                continue;
            }

            $fresh = $host->last_ping_at && $host->last_ping_at->gte(now()->subMinutes($window));

            if ($fresh && ($latest === null || $host->last_ping_at > $latest)) {
                $latest = $host->last_ping_at;
            }

            if (! $fresh) {
                $unknown++;
                $failing[] = ['label' => $label, 'detail' => 'No recent ping — last '.($host->last_ping_at?->diffForHumans() ?? 'never')];

                continue;
            }

            if ($host->status === 'up') {
                $passing++;

                continue;
            }

            $failing[] = ['label' => $label, 'detail' => 'Not responding to ping'];
        }

        return $this->result(
            'printer_reachability', 'Printers Reachable',
            $passing, $slice->printers->count(), $unknown,
            $passing.' of '.$slice->printers->count().' printers are reachable.',
            $url, $latest, $failing
        );
    }

    private function hostFor(Printer $printer, BranchSlice $slice)
    {
        return ($printer->device_id ? $slice->hostsByDeviceId->get($printer->device_id) : null)
            ?? (filled($printer->ip_address) ? $slice->hostsByIp->get($printer->ip_address) : null);
    }

    /**
     * Biometric terminals ride the existing generic ping monitor — there is no
     * biometric-specific collector, and there does not need to be. An asset with
     * no MonitoredHost is a configuration gap, reported as such rather than
     * silently passing or silently failing.
     */
    private function biometricReachability(BranchSlice $slice): array
    {
        $url = route('admin.devices.index', ['type' => 'biometric']);

        if ($slice->biometrics->isEmpty()) {
            return $this->unknown('biometric_reachability', 'Biometric Devices Reachable',
                'No biometric devices are registered for this branch.', $url);
        }

        $window = BranchHealthConfig::int('freshness.monitored_host', 3);
        $passing = 0;
        $unknown = 0;
        $failing = [];
        $latest = null;

        foreach ($slice->biometrics as $device) {
            $host = $slice->hostsByDeviceId->get($device->id)
                ?? (filled($device->ip_address) ? $slice->hostsByIp->get($device->ip_address) : null);
            $label = $device->name ?: ($device->ip_address ?: 'Biometric #'.$device->id);

            if (! $host) {
                $unknown++;
                $failing[] = [
                    'label' => $label,
                    'detail' => 'Not monitored — add this asset to Monitored Hosts to include it in the score',
                    'action' => 'configuration',
                ];

                continue;
            }

            $fresh = $host->last_ping_at && $host->last_ping_at->gte(now()->subMinutes($window));

            if ($fresh && ($latest === null || $host->last_ping_at > $latest)) {
                $latest = $host->last_ping_at;
            }

            if (! $fresh) {
                $unknown++;
                $failing[] = ['label' => $label, 'detail' => 'No recent ping — last '.($host->last_ping_at?->diffForHumans() ?? 'never')];

                continue;
            }

            if ($host->status === 'up') {
                $passing++;

                continue;
            }

            $failing[] = ['label' => $label, 'detail' => 'Not responding to ping'];
        }

        return $this->result(
            'biometric_reachability', 'Biometric Devices Reachable',
            $passing, $slice->biometrics->count(), $unknown,
            $passing.' of '.$slice->biometrics->count().' biometric devices are reachable.',
            $url, $latest, $failing
        );
    }

    private function printerToner(BranchSlice $slice): array
    {
        $url = route('admin.printers.index');

        if ($slice->printers->isEmpty()) {
            return $this->unknown('printer_toner', 'Printer Toner Above 30%',
                'No printers are registered for this branch.', $url);
        }

        $min = BranchHealthConfig::int('toner.min_percent', 30);
        $passing = 0;
        $unknown = 0;
        $failing = [];

        foreach ($slice->printers as $printer) {
            $label = $printer->printer_name ?: ($printer->ip_address ?: 'Printer #'.$printer->id);
            $levels = $this->tonerLevels($printer, $slice);

            if ($levels === []) {
                $unknown++;
                $failing[] = ['label' => $label, 'detail' => 'No fresh toner reading'];

                continue;
            }

            if (max($levels) > $min) {
                $passing++;

                continue;
            }

            $failing[] = ['label' => $label, 'detail' => 'All toner at or below '.$min.'% (highest '.max($levels).'%)'];
        }

        return $this->result(
            'printer_toner', 'Printer Toner Above 30%',
            $passing, $slice->printers->count(), $unknown,
            $passing.' of '.$slice->printers->count().' printers have a toner above '.$min.'%.',
            $url,
            $slice->tonerByPrinter->flatten(1)->max('last_updated_at'),
            $failing
        );
    }

    /**
     * Known toner percentages for one printer.
     *
     * Normalized printer_supplies rows are the source of truth. The legacy
     * printers.toner_* columns are a fallback only, and must be filtered to >= 0
     * first: they use -1 (unknown), -2 (not applicable) and -3 (some remaining)
     * as sentinels, so a naive comparison would read every unknown cartridge as
     * critically empty.
     *
     * @return array<int, int>
     */
    private function tonerLevels(Printer $printer, BranchSlice $slice): array
    {
        $supplies = $slice->tonerByPrinter->get($printer->id);

        if ($supplies && $supplies->isNotEmpty()) {
            return $supplies->pluck('supply_percent')
                ->filter(fn ($v) => $v !== null && $v >= 0)
                ->map(fn ($v) => (int) $v)
                ->values()->all();
        }

        $window = BranchHealthConfig::int('freshness.printer_supply', 30);
        $polledFresh = $printer->snmp_last_polled_at
            && $printer->snmp_last_polled_at->gte(now()->subMinutes($window));

        if (! $polledFresh) {
            return [];
        }

        return collect([$printer->toner_black, $printer->toner_cyan, $printer->toner_magenta, $printer->toner_yellow])
            ->filter(fn ($v) => $v !== null && $v >= 0)
            ->map(fn ($v) => (int) $v)
            ->values()->all();
    }

    // ─────────────────────────────────────────────────────────────
    // Result shaping
    // ─────────────────────────────────────────────────────────────

    private function maxPoints(string $key): int
    {
        foreach (BranchHealthConfig::get('weights') as $checks) {
            if (isset($checks[$key])) {
                return (int) $checks[$key];
            }
        }

        return 0;
    }

    /**
     * @param  int  $passing  items confirmed healthy
     * @param  int  $total  items expected — configured inventory, including unmonitored ones
     * @param  int  $unknown  subset of $total we could not assess
     * @param  array  $failures  [['label' =>, 'detail' =>], ...] naming what is affected
     */
    private function result(
        string $key,
        string $label,
        int $passing,
        int $total,
        int $unknown,
        string $message,
        string $url,
        $lastUpdatedAt = null,
        array $failures = []
    ): array {
        $max = $this->maxPoints($key);
        $ratio = $total > 0 ? $passing / $total : 0.0;

        return [
            'key' => $key,
            'label' => $label,
            'points' => round($max * $ratio, 2),
            'max_points' => $max,
            'ratio' => round($ratio, 4),
            'status' => match (true) {
                // Nothing configured, or nothing we could actually read: either
                // way we measured nothing. Reporting `fail` here would make a
                // branch whose collectors died look identical to one whose
                // switches died, and would count the check as covered when it
                // was not.
                $total === 0, $unknown === $total => 'unknown',
                $passing === $total => 'pass',
                $passing === 0 => 'fail',
                default => 'degraded',
            },
            'passing' => $passing,
            'total' => $total,
            'unknown' => $unknown,
            'message' => $message,
            'failures' => $failures,
            'last_updated_at' => $lastUpdatedAt?->toIso8601String(),
            'portal_url' => $url,
        ];
    }

    /** Nothing to measure: no points, and no coverage claimed either. */
    private function unknown(string $key, string $label, string $message, string $url, int $unknownCount = 0): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'points' => 0.0,
            'max_points' => $this->maxPoints($key),
            'ratio' => 0.0,
            'status' => 'unknown',
            'passing' => 0,
            'total' => 0,
            'unknown' => $unknownCount,
            'message' => $message,
            'failures' => [],
            'last_updated_at' => null,
            'portal_url' => $url,
        ];
    }
}
