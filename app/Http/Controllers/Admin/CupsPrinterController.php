<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\CupsPrinter;
use App\Models\CupsPrintJob;
use App\Services\CupsService;
use Illuminate\Http\Request;

class CupsPrinterController extends Controller
{
    public function __construct(protected CupsService $cups) {}

    public function index(Request $request)
    {
        $query = CupsPrinter::with('branch')->withCount('printJobs');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('queue_name', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        $cupsPrinters = $query->orderBy('name')->paginate(10)->withQueryString();
        $cupsRunning  = $this->cups->isCupsRunning();

        return view('admin.print-manager.index', compact('cupsPrinters', 'cupsRunning'));
    }

    public function create()
    {
        $branches = Branch::orderBy('name')->get();

        return view('admin.print-manager.create', compact('branches'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'queue_name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/', 'unique:cups_printers'],
            'ip_address' => 'required|ip',
            'port'       => 'integer|between:1,65535',
            'protocol'   => 'required|in:ipp,ipps,socket,lpd',
            'ipp_path'   => 'nullable|string|max:255',
            'branch_id'  => 'nullable|exists:branches,id',
            'driver'     => 'nullable|string|max:255',
            'location'   => 'nullable|string|max:255',
            'is_shared'  => 'boolean',
            'is_active'  => 'boolean',
        ]);

        $validated['is_shared'] = $request->boolean('is_shared');
        $validated['is_active'] = $request->boolean('is_active');

        $cupsPrinter = CupsPrinter::create($validated);

        // Register in CUPS
        $result = $this->cups->addPrinter($cupsPrinter);

        if ($result['success']) {
            $this->cups->enablePrinter($cupsPrinter->queue_name);
            $status = $this->cups->getStatus($cupsPrinter->queue_name);
            $cupsPrinter->update(['status' => $status, 'last_checked_at' => now()]);

            return redirect()->route('admin.print-manager.index')
                ->with('success', "Printer '{$cupsPrinter->name}' added and registered in CUPS.");
        }

        return redirect()->route('admin.print-manager.index')
            ->with('warning', "Printer '{$cupsPrinter->name}' saved to database, but CUPS registration failed: {$result['output']}");
    }

    public function show(CupsPrinter $cupsPrinter)
    {
        $cupsPrinter->load('branch');
        $jobs     = $cupsPrinter->printJobs()->with('user')->latest()->paginate(20);
        $cupsJobs = $this->cups->getJobs($cupsPrinter->queue_name);

        return view('admin.print-manager.show', compact('cupsPrinter', 'jobs', 'cupsJobs'));
    }

    public function edit(CupsPrinter $cupsPrinter)
    {
        $branches = Branch::orderBy('name')->get();

        return view('admin.print-manager.edit', compact('cupsPrinter', 'branches'));
    }

    public function update(Request $request, CupsPrinter $cupsPrinter)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'queue_name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/', 'unique:cups_printers,queue_name,' . $cupsPrinter->id],
            'ip_address' => 'required|ip',
            'port'       => 'integer|between:1,65535',
            'protocol'   => 'required|in:ipp,ipps,socket,lpd',
            'ipp_path'   => 'nullable|string|max:255',
            'branch_id'  => 'nullable|exists:branches,id',
            'driver'     => 'nullable|string|max:255',
            'location'   => 'nullable|string|max:255',
            'is_shared'  => 'boolean',
            'is_active'  => 'boolean',
        ]);

        $validated['is_shared'] = $request->boolean('is_shared');
        $validated['is_active'] = $request->boolean('is_active');

        $oldQueue = $cupsPrinter->queue_name;
        $connectionChanged = $cupsPrinter->ip_address !== $validated['ip_address']
            || $cupsPrinter->port != $validated['port']
            || $cupsPrinter->protocol !== $validated['protocol']
            || $cupsPrinter->ipp_path !== ($validated['ipp_path'] ?? $cupsPrinter->ipp_path)
            || $oldQueue !== $validated['queue_name'];

        $cupsPrinter->update($validated);

        // Re-register in CUPS if connection details changed
        if ($connectionChanged) {
            if ($oldQueue !== $cupsPrinter->queue_name) {
                $this->cups->removePrinter($oldQueue);
            }
            $result = $this->cups->addPrinter($cupsPrinter);
            if ($result['success']) {
                $this->cups->enablePrinter($cupsPrinter->queue_name);
            }
        }

        // Toggle active/disabled in CUPS
        if (!$connectionChanged) {
            $validated['is_active']
                ? $this->cups->enablePrinter($cupsPrinter->queue_name)
                : $this->cups->disablePrinter($cupsPrinter->queue_name);
        }

        return redirect()->route('admin.print-manager.show', $cupsPrinter)
            ->with('success', 'Printer updated.');
    }

    public function destroy(CupsPrinter $cupsPrinter)
    {
        $this->cups->removePrinter($cupsPrinter->queue_name);
        $name = $cupsPrinter->name;
        $cupsPrinter->delete();

        return redirect()->route('admin.print-manager.index')
            ->with('success', "Printer '{$name}' removed.");
    }

    public function refreshStatus(CupsPrinter $cupsPrinter)
    {
        $status = $this->cups->getStatus($cupsPrinter->queue_name);
        $cupsPrinter->update(['status' => $status, 'last_checked_at' => now()]);

        return back()->with('success', "Status refreshed: {$status}");
    }

    public function testPrint(CupsPrinter $cupsPrinter)
    {
        $result = $this->cups->printTestPage($cupsPrinter->queue_name);

        CupsPrintJob::create([
            'cups_printer_id' => $cupsPrinter->id,
            'user_id'         => auth()->id(),
            'title'           => 'CUPS Test Page',
            'status'          => $result['success'] ? 'processing' : 'error',
            'cups_metadata'   => ['output' => $result['output']],
        ]);

        return back()->with(
            $result['success'] ? 'success' : 'error',
            $result['success'] ? 'Test page sent to printer.' : 'Test print failed: ' . $result['output']
        );
    }

    public function cancelJob(CupsPrinter $cupsPrinter, CupsPrintJob $cupsPrintJob)
    {
        $jobId = $cupsPrintJob->cups_job_id
            ? $cupsPrinter->queue_name . '-' . $cupsPrintJob->cups_job_id
            : null;

        if ($jobId) {
            $this->cups->cancelJob($jobId);
        }

        $cupsPrintJob->update(['status' => 'cancelled']);

        return back()->with('success', 'Job cancelled.');
    }
}
