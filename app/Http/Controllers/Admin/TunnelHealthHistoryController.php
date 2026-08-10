<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BranchTunnel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Branch Tunnel Watchdog — the week view.
 *
 * The live board answers "what is broken now". This answers "what has been
 * breaking", which is the question you need when a branch says the link was
 * flaky yesterday afternoon and everything looks fine by the time you check.
 *
 * The watchdog writes one row per tunnel per minute and prunes at 7 days, so a
 * week is exactly the history that exists. Rather than pull ~90k rows, the page
 * aggregates to hourly buckets in SQL and paints a 7 x 24 grid per tunnel.
 */
class TunnelHealthHistoryController extends Controller
{
    /** Days shown. Matches the watchdog's 7-day retention — older data is gone. */
    private const DAYS = 7;

    /** The watchdog cadence, used to turn check counts into a duration. */
    private const MINUTES_PER_CHECK = 1;

    public function index()
    {
        $days = $this->dayRange();
        $since = Carbon::parse($days[0])->startOfDay();

        $tunnels = BranchTunnel::with('branch', 'probes')->ordered()->get();
        $buckets = $this->hourlyBuckets($since);

        $rows = $tunnels->map(fn (BranchTunnel $t) => $this->row($t, $buckets[$t->id] ?? [], $days))
            ->values()
            ->all();

        return view('admin.network.tunnel-health-history', [
            'rows' => $rows,
            'days' => $days,
            'since' => $since,
            'summary' => $this->summary($rows),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Aggregation
    // ─────────────────────────────────────────────────────────────

    /** @return array<int, string> The last N calendar days as Y-m-d, oldest first. */
    protected function dayRange(): array
    {
        $start = now()->startOfDay()->subDays(self::DAYS - 1);

        return collect(range(0, self::DAYS - 1))
            ->map(fn (int $i) => $start->copy()->addDays($i)->toDateString())
            ->all();
    }

    /**
     * Check counts per tunnel per hour, in one grouped query.
     *
     * `checked_at` is written in the app timezone, so the date and hour parts
     * are already local — no conversion needed for the day grid.
     *
     * @return array<int, array<string, array{total:int, up:int, degraded:int, down:int}>>
     *                                                                                     tunnel id => "Y-m-d H" => counts
     */
    protected function hourlyBuckets(Carbon $since): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        $day = $sqlite ? 'date(checked_at)' : 'DATE(checked_at)';
        $hour = $sqlite ? "CAST(strftime('%H', checked_at) AS INTEGER)" : 'HOUR(checked_at)';

        $rows = DB::table('tunnel_health_checks')
            ->selectRaw("branch_tunnel_id, {$day} as d, {$hour} as h, COUNT(*) as total")
            ->selectRaw("SUM(CASE WHEN state = 'up' THEN 1 ELSE 0 END) as up_c")
            ->selectRaw("SUM(CASE WHEN state = 'degraded' THEN 1 ELSE 0 END) as deg_c")
            ->selectRaw("SUM(CASE WHEN state = 'down' THEN 1 ELSE 0 END) as down_c")
            ->where('checked_at', '>=', $since)
            ->groupBy('branch_tunnel_id', 'd', 'h')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->branch_tunnel_id][$r->d.' '.(int) $r->h] = [
                'total' => (int) $r->total,
                'up' => (int) $r->up_c,
                'degraded' => (int) $r->deg_c,
                'down' => (int) $r->down_c,
            ];
        }

        return $out;
    }

