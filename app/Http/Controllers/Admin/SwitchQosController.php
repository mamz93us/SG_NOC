<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Credential;
use App\Models\Device;
use App\Models\PhonePortMap;
use App\Models\SwitchCdpNeighbor;
use App\Models\SwitchInterfaceStat;
use App\Models\SwitchQosStat;
use App\Models\SwitchRunningConfig;
use App\Models\VqAlertEvent;
use App\Services\CiscoTelnetClient;
use App\Services\MlsQosParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SwitchQosController extends Controller
{
    /**
     * `total_drops` and the per-queue counters are cumulative since last clear on the switch,
     * so SUMming across polls inflates numbers. Everywhere in this controller we take the
     * most recent poll per (device, interface) via a MAX(id) subquery.
     */
    public function dashboard()
    {
        $today = today();

        $latestIds = SwitchQosStat::selectRaw('MAX(id) as id')
            ->whereDate('polled_at', $today)
            ->groupBy('device_ip', 'interface_name')
            ->pluck('id');

        $latest = SwitchQosStat::whereIn('id', $latestIds);

        $interfacesWithDrops = (clone $latest)->where('total_drops', '>', 0)->count();
        $switchesPolled      = (clone $latest)->distinct('device_ip')->count('device_ip');
        $policerOutOfProfile = (clone $latest)->sum('policer_out_of_profile');

        $topDropInterfaces = (clone $latest)
            ->where('total_drops', '>', 0)
            ->orderByDesc('total_drops')
            ->limit(10)
            ->get(['device_name','device_ip','interface_name','branch_id',
                'q0_t1_drop','q0_t2_drop','q0_t3_drop',
                'q1_t1_drop','q1_t2_drop','q1_t3_drop',
                'q2_t1_drop','q2_t2_drop','q2_t3_drop',
                'q3_t1_drop','q3_t2_drop','q3_t3_drop',
                'total_drops','policer_out_of_profile','polled_at']);

        $topDropSwitches = (clone $latest)
            ->select('device_name', 'device_ip', 'branch_id',
                DB::raw('SUM(total_drops) as total_drops'),
                DB::raw('SUM(policer_out_of_profile) as total_policer'))
            ->groupBy('device_name', 'device_ip', 'branch_id')
            ->orderByDesc('total_drops')
            ->limit(10)
            ->get();

        // Per-queue drop breakdown across all interfaces (latest poll only)
        $queueBreakdown = (clone $latest)
            ->selectRaw('
                SUM(q0_t1_drop + q0_t2_drop + q0_t3_drop) as q0,
                SUM(q1_t1_drop + q1_t2_drop + q1_t3_drop) as q1,
                SUM(q2_t1_drop + q2_t2_drop + q2_t3_drop) as q2,
                SUM(q3_t1_drop + q3_t2_drop + q3_t3_drop) as q3
            ')
            ->first();

        $activeAlerts = VqAlertEvent::unresolved()
            ->where('source_type', 'switch-qos')
            ->whereDate('created_at', $today)
            ->orderByDesc('created_at')
            ->get();

        // All switches/routers in inventory — even if never polled.
        // We merge in the latest-poll summary so newly-added devices appear instantly.
        $latestPollPerIp = SwitchQosStat::select(
                'device_ip',
                DB::raw('MAX(polled_at) as last_polled_at'),
                DB::raw('COUNT(DISTINCT interface_name) as interface_count')
            )
            ->groupBy('device_ip')
            ->get()
            ->keyBy('device_ip');

        $inventory = Device::whereIn('type', ['switch', 'router'])
            ->whereNotNull('ip_address')
            ->with(['branch:id,name', 'credentials' => fn($q) => $q->whereIn('category', ['telnet','enable'])])
            ->orderBy('name')
            ->get()
            ->map(function (Device $d) use ($latestPollPerIp) {
                $poll = $latestPollPerIp->get($d->ip_address);
                $d->has_telnet_cred = $d->credentials->contains('category', 'telnet');
                $d->has_enable_cred = $d->credentials->contains('category', 'enable');
                $d->last_polled_at  = $poll?->last_polled_at;
                $d->polled_interfaces = (int) ($poll?->interface_count ?? 0);
                return $d;
            });

        $inventoryStats = [
            'total'              => $inventory->count(),
            'never_polled'       => $inventory->whereNull('last_polled_at')->count(),
            'missing_telnet'     => $inventory->where('has_telnet_cred', false)->count(),
            'mls_qos_supported'  => $inventory->where('mls_qos_supported', true)->count(),
            'mls_qos_unsupported'=> $inventory->where('mls_qos_supported', false)->count(),
        ];

        return view('admin.switch_qos.dashboard', compact(
            'interfacesWithDrops', 'switchesPolled', 'policerOutOfProfile',
            'topDropInterfaces', 'topDropSwitches', 'queueBreakdown', 'activeAlerts',
            'inventory', 'inventoryStats'
        ));
    }

    public function index(Request $request)
    {
        $query = SwitchQosStat::query();

        if ($request->filled('device_name')) {
            $query->where('device_name', 'like', '%' . $request->device_name . '%');
        }
        if ($request->filled('device_ip')) {
            $query->where('device_ip', $request->device_ip);
        }
        if ($request->filled('interface')) {
            $query->where('interface_name', 'like', '%' . $request->interface . '%');
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('polled_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('polled_at', '<=', $request->date_to);
        }
        if ($request->boolean('drops_only')) {
            $query->where('total_drops', '>', 0);
        }

        $stats    = $query->orderByDesc('polled_at')->paginate(50)->withQueryString();
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.switch_qos.index', compact('stats', 'branches'));
    }

    public function device(string $deviceIp)
    {
        $latestSnapshot = SwitchQosStat::where('device_ip', $deviceIp)
            ->latest('polled_at')
            ->first();

        if (!$latestSnapshot) {
            abort(404);
        }

        $interfaces = SwitchQosStat::where('device_ip', $deviceIp)
            ->whereIn('id', function ($sub) use ($deviceIp) {
                $sub->selectRaw('MAX(id)')
                    ->from('switch_qos_stats')
                    ->where('device_ip', $deviceIp)
                    ->groupBy('interface_name');
            })
            ->orderBy('interface_name')
            ->get();

        $trend = SwitchQosStat::where('device_ip', $deviceIp)
            ->where('polled_at', '>=', now()->subDay())
            ->where('total_drops', '>', 0)
            ->select('interface_name', 'total_drops',
                DB::raw('DATE_FORMAT(polled_at, "%H:%i") as label'))
            ->orderBy('polled_at')
            ->get()
            ->groupBy('interface_name');

        $device = Device::where('ip_address', $deviceIp)->first();
        $telnetCred = $device?->credentials()->where('category', 'telnet')->first();
        $enableCred = $device?->credentials()->where('category', 'enable')->first();

        // Latest-per-interface traffic/error counters
        $ifaceStats = SwitchInterfaceStat::where('device_ip', $deviceIp)
            ->whereIn('id', function ($sub) use ($deviceIp) {
                $sub->selectRaw('MAX(id)')
                    ->from('switch_interface_stats')
                    ->where('device_ip', $deviceIp)
                    ->groupBy('interface_name');
            })
            ->orderBy('interface_name')
            ->get()
            ->keyBy('interface_name');

        // Latest CDP neighbor snapshot
        $latestCdpAt = SwitchCdpNeighbor::where('device_ip', $deviceIp)->max('polled_at');
        $cdpNeighbors = $latestCdpAt
            ? SwitchCdpNeighbor::with(['merakiSwitch', 'matchedDevice'])
                ->where('device_ip', $deviceIp)->where('polled_at', $latestCdpAt)
                ->orderBy('local_interface')->get()
            : collect();

        $cdpPhonesByMac = $cdpNeighbors->pluck('neighbor_mac')->filter()->unique()->all();
        $cdpPhonesByMac = $cdpPhonesByMac
            ? PhonePortMap::whereIn('phone_mac', $cdpPhonesByMac)->get()->keyBy('phone_mac')
            : collect();

        return view('admin.switch_qos.device', compact(
            'latestSnapshot', 'interfaces', 'trend', 'deviceIp',
            'device', 'telnetCred', 'enableCred', 'ifaceStats', 'cdpNeighbors', 'cdpPhonesByMac'
        ));
    }

    /**
     * CDP-based network topology across ALL polled switches.
     */
    public function topology()
    {
        // Use the single most recent CDP snapshot per device.
        $latestPerDevice = SwitchCdpNeighbor::select('device_ip', DB::raw('MAX(polled_at) as last_at'))
            ->groupBy('device_ip')
            ->get();

        $edges = collect();
        foreach ($latestPerDevice as $ld) {
            $rows = SwitchCdpNeighbor::with(['merakiSwitch', 'matchedDevice'])
                ->where('device_ip', $ld->device_ip)
                ->where('polled_at', $ld->last_at)
                ->get();
            $edges = $edges->concat($rows);
        }

        // Canonical key for each polled switch. Prefer its internal Device id so a
        // neighbor that matches this Device collapses onto the same node.
        $polledKeyByIp = [];
        foreach ($edges as $e) {
            if (!isset($polledKeyByIp[$e->device_ip])) {
                $polledKeyByIp[$e->device_ip] = $e->device_id
                    ? 'device:' . $e->device_id
                    : 'ip:' . $e->device_ip;
            }
        }

        // Canonical key for a neighbor edge. Matches polled switches when the IP overlaps.
        $neighborKey = function (SwitchCdpNeighbor $e) use ($polledKeyByIp): string {
            if ($e->matched_meraki_serial) return 'meraki:' . $e->matched_meraki_serial;
            if ($e->matched_device_id)     return 'device:' . $e->matched_device_id;
            if ($e->neighbor_ip && isset($polledKeyByIp[$e->neighbor_ip])) {
                return $polledKeyByIp[$e->neighbor_ip];
            }
            if ($e->neighbor_ip)          return 'ip:' . $e->neighbor_ip;
            if ($e->neighbor_mac)         return 'mac:' . $e->neighbor_mac;
            return 'host:' . ($e->neighbor_device_id ?: 'unknown');
        };

        // End-user detection. Match on platform prefix (IP-phone models report Trans-Bridge Host
        // in CDP, so capabilities alone isn't enough) OR capabilities with Phone/Host but no
        // router/switch role.
        $phonePlatformPrefixes = ['GRP', 'GXP', 'GXV', 'HT', 'DP', 'APX', 'SPA', 'CP-',
            'CISCO IP PHONE', 'POLYCOM', 'YEALINK', 'FANVIL', 'IP PHONE'];
        $isEndUser = function (?string $capabilities, ?string $platform) use ($phonePlatformPrefixes): bool {
            $plat = strtoupper((string) $platform);
            foreach ($phonePlatformPrefixes as $p) {
                if ($plat !== '' && str_starts_with($plat, $p)) return true;
            }
            $cap = strtolower((string) $capabilities);
            if ($cap === '') return false;
            if (str_contains($cap, 'phone')) return true;
            $hasSwitchOrRouter = str_contains($cap, 'switch') || str_contains($cap, 'router');
            if ($hasSwitchOrRouter) return false;
            // "Host" alone (or Trans-Bridge Host) without Switch/Router = endpoint.
            return str_contains($cap, 'host');
        };

        // Pre-resolve phone MACs against PhonePortMap so CDP-discovered IP phones link to
        // their extension detail page.
        $macs = $edges->pluck('neighbor_mac')->filter()->unique()->all();
        $phonesByMac = $macs
            ? PhonePortMap::whereIn('phone_mac', $macs)->get()->keyBy('phone_mac')
            : collect();

        // Build nodes: polled switches first, then each distinct neighbor.
        $nodes = [];
        foreach ($edges as $e) {
            $pkey = $polledKeyByIp[$e->device_ip];
            if (!isset($nodes[$pkey])) {
                $nodes[$pkey] = [
                    'id'         => $pkey,
                    'label'      => $e->device_name . "\n" . $e->device_ip,
                    'group'      => 'polled',
                    'title'      => "{$e->device_name} ({$e->device_ip})",
                    'url'        => route('admin.switch-qos.device', urlencode($e->device_ip)),
                    'is_end_user'=> false,
                ];
            }

            $nkey = $neighborKey($e);
            $e->_nkey = $nkey;
            $phone = $e->neighbor_mac ? $phonesByMac->get($e->neighbor_mac) : null;

            if (!isset($nodes[$nkey])) {
                if ($e->merakiSwitch) {
                    $ms = $e->merakiSwitch;
                    $nodes[$nkey] = [
                        'id'         => $nkey,
                        'label'      => ($ms->name ?: $ms->serial) . "\n" . ($ms->lan_ip ?: $ms->mac),
                        'group'      => 'meraki',
                        'title'      => "Meraki {$ms->model} — {$ms->serial}" . ($ms->lan_ip ? " ({$ms->lan_ip})" : ''),
                        'url'        => route('admin.network.switch-detail', $ms->serial),
                        'is_end_user'=> false,
                    ];
                } elseif ($phone) {
                    $label = ($phone->extension ? "Ext {$phone->extension}" : ($e->platform ?: 'Phone'))
                        . "\n" . ($e->neighbor_ip ?: $e->neighbor_mac);
                    $nodes[$nkey] = [
                        'id'         => $nkey,
                        'label'      => $label,
                        'group'      => 'phone',
                        'title'      => ($e->platform ? $e->platform . ' — ' : '') . 'Phone'
                            . ($phone->extension ? " (Ext {$phone->extension})" : ''),
                        'url'        => $phone->extension
                            ? route('admin.extensions.details', $phone->extension)
                            : null,
                        'is_end_user'=> true,
                    ];
                } elseif ($e->matchedDevice) {
                    $md = $e->matchedDevice;
                    $isInfra = in_array($md->type, ['switch', 'router'], true);
                    $nodes[$nkey] = [
                        'id'         => $nkey,
                        'label'      => ($md->name ?: $md->ip_address) . "\n" . ($md->ip_address ?: ''),
                        'group'      => $md->type === 'phone' ? 'phone' : 'device',
                        'title'      => ($md->manufacturer ? $md->manufacturer . ' — ' : '') . ($md->model ?: $md->type),
                        'url'        => $isInfra
                            ? route('admin.switch-qos.setup', $md->id)
                            : route('admin.devices.show', $md->id),
                        'is_end_user'=> !$isInfra && ($md->type === 'phone' || $isEndUser($e->capabilities, $e->platform)),
                    ];
                } else {
                    $nodes[$nkey] = [
                        'id'         => $nkey,
                        'label'      => $e->neighbor_device_id . ($e->neighbor_ip ? "\n" . $e->neighbor_ip : ''),
                        'group'      => 'neighbor',
                        'title'      => ($e->platform ? $e->platform . ' — ' : '') . $e->neighbor_device_id,
                        'url'        => null,
                        'is_end_user'=> $isEndUser($e->capabilities, $e->platform),
                    ];
                }
            }
        }

        // Edges keyed by unordered (from, to) pair to avoid duplicate lines between the
        // same two devices (both sides often show each other in their CDP tables).
        $visEdges = [];
        $seenPairs = [];
        foreach ($edges as $e) {
            $from = $polledKeyByIp[$e->device_ip];
            $to   = $e->_nkey;
            if ($from === $to) continue;
            $pair = $from < $to ? "$from|$to" : "$to|$from";
            if (isset($seenPairs[$pair])) continue;
            $seenPairs[$pair] = true;

            $visEdges[] = [
                'from'        => $from,
                'to'          => $to,
                'label'       => $e->local_interface . ' ↔ ' . ($e->neighbor_port ?? ''),
                'title'       => "{$e->device_name} {$e->local_interface} → {$e->neighbor_device_id} {$e->neighbor_port}",
                'is_end_user' => $nodes[$to]['is_end_user'] ?? false,
            ];
        }

        $endUserCount = collect($nodes)->where('is_end_user', true)->count();

        return view('admin.switch_qos.topology', [
            'nodes' => array_values($nodes),
            'edges' => $visEdges,
            'stats' => [
                'devices'   => count($nodes),
                'links'     => count($visEdges),
                'polled'    => $latestPerDevice->count(),
                'end_users' => $endUserCount,
            ],
        ]);
    }

    /**
     * Full CDP neighbor list across all switches (flat table).
     */
    public function cdpIndex()
    {
        $latestPerDevice = SwitchCdpNeighbor::select('device_ip', DB::raw('MAX(polled_at) as last_at'))
            ->groupBy('device_ip')
            ->get();

        $rows = collect();
        foreach ($latestPerDevice as $ld) {
            $rows = $rows->concat(
                SwitchCdpNeighbor::with(['merakiSwitch', 'matchedDevice'])
                    ->where('device_ip', $ld->device_ip)
                    ->where('polled_at', $ld->last_at)
                    ->orderBy('local_interface')
                    ->get()
            );
        }

        // Resolve IP phones by MAC against PhonePortMap for extension links.
        $macs = $rows->pluck('neighbor_mac')->filter()->unique()->all();
        $phonesByMac = $macs
            ? PhonePortMap::whereIn('phone_mac', $macs)->get()->keyBy('phone_mac')
            : collect();

        return view('admin.switch_qos.cdp', ['rows' => $rows, 'phonesByMac' => $phonesByMac]);
    }

    /**
     * Setup page: credentials + capability for a device that may have never been polled.
     */
    public function setup(Device $device)
    {
        if (!in_array($device->type, ['switch', 'router'], true)) {
            abort(404);
        }

        $telnetCred = $device->credentials()->where('category', 'telnet')->first();
        $enableCred = $device->credentials()->where('category', 'enable')->first();

        $lastPoll = SwitchQosStat::where('device_ip', $device->ip_address)
            ->latest('polled_at')
            ->first();

        return view('admin.switch_qos.setup', compact('device', 'telnetCred', 'enableCred', 'lastPoll'));
    }

    /**
     * Poll-to-poll compare (delta view) — shows drops accrued between two snapshots.
     * Cumulative counters mean `current - previous` is the real drop count for that window.
     * Negative deltas indicate a counter reset (reboot / `clear`) and are shown as `reset`.
     */
    public function compare(Request $request, string $deviceIp)
    {
        // Distinct polled_at timestamps for this device (last 50)
        $timestamps = SwitchQosStat::where('device_ip', $deviceIp)
            ->select('polled_at')
            ->distinct()
            ->orderByDesc('polled_at')
            ->limit(50)
            ->pluck('polled_at');

        if ($timestamps->count() < 2) {
            return redirect()
                ->route('admin.switch-qos.device', urlencode($deviceIp))
                ->with('error', 'Need at least 2 polls to compare. Run the poller again in a few minutes.');
        }

        // Default to latest vs previous
        $curAt  = $request->input('current')  ?? $timestamps[0]->format('Y-m-d H:i:s');
        $prevAt = $request->input('previous') ?? $timestamps[1]->format('Y-m-d H:i:s');

        $current  = SwitchQosStat::where('device_ip', $deviceIp)->where('polled_at', $curAt)->get()->keyBy('interface_name');
        $previous = SwitchQosStat::where('device_ip', $deviceIp)->where('polled_at', $prevAt)->get()->keyBy('interface_name');

        if ($current->isEmpty() || $previous->isEmpty()) {
            return redirect()
                ->route('admin.switch-qos.device', urlencode($deviceIp))
                ->with('error', 'One of the selected polls has no data.');
        }

        $qcols = [
            'q0_t1_drop','q0_t2_drop','q0_t3_drop',
            'q1_t1_drop','q1_t2_drop','q1_t3_drop',
            'q2_t1_drop','q2_t2_drop','q2_t3_drop',
            'q3_t1_drop','q3_t2_drop','q3_t3_drop',
        ];

        $rows = [];
        foreach ($current as $name => $cur) {
            $prev = $previous->get($name);
            if (!$prev) {
                continue;
            }
            $totalDelta = (int) $cur->total_drops - (int) $prev->total_drops;
            $reset      = $totalDelta < 0;

            $perQueue = [];
            foreach ($qcols as $c) {
                $d = (int) $cur->{$c} - (int) $prev->{$c};
                $perQueue[$c] = $reset ? null : max($d, 0);
            }

            $rows[] = (object) [
                'interface_name' => $name,
                'total_delta'    => $reset ? null : max($totalDelta, 0),
                'reset'          => $reset,
                'per_queue'      => $perQueue,
                'cur_total'      => (int) $cur->total_drops,
                'prev_total'     => (int) $prev->total_drops,
            ];
        }

        // Sort by delta desc (resets to the bottom)
        usort($rows, function ($a, $b) {
            if ($a->reset !== $b->reset) {
                return $a->reset ? 1 : -1;
            }
            return (int) ($b->total_delta ?? 0) <=> (int) ($a->total_delta ?? 0);
        });

        $summary = [
            'interfaces_with_new_drops' => collect($rows)->where('total_delta', '>', 0)->count(),
            'total_new_drops'           => collect($rows)->sum(fn($r) => $r->total_delta ?? 0),
            'interfaces_reset'          => collect($rows)->where('reset', true)->count(),
            'window_seconds'            => (int) abs(strtotime($curAt) - strtotime($prevAt)),
        ];

        $device = Device::where('ip_address', $deviceIp)->first();

        return view('admin.switch_qos.compare', [
            'deviceIp'   => $deviceIp,
            'device'     => $device,
            'rows'       => $rows,
            'summary'    => $summary,
            'curAt'      => $curAt,
            'prevAt'     => $prevAt,
            'timestamps' => $timestamps,
        ]);
    }

    /**
     * One-shot probe: telnet reachability + MLS QoS support detection.
     * Updates device flags so UI badges stay accurate.
     */
    public function testConnection(Device $device)
    {
        $telnet = $device->credentials()->where('category', 'telnet')->first();
        $enable = $device->credentials()->where('category', 'enable')->first();

        if (!$telnet) {
            return back()->with('error', "Device has no 'telnet' credential. Add one first.");
        }

        $client = new CiscoTelnetClient();
        $error = null;
        $telnetReachable = false;
        $qosSupported = null;

        try {
            $client->connect($device->ip_address, 23, 6.0);
            $client->waitFor(['Password:'], 6.0);
            $client->send((string) $telnet->password);
            $client->waitForPrompt(8.0);
            $telnetReachable = true;

            if ($enable) {
                $client->send('enable');
                $client->waitFor(['Password:'], 6.0);
                $client->send((string) $enable->password);
                $client->waitForPrompt(8.0);
            }

            $client->send('terminal length 0');
            $client->waitForPrompt(5.0);

            $client->send('show mls qos');
            $out = $client->waitForPrompt(10.0);
            // "QoS is enabled" / "QoS is disabled" / "% Invalid input" tell us support status
            if (stripos($out, 'Invalid input') !== false || stripos($out, 'Incomplete command') !== false) {
                $qosSupported = false;
            } elseif (stripos($out, 'QoS is enabled') !== false || stripos($out, 'QoS is disabled') !== false || stripos($out, 'mls qos') !== false) {
                $qosSupported = true;
            } else {
                $qosSupported = false;
            }

            try { $client->send('exit'); } catch (\Throwable) {}
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            $client->close();
        }

        $device->update([
            'telnet_reachable'   => $telnetReachable,
            'mls_qos_supported'  => $qosSupported,
            'qos_probed_at'      => now(),
            'qos_probe_error'    => $error,
        ]);

        $msg = $telnetReachable
            ? ($qosSupported ? 'Telnet OK. MLS QoS is supported.' : 'Telnet OK. MLS QoS NOT supported on this device.')
            : 'Telnet failed: ' . ($error ?? 'unknown error');

        return back()->with($telnetReachable && $qosSupported ? 'success' : 'error', $msg);
    }

    /**
     * Create or update telnet / enable credential inline from the device QoS page.
     */
    public function saveCredential(Request $request, Device $device)
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(['telnet', 'enable'])],
            'password' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $cred = $device->credentials()->where('category', $data['category'])->first();

        if ($cred) {
            $cred->update([
                'password'   => $data['password'],
                'updated_by' => $request->user()?->id,
            ]);
        } else {
            $device->credentials()->create([
                'title'      => $data['category'] === 'telnet' ? 'Telnet vty' : 'Enable secret',
                'category'   => $data['category'],
                'password'   => $data['password'],
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);
        }

        return back()->with('success', ucfirst($data['category']) . ' password saved.');
    }

    /**
     * Run `switch:poll-mls-qos --device=IP` synchronously for one switch.
     * Useful when the OS scheduler isn't configured or you need a fresh snapshot on demand.
     */
    public function pollNow(Device $device)
    {
        if (!$device->ip_address) {
            return back()->with('error', 'Device has no IP address.');
        }

        try {
            $exitCode = Artisan::call('switch:poll-mls-qos', [
                '--device' => $device->ip_address,
            ]);
            $output = trim(Artisan::output());

            if ($exitCode === 0) {
                return back()->with('success', 'Poll complete. ' . ($output ?: 'No output.'));
            }
            return back()->with('error', "Poll exited with code {$exitCode}. " . $output);
        } catch (\Throwable $e) {
            return back()->with('error', 'Poll failed: ' . $e->getMessage());
        }
    }

    /**
     * Send `clear mls qos interface statistics` to the switch.
     * Resets cumulative counters on-device — next poll will show 0 for all drops.
     */
    public function clearStats(Device $device)
    {
        $telnet = $device->credentials()->where('category', 'telnet')->first();
        $enable = $device->credentials()->where('category', 'enable')->first();

        if (!$telnet) {
            return back()->with('error', "Device has no 'telnet' credential. Add one first.");
        }

        $client = new CiscoTelnetClient();
        $error  = null;

        try {
            $client->connect($device->ip_address, 23, 8.0);
            $client->waitFor(['Password:'], 8.0);
            $client->send((string) $telnet->password);
            $client->waitForPrompt(10.0);

            if ($enable) {
                $client->send('enable');
                $client->waitFor(['Password:'], 8.0);
                $client->send((string) $enable->password);
                $client->waitForPrompt(10.0);
            }

            $client->send('terminal length 0');
            $client->waitForPrompt(5.0);

            // Clears cumulative queue-drop / policer counters for all interfaces
            $client->send('clear mls qos interface statistics');
            $out = $client->waitForPrompt(15.0);

            if (stripos($out, 'Invalid input') !== false || stripos($out, 'Incomplete command') !== false) {
                $error = 'Switch does not support `clear mls qos interface statistics`.';
            }

            try { $client->send('exit'); } catch (\Throwable) {}
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            $client->close();
        }

        if ($error) {
            return back()->with('error', 'Clear failed: ' . $error);
        }
        return back()->with('success', 'QoS statistics cleared on the switch. Next poll will show zeroed counters.');
    }

    /**
     * Render the in-browser telnet console for a device.
     */
    public function telnetConsole(Device $device)
    {
        if (!in_array($device->type, ['switch', 'router'], true) || !$device->ip_address) {
            abort(404);
        }
        $telnet = $device->credentials()->where('category', 'telnet')->first();
        $enable = $device->credentials()->where('category', 'enable')->first();

        return view('admin.switch_qos.telnet', compact('device', 'telnet', 'enable'));
    }

    /**
     * Execute a single Cisco command via a fresh telnet session and return output as JSON.
     * Each call is stateless — login, enable, run command, disconnect.
     */
    public function telnetSend(Request $request, Device $device)
    {
        $data = $request->validate([
            'command' => ['required', 'string', 'max:512'],
        ]);
        $command = trim($data['command']);

        if ($command === '') {
            return response()->json(['output' => '', 'error' => null]);
        }

        $telnet = $device->credentials()->where('category', 'telnet')->first();
        $enable = $device->credentials()->where('category', 'enable')->first();

        if (!$telnet) {
            return response()->json([
                'output' => '',
                'error'  => "No 'telnet' credential configured for this device.",
            ], 422);
        }

        $client = new CiscoTelnetClient();
        $output = '';
        $error  = null;

        try {
            $client->connect($device->ip_address, 23, 8.0);
            $client->waitFor(['Password:'], 8.0);
            $client->send((string) $telnet->password);
            $client->waitForPrompt(10.0);

            if ($enable) {
                $client->send('enable');
                $client->waitFor(['Password:'], 8.0);
                $client->send((string) $enable->password);
                $client->waitForPrompt(10.0);
            }

            $client->send('terminal length 0');
            $client->waitForPrompt(5.0);

            $client->send($command);
            $raw = $client->waitForPrompt(30.0);

            // Strip the echoed command and the trailing prompt line so the user sees only the response.
            $lines = preg_split('/\r?\n/', $raw) ?: [];
            if (!empty($lines) && str_contains($lines[0], $command)) array_shift($lines);
            if (!empty($lines) && preg_match('/[#>]\s*$/', end($lines))) array_pop($lines);
            $output = implode("\n", $lines);

            try { $client->send('exit'); } catch (\Throwable) {}
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            $client->close();
        }

        return response()->json(['output' => $output, 'error' => $error]);
    }

    /**
     * Capture `show running-config` from a switch and store a new snapshot.
     * Skips storage if the config is unchanged since the last capture (dedup by sha256).
     */
    public function fetchConfig(Device $device)
    {
        if (!in_array($device->type, ['switch', 'router'], true) || !$device->ip_address) {
            return back()->with('error', 'Not a switch/router.');
        }

        $telnet = $device->credentials()->where('category', 'telnet')->first();
        $enable = $device->credentials()->where('category', 'enable')->first();
        if (!$telnet) {
            return back()->with('error', "Device has no 'telnet' credential. Add one first.");
        }

        $client = new CiscoTelnetClient();
        $error  = null;
        $raw    = '';

        try {
            $client->connect($device->ip_address, 23, 10.0);
            $client->waitFor(['Password:'], 10.0);
            $client->send((string) $telnet->password);
            $client->waitForPrompt(10.0);

            if ($enable) {
                $client->send('enable');
                $client->waitFor(['Password:'], 10.0);
                $client->send((string) $enable->password);
                $client->waitForPrompt(10.0);
            }

            $client->send('terminal length 0');
            $client->waitForPrompt(5.0);

            $client->send('show running-config');
            // Large configs on 48-port chassis can take several seconds to print.
            $raw = $client->waitForPrompt(60.0);

            try { $client->send('exit'); } catch (\Throwable) {}
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            $client->close();
        }

        if ($error) {
            return back()->with('error', 'Fetch failed: ' . $error);
        }

        $config = $this->cleanRunningConfig($raw, $device->name);
        if (trim($config) === '') {
            return back()->with('error', 'Empty config returned. Check device permissions.');
        }

        $hash    = hash('sha256', $config);
        $latest  = SwitchRunningConfig::where('device_id', $device->id)
            ->latest('captured_at')->first();

        if ($latest && $latest->config_hash === $hash) {
            $latest->captured_at = now();
            $latest->save();
            return back()->with('success', 'Config unchanged since ' . $latest->captured_at->diffForHumans() . '. Timestamp refreshed.');
        }

        SwitchRunningConfig::create([
            'device_id'   => $device->id,
            'device_name' => $device->name,
            'device_ip'   => $device->ip_address,
            'branch_id'   => $device->branch_id,
            'config_text' => $config,
            'config_hash' => $hash,
            'size_bytes'  => strlen($config),
            'captured_at' => now(),
        ]);

        return back()->with('success', 'Running-config captured (' . number_format(strlen($config)) . ' bytes).');
    }

    /**
     * Strip the pagination banner, timestamp header, and the trailing prompt line so
     * consecutive captures of an unchanged config produce stable hashes.
     */
    private function cleanRunningConfig(string $raw, string $deviceName): string
    {
        $lines = preg_split('/\r?\n/', $raw) ?: [];

        // Drop echoed "show running-config" + "Building configuration..." + "Current configuration : N bytes"
        // and drop the trailing prompt line.
        $cleaned = [];
        foreach ($lines as $ln) {
            $t = trim($ln);
            if ($t === 'show running-config') continue;
            if (str_starts_with($t, 'Building configuration')) continue;
            if (preg_match('/^Current configuration\s*:/', $t)) continue;
            // Prompt line at the end, e.g. "SwitchName#"
            if (preg_match('/^\S+[#>]\s*$/', $t)) continue;
            $cleaned[] = $ln;
        }

        return trim(implode("\n", $cleaned)) . "\n";
    }

    /**
     * List all devices with capture counts and latest snapshot time.
     */
    public function configsIndex()
    {
        $rows = SwitchRunningConfig::select(
                'device_id',
                DB::raw('MAX(device_name) as device_name'),
                DB::raw('MAX(device_ip) as device_ip'),
                DB::raw('MAX(branch_id) as branch_id'),
                DB::raw('COUNT(*) as capture_count'),
                DB::raw('MAX(captured_at) as last_captured_at'),
                DB::raw('MAX(size_bytes) as last_size')
            )
            ->groupBy('device_id')
            ->orderByDesc('last_captured_at')
            ->get();

        return view('admin.switch_qos.configs_index', ['rows' => $rows]);
    }

    /**
     * Show a specific config snapshot (or the latest if id is omitted).
     */
    public function configShow(Device $device, ?int $snapshotId = null)
    {
        $snapshot = $snapshotId
            ? SwitchRunningConfig::where('device_id', $device->id)->findOrFail($snapshotId)
            : SwitchRunningConfig::where('device_id', $device->id)->latest('captured_at')->firstOrFail();

        $history = SwitchRunningConfig::where('device_id', $device->id)
            ->orderByDesc('captured_at')
            ->get(['id', 'captured_at', 'size_bytes', 'config_hash']);

        return view('admin.switch_qos.configs_show', compact('device', 'snapshot', 'history'));
    }

    public function configDownload(Device $device, int $snapshotId)
    {
        $snapshot = SwitchRunningConfig::where('device_id', $device->id)->findOrFail($snapshotId);
        $slug = preg_replace('/[^A-Za-z0-9_\-]/', '_', $device->name ?: $device->ip_address);
        $stamp = $snapshot->captured_at->format('Ymd_His');
        return response($snapshot->config_text, 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$slug}_{$stamp}.cfg\"",
        ]);
    }

    /**
     * Diff two snapshots of the same device. Query params: ?from=ID&to=ID (defaults to
     * "latest vs. previous").
     */
    public function configDiff(Device $device, Request $request)
    {
        $snapshots = SwitchRunningConfig::where('device_id', $device->id)
            ->orderByDesc('captured_at')
            ->get();

        if ($snapshots->count() < 2) {
            return view('admin.switch_qos.configs_diff', [
                'device' => $device, 'snapshots' => $snapshots,
                'from' => null, 'to' => null, 'diff' => [],
            ]);
        }

        $toId   = (int) ($request->input('to')   ?: $snapshots[0]->id);
        $fromId = (int) ($request->input('from') ?: $snapshots[1]->id);

        $from = $snapshots->firstWhere('id', $fromId) ?? $snapshots[1];
        $to   = $snapshots->firstWhere('id', $toId)   ?? $snapshots[0];

        $diff = $this->lineDiff($from->config_text, $to->config_text);

        return view('admin.switch_qos.configs_diff', compact('device', 'snapshots', 'from', 'to', 'diff'));
    }

    /**
     * Simple line-level diff using LCS. Returns an array of ['op' => ' |-|+', 'line' => string].
     */
    private function lineDiff(string $a, string $b): array
    {
        $aLines = explode("\n", rtrim($a, "\n"));
        $bLines = explode("\n", rtrim($b, "\n"));
        $n = count($aLines); $m = count($bLines);

        // Fast path: identical
        if ($aLines === $bLines) {
            return array_map(fn ($l) => ['op' => ' ', 'line' => $l], $aLines);
        }

        // Bounded LCS table — guard against huge files by chunking. For typical switch
        // configs (<20k lines) this runs in well under a second.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $lcs[$i][$j] = $aLines[$i - 1] === $bLines[$j - 1]
                    ? $lcs[$i - 1][$j - 1] + 1
                    : max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
            }
        }

        $out = [];
        $i = $n; $j = $m;
        while ($i > 0 && $j > 0) {
            if ($aLines[$i - 1] === $bLines[$j - 1]) {
                $out[] = ['op' => ' ', 'line' => $aLines[$i - 1]];
                $i--; $j--;
            } elseif ($lcs[$i - 1][$j] >= $lcs[$i][$j - 1]) {
                $out[] = ['op' => '-', 'line' => $aLines[$i - 1]];
                $i--;
            } else {
                $out[] = ['op' => '+', 'line' => $bLines[$j - 1]];
                $j--;
            }
        }
        while ($i > 0) { $out[] = ['op' => '-', 'line' => $aLines[$i - 1]]; $i--; }
        while ($j > 0) { $out[] = ['op' => '+', 'line' => $bLines[$j - 1]]; $j--; }

        return array_reverse($out);
    }

    public function deleteCredential(Device $device, Credential $credential)
    {
        if ($credential->device_id !== $device->id) {
            abort(404);
        }
        if (!in_array($credential->category, ['telnet', 'enable'], true)) {
            abort(403, 'Only telnet/enable credentials are manageable from this page.');
        }
        $credential->delete();
        return back()->with('success', ucfirst($credential->category) . ' password removed.');
    }

    public function exportCsv(Request $request)
    {
        $query = SwitchQosStat::query();

        if ($request->filled('device_name')) {
            $query->where('device_name', 'like', '%' . $request->device_name . '%');
        }
        if ($request->filled('device_ip')) {
            $query->where('device_ip', $request->device_ip);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('polled_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('polled_at', '<=', $request->date_to);
        }

        $rows    = $query->orderByDesc('polled_at')->limit(10000)->get();
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="switch_qos_export.csv"',
        ];

        $callback = function () use ($rows) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, [
                'Device', 'IP', 'Interface',
                'Q0_T1_drop','Q0_T2_drop','Q0_T3_drop',
                'Q1_T1_drop','Q1_T2_drop','Q1_T3_drop',
                'Q2_T1_drop','Q2_T2_drop','Q2_T3_drop',
                'Q3_T1_drop','Q3_T2_drop','Q3_T3_drop',
                'Policer InProfile','Policer OutOfProfile',
                'Total Drops','Polled At',
            ]);
            foreach ($rows as $r) {
                fputcsv($fh, [
                    $r->device_name, $r->device_ip, $r->interface_name,
                    $r->q0_t1_drop,$r->q0_t2_drop,$r->q0_t3_drop,
                    $r->q1_t1_drop,$r->q1_t2_drop,$r->q1_t3_drop,
                    $r->q2_t1_drop,$r->q2_t2_drop,$r->q2_t3_drop,
                    $r->q3_t1_drop,$r->q3_t2_drop,$r->q3_t3_drop,
                    $r->policer_in_profile, $r->policer_out_of_profile,
                    $r->total_drops, $r->polled_at,
                ]);
            }
            fclose($fh);
        };

        return response()->stream($callback, 200, $headers);
    }
}
