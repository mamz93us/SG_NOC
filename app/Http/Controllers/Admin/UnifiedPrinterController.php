<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CupsPrinter;
use App\Models\NocEvent;
use App\Models\Printer;
use App\Models\PrinterCounterSnapshot;
use Illuminate\Http\Request;

class UnifiedPrinterController extends Controller
{
    public function index(Request $request)
    {
        $query = Printer::with(['branch:id,name', 'device:id,asset_code,type', 'cupsPrinter:id,printer_id,queue_name,status,is_active'])
            ->orderBy('branch_id')
            ->orderBy('printer_name');

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('printer_name', 'like', "%{$s}%")
                  ->orWhere('ip_address', 'like', "%{$s}%")
                  ->orWhere('model', 'like', "%{$s}%");
            });
        }
        if ($request->boolean('with_alerts')) {
            $alertedIds = NocEvent::where('source_type', 'printer')
                ->whereIn('status', ['open', 'acknowledged'])
                ->distinct()
                ->pluck('source_id');
            $query->whereIn('id', $alertedIds);
        }

        $printers = $query->paginate(50)->withQueryString();

        // Open-event count per printer in this page
        $printerIds = $printers->pluck('id');
        $openEventCounts = NocEvent::where('source_type', 'printer')
            ->whereIn('source_id', $printerIds)
            ->whereIn('status', ['open', 'acknowledged'])
            ->selectRaw('source_id, COUNT(*) as c')
            ->groupBy('source_id')
            ->pluck('c', 'source_id');

        $branches = Branch::orderBy('name')->get(['id', 'name']);

        // Standalone CUPS queues that aren't yet linked to a Printer record.
        // Surface them so admins can run `printers:link-cups` or just see the gap.
        $orphanCups = CupsPrinter::whereNull('printer_id')
            ->orderBy('branch_id')
            ->orderBy('queue_name')
            ->limit(50)
            ->get();

        return view('admin.printers.unified.index', compact('printers', 'branches', 'openEventCounts', 'orphanCups'));
    }

    public function show(Printer $printer)
    {
        $printer->load([
            'branch',
            'device.credentials',
            'cupsPrinter.printJobs' => fn ($q) => $q->orderByDesc('id')->limit(15),
            'supplies',
            'maintenanceLogs.performedByUser',
        ]);

        $alerts = NocEvent::where('source_type', 'printer')
            ->where('source_id', $printer->id)
            ->orderByDesc('first_seen')
            ->limit(50)
            ->get();

        // Last 90 days of usage from snapshots
        $snapshots = PrinterCounterSnapshot::where('printer_id', $printer->id)
            ->where('snapshot_date', '>=', now()->subDays(90))
            ->orderBy('snapshot_date')
            ->get();

        return view('admin.printers.unified.show', compact('printer', 'alerts', 'snapshots'));
    }
}
