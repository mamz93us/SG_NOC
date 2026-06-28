<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessVisit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Who's accessing the NOC / EM / Portal" analytics. Reads access_visits
 * (logins + presence heartbeats). Gated by `view-activity-logs` in web.php —
 * this is access-audit data, alongside the existing Audit Log.
 */
class AccessStatsController extends Controller
{
    private const UNIQUE_USERS = 'COUNT(DISTINCT user_id)';

    public function index(Request $request)
    {
        $now = CarbonImmutable::now();

        $cards = [
            'today' => $this->window($now->startOfDay(), $now),
            '7d' => $this->window($now->subDays(7), $now),
            '30d' => $this->window($now->subDays(30), $now),
            'all' => $this->window(null, null),
        ];

        return view('admin.access-stats.index', [
            'cards' => $cards,
            'byApp' => $this->byApp(),
            'series' => $this->dailySeriesByApp($now->subDays(29)->startOfDay(), $now),
            'byBranch' => $this->breakdown('branch'),
            'byDevice' => $this->breakdown('device_type'),
            'topUsers' => $this->topUsers($request),
            'recent' => $this->recentQuery($request)->paginate(25)->withQueryString(),
            'filters' => $this->filters($request),
            'apps' => AccessVisit::APPS,
        ]);
    }

    /** JSON summary: /api/access-stats?range=7d&app=noc */
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

        $base = AccessVisit::query();
        if ($from) {
            $base->where('occurred_at', '>=', $from);
        }
        if (in_array($request->query('app'), AccessVisit::APPS, true)) {
            $base->where('app', $request->query('app'));
        }

        return response()->json([
            'range' => $range,
            'app' => $request->query('app'),
            'accesses' => (clone $base)->count(),
            'users' => (int) (clone $base)->selectRaw(self::UNIQUE_USERS.' AS u')->value('u'),
            'logins' => (clone $base)->where('event', 'login')->count(),
            'byApp' => (clone $base)->selectRaw('app, COUNT(*) c')->groupBy('app')->pluck('c', 'app'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->recentQuery($request)->limit(50000)->get();
        $filename = 'access_visits_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Occurred At', 'User', 'Email', 'App', 'Event', 'Branch',
                'IP Address', 'Browser', 'Platform', 'Device', 'Path',
            ]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->occurred_at?->format('Y-m-d H:i:s'),
                    $r->user_name,
                    $r->user_email,
                    $r->app,
                    $r->event,
                    $r->branch,
                    $r->ip_address,
                    $r->browser,
                    $r->platform,
                    $r->device_type,
                    $r->path,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{accesses:int, users:int, logins:int} */
    private function window(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $q = AccessVisit::query();
        if ($from) {
            $q->where('occurred_at', '>=', $from);
        }
        if ($to) {
            $q->where('occurred_at', '<=', $to);
        }

        return [
            'accesses' => (clone $q)->count(),
            'users' => (int) (clone $q)->selectRaw(self::UNIQUE_USERS.' AS u')->value('u'),
            'logins' => (clone $q)->where('event', 'login')->count(),
        ];
    }

    /** @return \Illuminate\Support\Collection<string,array{accesses:int,users:int}> */
    private function byApp()
    {
        return AccessVisit::query()
            ->selectRaw('app, COUNT(*) accesses, '.self::UNIQUE_USERS.' users')
            ->groupBy('app')
            ->get()
            ->keyBy('app')
            ->map(fn ($r) => ['accesses' => (int) $r->accesses, 'users' => (int) $r->users]);
    }

    /** Per-day visits split by app, zero-filled. */
    private function dailySeriesByApp(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = AccessVisit::query()
            ->where('occurred_at', '>=', $from)
            ->selectRaw('DATE(occurred_at) d, app, COUNT(*) c')
            ->groupBy('d', 'app')
            ->get();

        $labels = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $labels[] = $day->format('Y-m-d');
        }

        $datasets = [];
        foreach (AccessVisit::APPS as $app) {
            $byDay = $rows->where('app', $app)->pluck('c', 'd');
            $datasets[$app] = array_map(fn ($d) => (int) ($byDay[$d] ?? 0), $labels);
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    /** @return \Illuminate\Support\Collection<string,int> */
    private function breakdown(string $column)
    {
        return AccessVisit::query()
            ->selectRaw("COALESCE($column, 'unknown') AS k, COUNT(*) c")
            ->groupBy('k')
            ->orderByDesc('c')
            ->pluck('c', 'k');
    }

    /** Most active users (within the active filter window). */
    private function topUsers(Request $request)
    {
        return $this->filteredBase($request)
            ->getQuery()
            ->selectRaw('user_id, MAX(user_name) user_name, MAX(user_email) user_email,
                COUNT(*) accesses,
                SUM(CASE WHEN event = ? THEN 1 ELSE 0 END) logins,
                COUNT(DISTINCT app) apps,
                MAX(occurred_at) last_seen', ['login'])
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('accesses')
            ->limit(15)
            ->get();
    }

    private function recentQuery(Request $request)
    {
        return $this->filteredBase($request)->latest('occurred_at');
    }

    /** Filters applied, no ordering — safe to aggregate or order downstream. */
    private function filteredBase(Request $request)
    {
        $f = $this->filters($request);
        $q = AccessVisit::query();

        if ($f['from']) {
            $q->where('occurred_at', '>=', CarbonImmutable::parse($f['from'])->startOfDay());
        }
        if ($f['to']) {
            $q->where('occurred_at', '<=', CarbonImmutable::parse($f['to'])->endOfDay());
        }
        if ($f['app']) {
            $q->where('app', $f['app']);
        }
        if ($f['event']) {
            $q->where('event', $f['event']);
        }
        if ($f['q']) {
            $s = '%'.$f['q'].'%';
            $q->where(function ($w) use ($s) {
                $w->where('user_name', 'like', $s)
                    ->orWhere('user_email', 'like', $s)
                    ->orWhere('ip_address', 'like', $s);
            });
        }

        return $q;
    }

    /** @return array{from:?string,to:?string,app:?string,event:?string,q:?string} */
    private function filters(Request $request): array
    {
        $app = $request->query('app');

        return [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'app' => in_array($app, AccessVisit::APPS, true) ? $app : null,
            'event' => in_array($request->query('event'), ['login', 'access'], true) ? $request->query('event') : null,
            'q' => $request->query('q'),
        ];
    }
}
