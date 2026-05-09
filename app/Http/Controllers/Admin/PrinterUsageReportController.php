<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        $printerQuery = Printer::with('branch:id,name')->orderBy('branch_id')->orderBy('printer_name');
        if ($branchId) {
            $printerQuery->where('branch_id', $branchId);
        }
        $printers = $printerQuery->get();

        // Pull bracket snapshots in one shot.
        $snapshotsByPrinter = PrinterCounterSnapshot::whereIn('printer_id', $printers->pluck('id'))
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('printer_id')
            ->orderBy('snapshot_date')
            ->get()
            ->groupBy('printer_id');

        $rows = [];
        foreach ($printers as $p) {
            $snaps = $snapshotsByPrinter->get($p->id, collect());
            if ($snaps->isEmpty()) {
                $rows[] = $this->row($p, null, null, 0, 0, 0, false);
                continue;
            }

            $first = $snaps->first();
            $last  = $snaps->last();

            $diffTotal = $this->safeDiff($last->page_total, $first->page_total);
            $diffColor = $this->safeDiff($last->page_color, $first->page_color);
            $diffMono  = $this->safeDiff($last->page_mono,  $first->page_mono);

            $anomaly = ($last->page_total !== null && $first->page_total !== null && $last->page_total < $first->page_total);

            $rows[] = $this->row($p, $first, $last, $diffTotal, $diffColor, $diffMono, $anomaly);
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
            $branchTotals[$bn]['pages'] = ($branchTotals[$bn]['pages'] ?? 0) + $r['pages'];
            $branchTotals[$bn]['color'] = ($branchTotals[$bn]['color'] ?? 0) + $r['color'];
            $branchTotals[$bn]['mono']  = ($branchTotals[$bn]['mono']  ?? 0) + $r['mono'];
        }

        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.printers.usage.index', [
            'rows'         => $rows,
            'branchTotals' => $branchTotals,
            'branches'     => $branches,
            'from'         => $from,
            'to'           => $to,
            'branchId'     => $branchId,
            'sort'         => $sort,
            'dir'          => $request->input('dir', 'desc'),
            'totalPages'   => array_sum(array_column($rows, 'pages')),
            'totalColor'   => array_sum(array_column($rows, 'color')),
            'totalMono'    => array_sum(array_column($rows, 'mono')),
        ]);
    }

    private function safeDiff(?int $end, ?int $start): int
    {
        if ($end === null || $start === null) return 0;
        $diff = $end - $start;
        return $diff > 0 ? $diff : 0;
    }

    private function row(Printer $p, $first, $last, int $pages, int $color, int $mono, bool $anomaly): array
    {
        return [
            'id'        => $p->id,
            'name'      => $p->printer_name,
            'branch'    => $p->branch?->name,
            'first'     => $first?->snapshot_date?->toDateString(),
            'last'      => $last?->snapshot_date?->toDateString(),
            'first_total' => $first->page_total ?? null,
            'last_total'  => $last->page_total ?? null,
            'pages'     => $pages,
            'color'     => $color,
            'mono'      => $mono,
            'anomaly'   => $anomaly,
        ];
    }
}
