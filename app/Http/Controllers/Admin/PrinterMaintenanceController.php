<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Printer;
use App\Models\PrinterMaintenanceLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PrinterMaintenanceController extends Controller
{
    public function index(Printer $printer)
    {
        $logs = PrinterMaintenanceLog::where('printer_id', $printer->id)
            ->orderByDesc('performed_at')
            ->paginate(20);

        return view('admin.printers.maintenance', compact('printer', 'logs'));
    }

    public function store(Request $request, Printer $printer)
    {
        $validated = $request->validate([
            'type'              => 'required|in:toner_change,repair,service,inspection',
            'description'       => 'nullable|string|max:1000',
            'performed_by_name' => 'nullable|string|max:255',
            'cost'              => 'nullable|numeric|min:0',
            'performed_at'      => 'required|date',
            'notes'             => 'nullable|string|max:1000',
        ]);

        $log = PrinterMaintenanceLog::create(array_merge($validated, [
            'printer_id'           => $printer->id,
            'performed_by_user_id' => Auth::id(),
            'performed_by_name'    => $validated['performed_by_name'] ?? Auth::user()->name,
        ]));

        // Update printer fields based on type
        if ($validated['type'] === 'toner_change') {
            $printer->update(['toner_last_changed' => $validated['performed_at']]);
        } elseif (in_array($validated['type'], ['service', 'repair'])) {
            $printer->update(['last_service_date' => $validated['performed_at']]);
        }

        ActivityLog::create([
            'model_type' => Printer::class,
            'model_id'   => $printer->id,
            'action'     => 'printer_maintenance_logged',
            'changes'    => ['log_id' => $log->id, 'type' => $validated['type'], 'performed_at' => $validated['performed_at']],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', 'Maintenance log added successfully.');
    }

    public function destroy(Printer $printer, PrinterMaintenanceLog $log)
    {
        if ($log->printer_id !== $printer->id) {
            abort(404);
        }

        $snapshot = $log->toArray();
        $id       = $log->id;
        $log->delete();

        ActivityLog::create([
            'model_type' => Printer::class,
            'model_id'   => $printer->id,
            'action'     => 'printer_maintenance_deleted',
            'changes'    => ['log_id' => $id, 'snapshot' => $snapshot],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', 'Maintenance log deleted.');
    }
}
