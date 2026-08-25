<?php

namespace App\Services\BranchHealth;

use App\Models\Branch;
use Illuminate\Support\Collection;

/**
 * Everything the evaluator is allowed to know about one branch.
 *
 * Deliberately a typed object rather than a loose array. Every check treats a
 * missing source as `unknown`, so if the loader silently failed to populate a
 * key an array would degrade into a plausible-looking zero and nobody would
 * notice. Here a missing source is a TypeError at construction instead.
 *
 * All values are already resolved and attributed. The evaluator runs no queries
 * and performs no cross-branch lookups — anything that needed the whole estate
 * (the voice mesh denominator, MOS extension mapping) was reduced by the loader
 * before it got here.
 */
class BranchSlice
{
    public function __construct(
        public readonly Branch $branch,

        // ── VoIP ───────────────────────────────────────────────────
        /** The branch's UCM, via branches.ucm_server_id. */
        public readonly ?\App\Models\UcmServer $ucmServer,
        /** @var Collection<int, \App\Models\UcmTrunkCache> */
        public readonly Collection $trunks,
        /** The branch's active voice mesh node, if it has exactly one. */
        public readonly ?\App\Models\VoiceMeshNode $meshNode,
        /** @var Collection<int, \App\Models\VoiceMeshPair> outgoing pairs only */
        public readonly Collection $meshPairs,
        /** Every OTHER active node — the mesh denominator. @var Collection<int, \App\Models\VoiceMeshNode> */
        public readonly Collection $meshDestinations,
        /** Pre-aggregated: sample_count, passing, avg_mos, window_label. */
        public readonly array $mos,

        // ── Network ────────────────────────────────────────────────
        /** @var Collection<int, \App\Models\SophosFirewall> */
        public readonly Collection $firewalls,
        /** @var Collection<int, \App\Models\BranchTunnel> */
        public readonly Collection $tunnels,
        /** @var Collection<int, \App\Models\IspConnection> */
        public readonly Collection $ispConnections,
        /** Latest fresh LinkCheck per isp id. @var Collection<int, \App\Models\LinkCheck> */
        public readonly Collection $linkChecks,
        /** Device rows of type `switch`. @var Collection<int, \App\Models\Device> */
        public readonly Collection $switches,
        /** @var Collection<int, \App\Models\AccessPoint> */
        public readonly Collection $accessPoints,
        /** Open/acknowledged critical firewall+Sophos events. @var Collection<int, \App\Models\NocEvent> */
        public readonly Collection $criticalAlerts,

        // ── Devices ────────────────────────────────────────────────
        /** @var Collection<int, \App\Models\Printer> */
        public readonly Collection $printers,
        /** Device rows of type `biometric`. @var Collection<int, \App\Models\Device> */
        public readonly Collection $biometrics,
        /** Fresh toner supplies grouped by printer id. @var Collection<int, Collection> */
        public readonly Collection $tonerByPrinter,

        // ── Shared lookups ─────────────────────────────────────────
        /** MonitoredHost indexed by device_id. @var Collection<int, \App\Models\MonitoredHost> */
        public readonly Collection $hostsByDeviceId,
        /** MonitoredHost indexed by ip. @var Collection<string, \App\Models\MonitoredHost> */
        public readonly Collection $hostsByIp,
        /** NetworkSwitch indexed by device_id. @var Collection<int, \App\Models\NetworkSwitch> */
        public readonly Collection $merakiByDeviceId,

        /** Staleness ceiling for Meraki rows, derived from the polling setting. */
        public readonly int $merakiStaleMinutes,
    ) {}
}
