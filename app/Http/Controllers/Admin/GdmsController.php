<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UcmServer;
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
                'server'     => $server,
                'online'     => false,
                'error'      => null,
                'system'     => [],
                'general'    => [],
                'mac'        => null,
                'extensions' => [],
                'trunks'     => [],
                'summary'    => ['total' => 0, 'idle' => 0, 'inuse' => 0, 'unavailable' => 0, 'other' => 0],
                'trunk_summary' => ['total' => 0, 'reachable' => 0, 'unreachable' => 0],
            ];

            $stats = IppbxApiService::getCachedStats($server);

            if (!$stats['online']) {
                $item['error'] = $stats['error'] ?? 'Offline';
            } else {
                $item['online']        = true;
                $item['system']        = $stats['system'] ?? [];
                $item['general']       = $stats['general'] ?? [];
                if (!empty($stats['uptime'])) {
                     $item['system']['up-time-formatted'] = $stats['uptime'];
                }
                $item['mac']           = $stats['mac'] ?? '';
                $item['extensions']    = $stats['extensions_list'] ?? [];
                $item['trunks']        = $stats['trunks_list'] ?? [];
                $item['summary']       = $stats['extensions'] ?? ['total' => 0, 'idle' => 0, 'inuse' => 0, 'unavailable' => 0, 'other' => 0];
                $item['trunk_summary'] = $stats['trunk_counts'] ?? ['total' => 0, 'reachable' => 0, 'unreachable' => 0];
            }

            $results[] = $item;
        }

        return view('admin.gdms.ucm', compact('results'));
    }
}
