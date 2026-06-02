<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UcmServer;
use App\Services\GdmsService;
use App\Services\IppbxApiService;

class GdmsController extends Controller
{
    /**
     * Show live status for every configured UCM server by querying each
     * UCM's own HTTPS API directly (/api — challenge → login → status queries).
     */
    public function ucmIndex()
    {
        $servers = UcmServer::active()->orderBy('name')->get();

        $results = [];

        foreach ($servers as $server) {
            $item = [
                'server' => $server,
                'wave_domain' => $server->cloud_domain ?? null,
                'gdms' => null,
                'online' => false,
                'error' => null,
                'system' => [],
                'general' => [],
                'mac' => null,
                'extensions' => [],
                'trunks' => [],
                'resources' => [],
                'summary' => ['total' => 0, 'idle' => 0, 'inuse' => 0, 'unavailable' => 0, 'other' => 0],
                'trunk_summary' => ['total' => 0, 'reachable' => 0, 'unreachable' => 0],
            ];

            $stats = IppbxApiService::getCachedStats($server);

            if (! $stats['online']) {
                $item['error'] = $stats['error'] ?? 'Offline';
            } else {
                $item['online'] = true;
                $item['system'] = $stats['system'] ?? [];
                $item['general'] = $stats['general'] ?? [];
                if (! empty($stats['uptime'])) {
                    $item['system']['up-time-formatted'] = $stats['uptime'];
                }
                $item['mac'] = $stats['mac'] ?? '';
                $item['extensions'] = $stats['extensions_list'] ?? [];
                $item['trunks'] = $stats['trunks_list'] ?? [];
                $item['resources'] = $stats['resources'] ?? [];
                $item['summary'] = $stats['extensions'] ?? ['total' => 0, 'idle' => 0, 'inuse' => 0, 'unavailable' => 0, 'other' => 0];
                $item['trunk_summary'] = $stats['trunk_counts'] ?? ['total' => 0, 'reachable' => 0, 'unreachable' => 0];
            }

            $results[] = $item;
        }

        // ── GDMS cloud view (single list call; non-fatal) ────────────────
        // Adds GDMS's own online state per UCM (projectId=3) plus the GDMS SIP
        // servers (the RemoteConnect servers Wave registers against), alongside
        // the direct-query / SNMP data above.
        $gdmsByMac = [];
        $sipServers = [];
        $gdmsError = null;
        try {
            $gdms = app(GdmsService::class);
            foreach ($gdms->listOnPremisePbx() as $u) {
                $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $u['mac'] ?? ''));
                if ($mac !== '') {
                    $gdmsByMac[$mac] = $u;
                }
            }
            $sipServers = $gdms->listSipServers();
        } catch (\Throwable $e) {
            $gdmsError = $e->getMessage();
        }

        // Attach the matching GDMS record to each UCM by MAC.
        foreach ($results as &$item) {
            $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) ($item['mac'] ?? '')));
            $item['gdms'] = $mac !== '' ? ($gdmsByMac[$mac] ?? null) : null;
        }
        unset($item);

        return view('admin.gdms.ucm', compact('results', 'sipServers', 'gdmsError'));
    }
}
