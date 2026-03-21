<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Printer;
use App\Models\PrinterDeployToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PrinterSetupController extends Controller
{
    /**
     * GET /printer-setup?token=xxx
     * Show the printer setup page with all printers for the employee's branch.
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

        // Load all active printers for this branch
        $printers = Printer::where('branch_id', $token->branch_id)
            ->orderBy('printer_name')
            ->get();

        // Mark token as used on first open (idempotent)
        if (! $token->isUsed()) {
            $token->markUsed();
        }

        return view('public.printer_setup', [
            'token'    => $token,
            'employee' => $token->employee,
            'branch'   => $token->branch,
            'printers' => $printers,
        ]);
    }

    /**
     * GET /printer-setup/script?token=xxx&printer_id=5&os=windows
     * Download an install script (.bat or .sh) for a specific printer.
     */
    public function downloadScript(Request $request): Response
    {
        $tokenString = $request->query('token');
        $printerId   = $request->query('printer_id');
        $os          = $request->query('os', 'windows'); // 'windows' | 'mac'

        $token = PrinterDeployToken::where('token', $tokenString)->first();

        if (! $token || $token->isExpired()) {
            abort(404, 'This link is invalid or has expired.');
        }

        $printer = Printer::where('id', $printerId)
            ->where('branch_id', $token->branch_id) // security: printer must belong to token's branch
            ->firstOrFail();

        $printerName = $printer->printer_name;
        $ip          = $printer->ip_address ?? '127.0.0.1';
        $shareName   = preg_replace('/[^A-Za-z0-9_-]/', '', $printerName);
        $safeName    = preg_replace('/[^A-Za-z0-9_-]/', '_', $printerName);
        $model       = trim(($printer->manufacturer ?? '') . ' ' . ($printer->model ?? ''));

        if ($os === 'windows') {
            $script   = $this->buildWindowsScript($printerName, $ip, $shareName, $model);
            $filename = "install_{$safeName}.bat";
            $mime     = 'application/octet-stream';
        } else {
            $script   = $this->buildMacScript($printerName, $ip, $safeName, $model);
            $filename = "install_{$safeName}.sh";
            $mime     = 'application/octet-stream';
        }

        return response($script, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ─── Script builders ─────────────────────────────────────────

    private function buildWindowsScript(string $name, string $ip, string $share, string $model): string
    {
        return <<<BAT
        @echo off
        echo Installing printer: {$name}...
        rundll32 printui.dll,PrintUIEntry /in /n "\\\\{$ip}\\{$share}"
        if %errorlevel% == 0 (
            echo Printer installed successfully!
        ) else (
            echo Installation failed. Please contact IT support.
            echo Manual path: \\\\{$ip}\\{$share}
        )
        pause
        BAT;
    }

    private function buildMacScript(string $name, string $ip, string $safeName, string $model): string
    {
        return <<<SH
        #!/bin/bash
        echo "Installing printer: {$name}..."
        lpadmin -p "{$safeName}" -E -v "ipp://{$ip}/ipp/print" -m everywhere -D "{$name}"
        if [ $? -eq 0 ]; then
            echo "Done! Check System Preferences > Printers."
        else
            echo "Could not install automatically. Try adding printer manually via System Preferences."
        fi
        SH;
    }
}
