<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CupsPrinterSetupMail;
use App\Models\Branch;
use App\Models\CupsPrinter;
use App\Models\CupsPrintJob;
use App\Services\CupsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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

    /**
     * Sync print jobs from CUPS into the database.
     */
    public function syncJobs(CupsPrinter $cupsPrinter)
    {
        $cupsJobs = $this->cups->getAllJobs($cupsPrinter->queue_name);
        $synced = 0;

        foreach ($cupsJobs as $job) {
            $existing = CupsPrintJob::where('cups_printer_id', $cupsPrinter->id)
                ->where('cups_job_id', $job['job_id'])
                ->first();

            if ($existing) {
                // Update status if changed
                if ($existing->status !== $job['status'] && $existing->status !== 'cancelled') {
                    $existing->update(['status' => $job['status']]);
                    $synced++;
                }
            } else {
                CupsPrintJob::create([
                    'cups_printer_id' => $cupsPrinter->id,
                    'cups_job_id'     => $job['job_id'],
                    'title'           => $job['user'] . ' - Job #' . $job['job_id'],
                    'status'          => $job['status'],
                    'cups_metadata'   => $job,
                ]);
                $synced++;
            }
        }

        return back()->with('success', "Synced {$synced} job(s) from CUPS.");
    }

    /**
     * Send printer setup instructions via email.
     */
    public function sendSetupEmail(Request $request, CupsPrinter $cupsPrinter)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'name'  => 'required|string|max:255',
        ]);

        $airprintUrl = route('admin.print-manager.airprint', $cupsPrinter);

        Mail::to($validated['email'])->send(
            new CupsPrinterSetupMail($cupsPrinter, $airprintUrl, $validated['name'])
        );

        return back()->with('success', "Setup instructions sent to {$validated['email']}.");
    }

    /**
     * Generate and download an iOS .mobileconfig AirPrint profile for this printer.
     */
    public function airprintProfile(CupsPrinter $cupsPrinter)
    {
        $domain  = \App\Models\Setting::get()->cups_ipp_domain ?? request()->getHost();
        $uuid    = strtoupper(md5('cups-printer-' . $cupsPrinter->id . '-' . $cupsPrinter->queue_name));
        $payloadUuid = strtoupper(md5('airprint-payload-' . $cupsPrinter->id));

        // Format UUIDs properly
        $formatUuid = function (string $hex): string {
            return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
                   substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' .
                   substr($hex, 20, 12);
        };

        $profileUuid = $formatUuid($uuid);
        $payloadUuid = $formatUuid($payloadUuid);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
            . '<plist version="1.0">' . "\n"
            . '<dict>' . "\n"
            . '    <key>PayloadContent</key>' . "\n"
            . '    <array>' . "\n"
            . '        <dict>' . "\n"
            . '            <key>AirPrint</key>' . "\n"
            . '            <array>' . "\n"
            . '                <dict>' . "\n"
            . '                    <key>IPAddress</key>' . "\n"
            . '                    <string>' . e($domain) . '</string>' . "\n"
            . '                    <key>ResourcePath</key>' . "\n"
            . '                    <string>printers/' . e($cupsPrinter->queue_name) . '</string>' . "\n"
            . '                    <key>Port</key>' . "\n"
            . '                    <integer>631</integer>' . "\n"
            . '                    <key>ForceTLS</key>' . "\n"
            . '                    <false/>' . "\n"
            . '                </dict>' . "\n"
            . '            </array>' . "\n"
            . '            <key>PayloadDisplayName</key>' . "\n"
            . '            <string>AirPrint - ' . e($cupsPrinter->name) . '</string>' . "\n"
            . '            <key>PayloadIdentifier</key>' . "\n"
            . '            <string>com.samirgroup.noc.airprint.' . e($cupsPrinter->queue_name) . '</string>' . "\n"
            . '            <key>PayloadType</key>' . "\n"
            . '            <string>com.apple.airprint</string>' . "\n"
            . '            <key>PayloadUUID</key>' . "\n"
            . '            <string>' . $payloadUuid . '</string>' . "\n"
            . '            <key>PayloadVersion</key>' . "\n"
            . '            <integer>1</integer>' . "\n"
            . '        </dict>' . "\n"
            . '    </array>' . "\n"
            . '    <key>PayloadDisplayName</key>' . "\n"
            . '    <string>' . e($cupsPrinter->name) . ' — SG NOC</string>' . "\n"
            . '    <key>PayloadIdentifier</key>' . "\n"
            . '    <string>com.samirgroup.noc.print.' . e($cupsPrinter->queue_name) . '</string>' . "\n"
            . '    <key>PayloadOrganization</key>' . "\n"
            . '    <string>Samir Group IT</string>' . "\n"
            . '    <key>PayloadType</key>' . "\n"
            . '    <string>Configuration</string>' . "\n"
            . '    <key>PayloadUUID</key>' . "\n"
            . '    <string>' . $profileUuid . '</string>' . "\n"
            . '    <key>PayloadVersion</key>' . "\n"
            . '    <integer>1</integer>' . "\n"
            . '    <key>PayloadRemovalDisallowed</key>' . "\n"
            . '    <false/>' . "\n"
            . '</dict>' . "\n"
            . '</plist>';

        $filename = $cupsPrinter->queue_name . '-airprint.mobileconfig';

        return response($xml, 200, [
            'Content-Type'        => 'application/x-apple-aspen-config',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
