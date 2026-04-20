<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Credential;
use App\Models\Device;
use App\Models\SwitchQosStat;
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

        return view('admin.switch_qos.device', compact(
            'latestSnapshot', 'interfaces', 'trend', 'deviceIp',
            'device', 'telnetCred', 'enableCred'
        ));
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
