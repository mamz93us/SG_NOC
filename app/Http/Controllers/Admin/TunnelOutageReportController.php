<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BranchTunnel;
use App\Models\IspConnection;
use App\Models\TunnelOutage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Branch Tunnel Watchdog — the outage report.
 *
 * The live board says what is broken now and the 7-day grid says which hours
 * were bad. Neither answers the question you get asked when the ISP disputes a
 * service credit: "give me every time this circuit was down last month, with
 * the exact start and end times."
 *
 * That is what this page is. It reads tunnel_outages, which the watchdog writes
 * on every state transition and which is never pruned, so the window can be any
 * length — not just the seven days the raw check log keeps.
 *
 * Down and degraded are reported separately on purpose. Down means the branch
 * firewall stopped answering entirely and is the ISP's problem. Degraded means
 * the link was up but a carried subnet was unreachable, which is nearly always
 * a traffic selector on our own firewall — sending that to the ISP as downtime
 * gets the whole claim rejected.
 */
class TunnelOutageReportController extends Controller
{
    /** Default reporting window when none is given — one billing month. */
    private const DEFAULT_DAYS = 30;

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $incidents = $this->incidents($filters);

        return view('admin.network.tunnel-outage-report', [
            'filters' => $filters,
            'incidents' => $incidents,
            'rows' => $this->summarise($filters, $incidents),
            'totals' => $this->totals($filters, $incidents),
            'tunnels' => BranchTunnel::with('branch')->ordered()->get(),
            'canManage' => $request->user()?->can('manage-network-settings') ?? false,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $incidents = $this->incidents($filters);
        $isp = $this->ispByBranch();

        $filename = sprintf(
            'tunnel_outages_%s_%s.csv',
            $filters['from']->format('Ymd'),
            $filters['to']->format('Ymd')
        );

        return response()->streamDownload(function () use ($incidents, $isp, $filters) {
            $out = fopen('php://output', 'w');

            // Excel opens a bare UTF-8 CSV as Windows-1252 and mangles the branch
            // names; the BOM is what stops that.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Tunnel', 'Branch', 'ISP', 'ISP account #', 'Circuit ID', 'Firewall IP',
                'Type', 'Started', 'Ended', 'Duration', 'Duration (minutes)',
                'In-window minutes', 'Checks observed', 'Monitoring coverage', 'Data gap',
                'Reason', 'ISP ticket', 'Notes',
            ]);

            foreach ($incidents as $o) {
                $branchId = $o->tunnel?->branch_id;
                $link = $branchId ? ($isp[$branchId] ?? null) : null;
                $within = $o->secondsWithin($filters['from'], $filters['to']);

                fputcsv($out, [
                    $o->tunnel?->name,
                    $o->tunnel?->branch?->name,
                    $link?->provider,
                    $link?->account_number,
                    $link?->circuit_id,
                    $o->tunnel?->firewall_ip,
                    $o->stateLabel(),
                    $o->started_at->format('Y-m-d H:i:s'),
                    $o->ended_at?->format('Y-m-d H:i:s') ?? 'ongoing',
                    TunnelOutage::humanDuration($o->seconds()),
                    round($o->seconds() / 60, 1),
                    round($within / 60, 1),
                    $o->checks,
                    round($o->coverage() * 100).'%',
                    $o->hasMonitoringGap() ? 'YES — watchdog was not running for part of this window' : '',
                    $o->reason,
                    $o->ticket_ref,
                    $o->notes,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Record the ISP ticket raised for an incident, so the next person to look
     * knows it was already reported and what reference to chase.
     */
    public function updateIncident(Request $request, TunnelOutage $outage)
    {
        $data = $request->validate([
            'ticket_ref' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        $outage->update($data);

        return back()->with('success', 'Incident updated.');
    }

    // ─────────────────────────────────────────────────────────────
    // Filters
    // ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    protected function filters(Request $request): array
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'tunnel_id' => 'nullable|integer',
            'state' => 'nullable|in:all,down,degraded',
            'min_minutes' => 'nullable|integer|min:0|max:1440',
        ]);

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : now()->subDays(self::DEFAULT_DAYS - 1)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [
            'from' => $from,
            'to' => $to,
            // Availability is measured against elapsed time, and the future has
            // not elapsed — an end date of today must not count the rest of the
            // day as uptime.
            'to_effective' => $to->greaterThan(now()) ? now() : $to->copy(),
            'tunnel_id' => $request->filled('tunnel_id') ? (int) $request->input('tunnel_id') : null,
            'state' => $request->input('state', 'all'),
            // Single dropped ICMP packets are recorded honestly but are noise in
            // a report; this hides them without deleting anything.
            'min_minutes' => (int) $request->input('min_minutes', 0),
        ];
    }

    /**
     * Every incident touching the window, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, TunnelOutage>
     */
    protected function incidents(array $filters): Collection
    {
        return TunnelOutage::with('tunnel.branch')
            ->overlapping($filters['from'], $filters['to'])
            ->when($filters['tunnel_id'], fn ($q, $id) => $q->where('branch_tunnel_id', $id))
            ->when($filters['state'] !== 'all', fn ($q) => $q->where('state', $filters['state']))
            ->orderByDesc('started_at')
            ->get()
            ->filter(fn (TunnelOutage $o) => $o->secondsWithin($filters['from'], $filters['to']) >= $filters['min_minutes'] * 60)
            ->values();
    }

    // ─────────────────────────────────────────────────────────────
    // Aggregation
    // ─────────────────────────────────────────────────────────────

    /**
     * One line per tunnel: how many incidents, how long, and the availability
     * figure to quote.
     *
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, TunnelOutage>  $incidents
     * @return array<int, array<string, mixed>>
     */
    protected function summarise(array $filters, Collection $incidents): array
    {
        $isp = $this->ispByBranch();
        $byTunnel = $incidents->groupBy('branch_tunnel_id');

        $tunnels = BranchTunnel::with('branch')
            ->when($filters['tunnel_id'], fn ($q, $id) => $q->where('id', $id))
            ->ordered()
            ->get();

        return $tunnels->map(function (BranchTunnel $t) use ($byTunnel, $filters, $isp) {
            $mine = $byTunnel->get($t->id, collect());

            // A tunnel added halfway through the window was not being watched
            // before that, and counting the earlier days as uptime would inflate
            // its availability.
            $windowStart = $t->created_at && $t->created_at->greaterThan($filters['from'])
                ? $t->created_at
                : $filters['from'];

            $windowSeconds = max(0, $windowStart->diffInSeconds($filters['to_effective']));

            $down = $mine->where('state', TunnelOutage::STATE_DOWN);
            $degraded = $mine->where('state', TunnelOutage::STATE_DEGRADED);

            $downSeconds = $down->sum(fn (TunnelOutage $o) => $o->secondsWithin($windowStart, $filters['to_effective']));
            $degradedSeconds = $degraded->sum(fn (TunnelOutage $o) => $o->secondsWithin($windowStart, $filters['to_effective']));

            $longest = $mine->sortByDesc(fn (TunnelOutage $o) => $o->seconds())->first();

            return [
                'tunnel' => $t,
                'branch' => $t->branch?->name,
                'isp' => $t->branch_id ? ($isp[$t->branch_id] ?? null) : null,
                'window_seconds' => $windowSeconds,
                'window_start' => $windowStart,
                'partial_window' => $windowStart->greaterThan($filters['from']),
                'down_count' => $down->count(),
                'down_seconds' => $downSeconds,
                'degraded_count' => $degraded->count(),
                'degraded_seconds' => $degradedSeconds,
                // Link availability is what an SLA is written against: was the
                // circuit passing traffic at all.
                'availability' => $this->pct($windowSeconds - $downSeconds, $windowSeconds),
                // What the branch actually experienced, degraded included.
                'service_availability' => $this->pct($windowSeconds - $downSeconds - $degradedSeconds, $windowSeconds),
                'longest' => $longest,
                'ongoing' => $mine->first(fn (TunnelOutage $o) => $o->isOngoing()),
                'has_gap' => $mine->contains(fn (TunnelOutage $o) => $o->hasMonitoringGap()),
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, TunnelOutage>  $incidents
     * @return array<string, mixed>
     */
    protected function totals(array $filters, Collection $incidents): array
    {
        $down = $incidents->where('state', TunnelOutage::STATE_DOWN);
        $degraded = $incidents->where('state', TunnelOutage::STATE_DEGRADED);

        return [
            'incidents' => $incidents->count(),
            'down_count' => $down->count(),
            'down_seconds' => (int) $down->sum(fn (TunnelOutage $o) => $o->secondsWithin($filters['from'], $filters['to_effective'])),
            'degraded_count' => $degraded->count(),
            'degraded_seconds' => (int) $degraded->sum(fn (TunnelOutage $o) => $o->secondsWithin($filters['from'], $filters['to_effective'])),
            'affected_tunnels' => $incidents->pluck('branch_tunnel_id')->unique()->count(),
            'ongoing' => $incidents->filter(fn (TunnelOutage $o) => $o->isOngoing())->count(),
            'days' => max(1, $filters['from']->diffInDays($filters['to_effective']) + 1),
        ];
    }

    /**
     * The ISP circuit behind each branch, so a ticket can be raised without
     * going and looking the account number up somewhere else.
     *
     * A branch with a primary and a backup line has more than one row; the
     * lowest id wins, which is the one that was entered first. The report says
     * where the number came from rather than pretending the branch has exactly
     * one circuit.
     *
     * @return array<int, IspConnection>
     */
    protected function ispByBranch(): array
    {
        return IspConnection::query()
            ->whereNotNull('branch_id')
            ->orderBy('id')
            ->get()
            ->groupBy('branch_id')
            ->map->first()
            ->all();
    }

    protected function pct(float $part, float $total): ?float
    {
        return $total > 0 ? round(max(0, $part) / $total * 100, 3) : null;
    }
}
