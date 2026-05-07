<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchLogCollector;
use App\Models\SnmpDevice;
use App\Models\SnmpDiscoveredDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Endpoints branches *pull* from / *post to*:
 *
 *   GET  /api/branch-config/snmp-devices       returns this branch's device list
 *   POST /api/branch-config/discovered-devices branch reports nmap findings
 *
 * Authentication: same Bearer token the branch uses for its own log API
 * (stored on the BranchLogCollector record). Token is encrypted at rest
 * via the model cast — to authenticate we iterate over branches and
 * compare. Trivially fast for 9 branches; if the estate ever grows past
 * ~100 branches, add an api_token_hash column for O(1) lookup.
 */
class BranchConfigController extends Controller
{
    /**
     * GET /api/branch-config/snmp-devices
     * Returns:
     *   {
     *     "ok": true,
     *     "branch": {"code":"kbr", "name":"Khobar"},
     *     "devices": [
     *       {
     *         "name":"JED Sophos","host":"10.3.0.1",
     *         "snmp_version":"2c","snmp_community":"public","snmp_port":161,
     *         "device_type":"sophos_xgs","polling_interval_s":60
     *       }, ...
     *     ]
     *   }
     */
    public function snmpDevices(Request $request): JsonResponse
    {
        $branch = $this->resolveBranch($request);
        if (!$branch) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $devices = SnmpDevice::where('branch_log_collector_id', $branch->id)
            ->where('enabled', true)
            ->get()
            ->map(fn (SnmpDevice $d) => [
                'name'               => $d->name,
                'host'               => $d->host,
                'snmp_version'       => $d->snmp_version,
                'snmp_community'     => $d->snmp_community,    // decrypted by cast
                'snmp_port'          => $d->snmp_port,
                'device_type'        => $d->device_type,
                'polling_interval_s' => $d->polling_interval_s,
            ])
            ->values();

        return response()->json([
            'ok'      => true,
            'branch'  => ['code' => $branch->code, 'name' => $branch->name],
            'devices' => $devices,
            'count'   => $devices->count(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * POST /api/branch-config/discovered-devices
     * Body:
     *   { "devices": [
     *       {"host":"10.3.0.55","sys_descr":"Sophos ...","sys_name":"jed-fw",
     *        "mac":"aa:bb:cc:dd:ee:ff","snmp_responding":true},
     *       ...
     *     ] }
     *
     * Branch posts the full set each scan — server upserts by (branch, host),
     * incrementing seen_count + last_seen_at on existing rows.
     */
    public function postDiscovered(Request $request): JsonResponse
    {
        $branch = $this->resolveBranch($request);
        if (!$branch) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $payload = $request->validate([
            'devices'                       => ['required', 'array', 'max:500'],
            'devices.*.host'                => ['required', 'string', 'max:255'],
            'devices.*.mac'                 => ['nullable', 'string', 'max:32'],
            'devices.*.sys_descr'           => ['nullable', 'string', 'max:2000'],
            'devices.*.sys_name'            => ['nullable', 'string', 'max:128'],
            'devices.*.snmp_responding'     => ['nullable', 'boolean'],
        ]);

        $now      = now();
        $stats    = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($payload['devices'] as $d) {
            // Skip if this host already exists as a managed SnmpDevice — it's
            // not "discovered", it's an existing config. Avoids duplicate
            // suggestions.
            $alreadyManaged = SnmpDevice::where('branch_log_collector_id', $branch->id)
                ->where('host', $d['host'])
                ->exists();
            if ($alreadyManaged) {
                $stats['skipped']++;
                continue;
            }

            $existing = SnmpDiscoveredDevice::where('branch_log_collector_id', $branch->id)
                ->where('host', $d['host'])
                ->first();

            $sysDescr = $d['sys_descr'] ?? null;
            $suggested = SnmpDiscoveredDevice::guessTypeFromSysDescr($sysDescr);

            if ($existing) {
                // Re-suggest only if it had been rejected > 30 days ago.
                if ($existing->status === 'rejected'
                    && $existing->updated_at?->lt(Carbon::now()->subDays(30))) {
                    $existing->status = 'pending';
                }
                $existing->fill([
                    'mac'             => $d['mac']             ?? $existing->mac,
                    'sys_descr'       => $sysDescr             ?? $existing->sys_descr,
                    'sys_name'        => $d['sys_name']        ?? $existing->sys_name,
                    'suggested_type'  => $suggested            ?? $existing->suggested_type,
                    'snmp_responding' => (bool) ($d['snmp_responding'] ?? $existing->snmp_responding),
                    'last_seen_at'    => $now,
                    'seen_count'      => $existing->seen_count + 1,
                ]);
                $existing->save();
                $stats['updated']++;
            } else {
                SnmpDiscoveredDevice::create([
                    'branch_log_collector_id' => $branch->id,
                    'host'             => $d['host'],
                    'mac'              => $d['mac']             ?? null,
                    'sys_descr'        => $sysDescr,
                    'sys_name'         => $d['sys_name']        ?? null,
                    'suggested_type'   => $suggested,
                    'snmp_responding'  => (bool) ($d['snmp_responding'] ?? false),
                    'status'           => 'pending',
                    'first_seen_at'    => $now,
                    'last_seen_at'     => $now,
                    'seen_count'       => 1,
                ]);
                $stats['inserted']++;
            }
        }

        return response()->json([
            'ok'     => true,
            'branch' => $branch->code,
            'stats'  => $stats,
        ]);
    }

    /**
     * Match the request's Bearer token against branch_log_collectors.
     * Returns the matching collector or null. O(N=branches) — fine until
     * the estate gets big.
     */
    private function resolveBranch(Request $request): ?BranchLogCollector
    {
        $bearer = $request->bearerToken();
        if (!$bearer) return null;

        foreach (BranchLogCollector::ready()->get() as $collector) {
            // The api_token cast decrypts on access
            if (hash_equals((string) $collector->api_token, $bearer)) {
                return $collector;
            }
        }
        return null;
    }
}