    /**
     * Shape one tunnel into the grid, per-day totals and week totals.
     *
     * @param  array<string, array{total:int, up:int, degraded:int, down:int}>  $buckets
     * @param  array<int, string>  $days
     */
    protected function row(BranchTunnel $tunnel, array $buckets, array $days): array
    {
        $week = ['total' => 0, 'up' => 0, 'degraded' => 0, 'down' => 0];
        $grid = [];
        $impactedHours = 0;

        foreach ($days as $date) {
            $cells = [];
            $dayTotals = ['total' => 0, 'up' => 0, 'degraded' => 0, 'down' => 0];

            for ($h = 0; $h < 24; $h++) {
                $b = $buckets[$date.' '.$h] ?? null;

                if ($b === null) {
                    $cells[] = ['state' => 'none', 'hour' => $h, 'date' => $date, 'title' => $this->cellTitle($date, $h, null)];

                    continue;
                }

                // Worst state wins: an hour that dipped is not an "up" hour.
                $state = $b['down'] > 0 ? 'down' : ($b['degraded'] > 0 ? 'degraded' : 'up');
                if ($state !== 'up') {
                    $impactedHours++;
                }

                $cells[] = ['state' => $state, 'hour' => $h, 'date' => $date, 'title' => $this->cellTitle($date, $h, $b)];

                foreach ($dayTotals as $k => $_) {
                    $dayTotals[$k] += $b[$k];
                }
            }

            foreach ($week as $k => $_) {
                $week[$k] += $dayTotals[$k];
            }

            $grid[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('D d M'),
                'is_today' => $date === now()->toDateString(),
                'cells' => $cells,
                'uptime' => $this->pct($dayTotals['up'], $dayTotals['total']),
                'total' => $dayTotals['total'],
            ];
        }

        $worst = collect($grid)->filter(fn ($d) => $d['total'] > 0)->sortBy('uptime')->first();

        return [
            'id' => $tunnel->id,
            'name' => $tunnel->name,
            'branch' => $tunnel->branch?->name,
            'firewall_ip' => $tunnel->firewall_ip,
            'is_active' => (bool) $tunnel->is_active,
            'state' => $tunnel->state ?? BranchTunnel::STATE_UNKNOWN,
            'state_label' => $tunnel->stateLabel(),
            'state_badge' => $tunnel->stateBadgeClass(),
            'probe_labels' => $tunnel->probes->where('is_active', true)->pluck('label')->all(),
            'grid' => $grid,
            'week' => $week,
            'uptime' => $this->pct($week['up'], $week['total']),
            'down_minutes' => $week['down'] * self::MINUTES_PER_CHECK,
            'degraded_minutes' => $week['degraded'] * self::MINUTES_PER_CHECK,
            'impacted_hours' => $impactedHours,
            'worst_day' => $worst ? ['label' => $worst['label'], 'uptime' => $worst['uptime']] : null,
            'has_data' => $week['total'] > 0,
        ];
    }

    protected function cellTitle(string $date, int $hour, ?array $b): string
    {
        $when = Carbon::parse($date)->format('D d M').sprintf(' %02d:00', $hour);

        if ($b === null) {
            return "{$when} — no checks recorded";
        }

        $pct = $this->pct($b['up'], $b['total']);
        $parts = ["{$when} — {$pct}% up"];
        if ($b['degraded'] > 0) {
            $parts[] = "{$b['degraded']} degraded";
        }
        if ($b['down'] > 0) {
            $parts[] = "{$b['down']} down";
        }
        $parts[] = "{$b['total']} checks";

        return implode(' · ', $parts);
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function summary(array $rows): array
    {
        $withData = array_filter($rows, fn ($r) => $r['has_data']);

        $totalChecks = array_sum(array_column(array_column($rows, 'week'), 'total'));
        $totalUp = array_sum(array_column(array_column($rows, 'week'), 'up'));

        return [
            'avg_uptime' => $this->pct($totalUp, $totalChecks),
            'perfect' => count(array_filter($withData, fn ($r) => $r['uptime'] !== null && $r['uptime'] >= 100)),
            'tracked' => count($withData),
            'impacted_hours' => array_sum(array_column($rows, 'impacted_hours')),
            'down_minutes' => array_sum(array_column($rows, 'down_minutes')),
        ];
    }

    protected function pct(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }
}
