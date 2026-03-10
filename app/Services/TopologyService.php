<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Device;
use App\Models\IspConnection;
use App\Models\MonitoredHost;
use App\Models\NetworkSwitch;
use App\Models\SophosFirewall;
use App\Models\VpnTunnel;

class TopologyService
{
    /**
     * Build a Cytoscape.js-compatible graph of the network topology.
     *
     * Returns: ['nodes' => [...], 'edges' => [...]]
     */
    public function buildGraph(?int $branchId = null): array
    {
        $nodes = [];
        $edges = [];

        // ── Branches as top-level nodes ──────────────────────────
        $branchQuery = Branch::query();
        if ($branchId) {
            $branchQuery->where('id', $branchId);
        }
        $branches = $branchQuery->get();

        foreach ($branches as $branch) {
            $nodes[] = [
                'data' => [
                    'id'    => "branch_{$branch->id}",
                    'label' => $branch->name,
                    'type'  => 'branch',
                    'icon'  => 'bi-building',
                ],
            ];
        }

        // ── VPN Tunnels ──────────────────────────────────────────
        $vpnQuery = VpnTunnel::query();
        if ($branchId) {
            $vpnQuery->where('branch_id', $branchId);
        }
        $vpnTunnels = $vpnQuery->get();

        foreach ($vpnTunnels as $vpn) {
            $nodes[] = [
                'data' => [
                    'id'     => "vpn_{$vpn->id}",
                    'label'  => $vpn->name,
                    'type'   => 'vpn',
                    'status' => $vpn->status,
                    'icon'   => 'bi-shield-lock',
                    'parent' => $vpn->branch_id ? "branch_{$vpn->branch_id}" : null,
                ],
            ];
            if ($vpn->branch_id) {
                $edges[] = [
                    'data' => [
                        'id'     => "e_branch_{$vpn->branch_id}_vpn_{$vpn->id}",
                        'source' => "branch_{$vpn->branch_id}",
                        'target' => "vpn_{$vpn->id}",
                        'type'   => 'vpn',
                    ],
                ];
            }
        }

        // ── Network Switches ─────────────────────────────────────
        $switchQuery = NetworkSwitch::query();
        if ($branchId) {
            $switchQuery->where('branch_id', $branchId);
        }
        $switches = $switchQuery->get();

        foreach ($switches as $sw) {
            $nodes[] = [
                'data' => [
                    'id'     => "switch_{$sw->serial}",
                    'label'  => $sw->name,
                    'type'   => 'switch',
                    'status' => $sw->status,
                    'model'  => $sw->model,
                    'icon'   => 'bi-hdd-network',
                    'parent' => $sw->branch_id ? "branch_{$sw->branch_id}" : null,
                ],
            ];
            if ($sw->branch_id) {
                $edges[] = [
                    'data' => [
                        'id'     => "e_branch_{$sw->branch_id}_sw_{$sw->serial}",
                        'source' => "branch_{$sw->branch_id}",
                        'target' => "switch_{$sw->serial}",
                        'type'   => 'network',
                    ],
                ];
            }
        }

        // ── ISP Connections ──────────────────────────────────────
        $ispQuery = IspConnection::query();
        if ($branchId) {
            $ispQuery->where('branch_id', $branchId);
        }
        $isps = $ispQuery->get();

        foreach ($isps as $isp) {
            $nodes[] = [
                'data' => [
                    'id'     => "isp_{$isp->id}",
                    'label'  => $isp->provider . ($isp->circuit_id ? " ({$isp->circuit_id})" : ''),
                    'type'   => 'isp',
                    'icon'   => 'bi-globe2',
                    'parent' => $isp->branch_id ? "branch_{$isp->branch_id}" : null,
                ],
            ];
            if ($isp->branch_id) {
                $edges[] = [
                    'data' => [
                        'id'     => "e_branch_{$isp->branch_id}_isp_{$isp->id}",
                        'source' => "branch_{$isp->branch_id}",
                        'target' => "isp_{$isp->id}",
                        'type'   => 'isp',
                    ],
                ];
            }
        }

        // ── Monitored Hosts ──────────────────────────────────────
        $hostQuery = MonitoredHost::query();
        if ($branchId) {
            $hostQuery->where('branch_id', $branchId);
        }
        $hosts = $hostQuery->get();

        foreach ($hosts as $host) {
            $nodes[] = [
                'data' => [
                    'id'     => "host_{$host->id}",
                    'label'  => $host->name,
                    'type'   => 'host',
                    'status' => $host->status,
                    'ip'     => $host->ip,
                    'icon'   => 'bi-pc-display',
                    'parent' => $host->branch_id ? "branch_{$host->branch_id}" : null,
                ],
            ];
            if ($host->branch_id) {
                $edges[] = [
                    'data' => [
                        'id'     => "e_branch_{$host->branch_id}_host_{$host->id}",
                        'source' => "branch_{$host->branch_id}",
                        'target' => "host_{$host->id}",
                        'type'   => 'host',
                    ],
                ];
            }
        }

        // ── Sophos Firewalls ────────────────────────────────────────
        $fwQuery = SophosFirewall::query();
        if ($branchId) {
            $fwQuery->where('branch_id', $branchId);
        }
        $sophosFirewalls = $fwQuery->get();

        foreach ($sophosFirewalls as $fw) {
            $nodes[] = [
                'data' => [
                    'id'     => "sophos_{$fw->id}",
                    'label'  => $fw->name,
                    'type'   => 'firewall',
                    'status' => $fw->last_synced_at ? 'up' : 'unknown',
                    'model'  => $fw->model,
                    'icon'   => 'bi-shield-fill',
                    'parent' => $fw->branch_id ? "branch_{$fw->branch_id}" : null,
                ],
            ];
            if ($fw->branch_id) {
                $edges[] = [
                    'data' => [
                        'id'     => "e_branch_{$fw->branch_id}_sophos_{$fw->id}",
                        'source' => "branch_{$fw->branch_id}",
                        'target' => "sophos_{$fw->id}",
                        'type'   => 'firewall',
                    ],
                ];
            }
        }

        // ── Key Devices (routers, firewalls, servers) ────────────
        $deviceQuery = Device::whereIn('type', ['router', 'firewall', 'server', 'access_point']);
        if ($branchId) {
            $deviceQuery->where('branch_id', $branchId);
        }
        $keyDevices = $deviceQuery->get();

        foreach ($keyDevices as $dev) {
            $nodes[] = [
                'data' => [
                    'id'     => "device_{$dev->id}",
                    'label'  => $dev->name,
                    'type'   => 'device',
                    'subtype' => $dev->type,
                    'icon'   => 'bi-cpu',
                    'parent' => $dev->branch_id ? "branch_{$dev->branch_id}" : null,
                ],
            ];
            if ($dev->branch_id) {
                $edges[] = [
                    'data' => [
                        'id'     => "e_branch_{$dev->branch_id}_dev_{$dev->id}",
                        'source' => "branch_{$dev->branch_id}",
                        'target' => "device_{$dev->id}",
                        'type'   => 'device',
                    ],
                ];
            }
        }

        return compact('nodes', 'edges');
    }
}
