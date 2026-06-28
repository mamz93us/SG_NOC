<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketVisit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * IT ticket-portal analytics. Reads the ticket_visits table written by the
 * it.samirgroup.net forward route. Gated by `manage-settings` in routes/web.php.
 */
class TicketStatsController extends Controller
{
    /** Unique visitor = distinct session cookie, falling back to IP. */
    private const UNIQUE_EXPR = 'COUNT(DISTINCT COALESCE(session_id, ip_address))';

    public function index(Request $request)
    {
        $now = CarbonImmutable::now();

        $cards = [
            'today' => $this->window($now->startOfDay(), $now),
            '7d' => $this->window($now->subDays(7), $now),
            '30d' => $this->window($now->subDays(30), $now),
            'all' => $this->window(null, null),
        ];

        $branches = TicketVisit::query()
            ->whereNotNull('branch')
            ->distinct()
            ->orderBy('branch')
            ->pluck('branch');

        return view('admin.ticket-stats.index', [
            'cards' => $cards,
            'series' => $this->dailySeries($now->subDays(29)->startOfDay(), $now),
            'byBranch' => $this->breakdown('branch'),
            'byBrowser' => $this->breakdown('browser'),
            'byDevice' => $this->breakdown('device_type'),
            'heatmap' => $this->heatmap($now->subDays(30), $now),
            'recent' => $this->recentQuery($request)->paginate(25)->withQueryString(),
            'branches' => $branches,
            'filters' => $this->filters($request),
            'destination' => config('ticket_tracking.destination_url'),
            'forwardMode' => config('ticket_tracking.forward_mode'),
        ]);
    }

    /** JSON summary for reuse by other NOC widgets: /api/ticket-stats?range=7d&branch=... */
    public function data(Request $request): JsonResponse
    {
        $now = CarbonImmutable::now();
        $range = $request->query('range', '7d');
        $from = match ($range) {
            'today' => $now->startOfDay(),
            '30d' => $now->subDays(30),
            'all' => null,
            default => $now->subDays(7),
        };

        $base = TicketVisit::query();
        if ($from) {
            $base->where('visited_at', '>=', $from);
        }
        if ($request->filled('branch')) {
            $base->where('branch', $request->query('branch'));
        }

        return response()->json([
            'range' => $range,
            'branch' => $request->query('branch'),
            'total' => (clone $base)->count(),
            'unique' => (int) (clone $base)->selectRaw(self::UNIQUE_EXPR.' AS u')->value('u'),
            'byBranch' => (clone $base)->selectRaw('branch, COUNT(*) c')->groupBy('branch')->pluck('c', 'branch'),
            'byDevice' => (clone $base)->selectRaw('device_type, COUNT(*) c')->groupBy('device_type')->pluck('c', 'device_type'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $visits = $this->recentQuery($request)->limit(50000)->get();
        $filename = 'ticket_visits_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($visits) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Visited At', 'Branch', 'IP Address', 'Browser', 'Platform',
                'Device', 'Unique Today', 'Referrer', 'Country', 'City',
            ]);
            foreach ($visits as $v) {
                fputcsv($out, [
                    $v->visited_at?->format('Y-m-d H:i:s'),
                    $v->branch,
                    $v->ip_address,
                    $v->browser,
                    $v->platform,
                    $v->device_type,
                    $v->is_unique_today ? 'yes' : 'no',
                    $v->referrer,
                    $v->country,
                    $v->city,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{total:int, unique:int} */
    private function window(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $query = TicketVisit::query();
        if ($from) {
            $query->where('visited_at', '>=', $from);
        }
        if ($to) {
            $query->where('visited_at', '<=', $to);
        }

        return [
            'total' => (clone $query)->count(),
            'unique' => (int) (clone $query)->selectRaw(self::UNIQUE_EXPR.' AS u')->value('u'),
        ];
    }

    /** Visits per day across the window, zero-filled. */
    private function dailySeries(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = TicketVisit::query()
            ->where('visited_at', '>=', $from)
            ->selectRaw('DATE(visited_at) d, COUNT(*) c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $labels = [];
        $values = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $labels[] = $key;
            $values[] = (int) ($rows[$key] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** @return \Illuminate\Support\Collection<string,int> */
    private function breakdown(string $column)
    {
        return TicketVisit::query()
            ->selectRaw("COALESCE($column, 'unknown') AS k, COUNT(*) c")
            ->groupBy('k')
            ->orderByDesc('c')
            ->pluck('c', 'k');
    }

    /** 7 (day-of-week) × 24 (hour) grid of counts. */
    private function heatmap(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        TicketVisit::query()
            ->where('visited_at', '>=', $from)
            ->where('visited_at', '<=', $to)
            ->get(['visited_at'])
            ->each(function ($v) use (&$grid) {
                $dow = (int) $v->visited_at->dayOfWeek; // 0=Sun
                $hour = (int) $v->visited_at->format('G');
                $grid[$dow][$hour]++;
            });

        return $grid;
    }

    private function recentQuery(Request $request)
    {
        $f = $this->filters($request);
        $query = TicketVisit::query()->latest('visited_at');

        if ($f['from']) {
            $query->where('visited_at', '>=', CarbonImmutable::parse($f['from'])->startOfDay());
        }
        if ($f['to']) {
            $query->where('visited_at', '<=', CarbonImmutable::parse($f['to'])->endOfDay());
        }
        if ($f['branch']) {
            $query->where('branch', $f['branch']);
        }

        return $query;
    }

    /** @return array{from:?string,to:?string,branch:?string} */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'branch' => $request->query('branch'),
        ];
    }
}
