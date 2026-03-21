<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\PrinterDeployToken;
use App\Models\PrinterDriver;
use App\Services\PrinterScriptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PrinterSetupController extends Controller
{
    /**
     * GET /printer-setup?token=xxx
     * Show printer setup page with driver availability info per printer.
     */
    public function show(Request $request): View
    {
        $tokenString = $request->query('token');
        $token       = PrinterDeployToken::where('token', $tokenString)
            ->with(['employee', 'branch'])
            ->first();

        if (! $token) {
            abort(404, 'This setup link is invalid or does not exist.');
        }

        if ($token->isExpired()) {
            return view('public.printer_setup_expired', compact('token'));
        }

        $printers = Printer::where('branch_id', $token->branch_id)
            ->orderBy('printer_name')
            ->get();

        // Build per-printer driver availability data
        $printerData = $printers->map(function ($p) {
            return [
                'printer'    => $p,
                'win_driver' => PrinterDriver::findForPrinter($p, 'windows_x64'),
                'mac_driver' => PrinterDriver::findForPrinter($p, 'mac'),
            ];
        });

        return view('public.printer_setup', compact('token', 'printerData'));
    }

    /**
     * GET /printer-setup/script?token=xxx&printer_id=5&os=windows
     * Download an install script or ZIP package for a specific printer.
     */
    public function downloadScript(Request $request)
    {
        $request->validate([
            'token'      => 'required|string',
            'printer_id' => 'required|exists:printers,id',
            'os'         => 'required|in:windows,mac',
        ]);

        $token = PrinterDeployToken::where('token', $request->token)
            ->valid()
            ->first();

        if (! $token) {
            abort(404, 'This link is invalid or has expired.');
        }

        $printer = Printer::with('branch')->findOrFail($request->printer_id);
        abort_if($printer->branch_id !== $token->branch_id, 403, 'Printer does not belong to your branch.');

        $service = new PrinterScriptService();

        if ($request->os === 'windows') {
            $driver = PrinterDriver::findForPrinter($printer, 'windows_x64');

            $driverDownloadUrl = null;
            if ($driver && $driver->driver_file_path) {
                $driverDownloadUrl = url('/printer-setup/driver?token=' . $token->token . '&driver_id=' . $driver->id);
            }

            $bat      = $service->generateWindowsBat($printer, $driver, $driverDownloadUrl);
            $filename = 'install-' . Str::slug($printer->printer_name) . '.bat';
            return response($bat, 200, [
                'Content-Type'        => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        // macOS
        $driver   = PrinterDriver::findForPrinter($printer, 'mac');
        $sh       = $service->generateMacSh($printer, $driver);
        $filename = 'install-' . Str::slug($printer->printer_name) . '.sh';
        return response($sh, 200, [
            'Content-Type'        => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * GET /printer-setup/driver?token=xxx&driver_id=5
     * Serve a private driver zip file, validated via deploy token.
     */
    public function downloadDriver(Request $request)
    {
        $request->validate([
            'token'     => 'required|string',
            'driver_id' => 'required|exists:printer_drivers,id',
        ]);

        $token = PrinterDeployToken::where('token', $request->token)
            ->valid()
            ->first();

        if (! $token) {
            abort(404, 'This link is invalid or has expired.');
        }

        $driver = PrinterDriver::findOrFail($request->driver_id);

        if (! $driver->driver_file_path || ! Storage::disk('private')->exists($driver->driver_file_path)) {
            abort(404, 'Driver file not found.');
        }

        $filename = $driver->original_filename ?? basename($driver->driver_file_path);

        return Storage::disk('private')->download($driver->driver_file_path, $filename);
    }
}
