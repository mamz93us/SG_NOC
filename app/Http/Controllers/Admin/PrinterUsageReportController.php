<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SnapshotPrinterCountersJob;
use App\Models\Branch;
use App\Models\Printer;
use App\Models\PrinterCounterSnapshot;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PrinterUsageReportController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->input('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : now()->subDays(30)->startOfDay();
        $to = $request->input('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();
        $branchId = $request->input('branch') ?: null;

        // Take a snapshot now if none exists for today — gives the user a usable
        // baseline immediately instead of waiting for the 23:55 schedule.
        $haveTodaySnapshot = PrinterCounterSnapshot::where('snapshot_date', now()->toDateString())->exists();
        if (! $haveTodaySnapshot) {
            try {
                (new SnapshotPrinterCountersJob())->handle();
            } catch (\Throwable) {
                // non-fatal — page still renders
            }
        }

        $printerQuery = Printer::with('branch:id,name')->orderBy('branch_id')->orderBy('printer_name');
        if ($branchId) {
            $printerQuery->where('branch_id', $branchId);
        }
        $printers = $printerQuery->get();
        $printerIds = $printers->pluck('id');

        // Snapshots within the period
        $snapshotsByPrinter = PrinterCounterSnapshot::whereIn('printer_id', $printerIds)
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('printer_id')
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy('printer_id');

        // Latest snapshot BEFORE the period — used as the "first" boundary when
        // there's no in-period snapshot but we still want a pre-period anchor.
        $preFromByPrinter = PrinterCounterSnapshot::whereIn('printer_id', $printerIds)
            ->where('snapshot_date', '<', $from->toDateString())
            ->orderBy('printer_id')
            ->orderByDesc('snapshot_date')
            ->get()
            ->groupBy('printer_id')
            ->map(fn ($g) => $g->first());

        $rows = [];
        foreach ($printers as $p) {
            $snaps = $snapshotsByPrinter->get($p->id, collect());

            // ── First boundary ──────────────────────────────────────
            // Prefer earliest in-period snapshot. Otherwise fall back to the
            // latest pre-period snapshot, which still lets us compute the
            // since-then delta inside the requested window.
            $first = $snaps->first() ?: $preFromByPrinter->get($p->id);

            // ── Last boundary ───────────────────────────────────────
            // Prefer latest in-period snapshot. Otherwise use the printer's
            // current page_count_total (live SNMP value) — for a "to" of today
            // this is the most accurate possible value.
            $last       = $snaps->last();
            $lastIsLive = false;
            if (! $last && $p->page_count_total !== null) {
                $last = (object) [
                    'snapshot_date' => now()->toDate(),
                    'page_total' => $p->page_count_total,
                    'page_color' => $p->page_count_color,
                    'page_mono'  => $p->page_count_mono,
                    'page_copy'  => $p->page_count_copy,
                    'page_print' => $p->page_count_print,
                    'page_scan'  => $p->page_count_scan,
                    'page_fax'   => $p->page_count_fax,
                ];
                $lastIsLive = true;
            }

            if (! $first || ! $last) {
                // No data at all — still surface the printer with the live total
                // so the row isn't empty.
                $rows[] = $this->row($p, null, null, 0, 0, 0, false, $p->page_count_total, false);
                continue;
            }

            $diffTotal = $this->safeDiff($last->page_total ?? null, $first->page_total ?? null);
            $diffColor = $this->safeDiff($last->page_color ?? null, $first->page_color ?? null);
            $diffMono  = $this->safeDiff($last->page_mono  ?? null, $first->page_mono  ?? null);

            $anomaly = (($last->page_total ?? null) !== null
                     && ($first->page_total ?? null) !== null
                     && $last->page_total < $first->page_total);

            $rows[] = $this->row(
                $p,
                $first,
                $last,
                $diffTotal,
                $diffColor,
                $diffMono,
                $anomaly,
                $p->page_count_total,
                $lastIsLive
            );
        }

        // Sort
        $sort = $request->input('sort', 'pages');
        $dir  = $request->input('dir', 'desc') === 'asc' ? SORT_ASC : SORT_DESC;
        $key  = match ($sort) {
            'name'    => 'name',
            'branch'  => 'branch',
            'pages'   => 'pages',
            'color'   => 'color',
            'mono'    => 'mono',
            'current' => 'current_total',
            default   => 'pages',
        };
        if (! empty($rows)) {
            $col = array_column($rows, $key);
            array_multisort($col, $dir, $rows);
        }

        // Branch totals
        $branchTotals = [];
        foreach ($rows as $r) {
            $bn = $r['branch'] ?: '—';
            $branchTotals[$bn]['pages']   = ($branchTotals[$bn]['pages']   ?? 0) + $r['pages'];
            $branchTotals[$bn]['color']   = ($branchTotals[$bn]['color']   ?? 0) + $r['color'];
            $branchTotals[$bn]['mono']    = ($branchTotals[$bn]['mono']    ?? 0) + $r['mono'];
            $branchTotals[$bn]['current'] = ($branchTotals[$bn]['current'] ?? 0) + (int) ($r['current_total'] ?? 0);
        }

        $branches = Branch::orderBy('name')->get(['id', 'name']);

        // Earliest snapshot date overall — informs the user how far back data goes
        $earliestSnapshot = PrinterCounterSnapshot::min('snapshot_date');

        return view('admin.printers.usage.index', [
            'rows'             => $rows,
            'branchTotals'     => $branchTotals,
            'branches'         => $branches,
            'from'             => $from,
            'to'               => $to,
            'branchId'         => $branchId,
            'sort'             => $sort,
            'dir'              => $request->input('dir', 'desc'),
            'totalPages'       => array_sum(array_column($rows, 'pages')),
            'totalColor'       => array_sum(array_column($rows, 'color')),
            'totalMono'        => array_sum(array_column($rows, 'mono')),
            'totalCurrent'     => array_sum(array_column($rows, 'current_total')),
            'earliestSnapshot' => $earliestSnapshot,
        ]);
    }

    /**
     * Manual "snapshot now" trigger — useful when you want an extra baseline
     * mid-day without waiting for the daily 23:55 schedule.
     */
    public function snapshotNow()
    {
        try {
            (new SnapshotPrinterCountersJob())->handle();
            return back()->with('success', 'Counter snapshot taken.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Snapshot failed: ' . $e->getMessage());
        }
    }

    private function safeDiff(?int $end, ?int $start): int
    {
        if ($end === null || $start === null) return 0;
        $diff = $end - $start;
        return $diff > 0 ? $diff : 0;
    }

    private function row(Printer $p, $first, $last, int $pages, int $color, int $mono, bool $anomaly, ?int $currentTotal, bool $lastIsLive): array
    {
        return [
            'id'            => $p->id,
            'name'          => $p->printer_name,
            'branch'        => $p->branch?->name,
            'first'         => $first ? Carbon::parse($first->snapshot_date)->toDateString() : null,
            'last'          => $last  ? Carbon::parse($last->snapshot_date)->toDateString()  : null,
            'first_total'   => $first->page_total ?? null,
            'last_total'    => $last->page_total ?? null,
            'pages'         => $pages,
            'color'         => $color,
            'mono'          => $mono,
            'anomaly'       => $anomaly,
            'current_total' => $currentTotal,
            'last_is_live'  => $lastIsLive,
        ];
    }
}
