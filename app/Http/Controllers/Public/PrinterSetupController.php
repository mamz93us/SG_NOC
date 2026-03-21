<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\PrinterDeployToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PrinterSetupController extends Controller
{
    /**
     * GET /printer-setup?token=xxx
     * Show the printer setup page with download buttons.
     */
    public function show(Request $request): View|\Illuminate\Http\RedirectResponse
    {
        $tokenString = $request->query('token');
        $token       = PrinterDeployToken::where('token', $tokenString)->with('printer')->first();

        if (! $token || ! $token->isValid()) {
            abort(404, 'This setup link is invalid or has expired.');
        }

        return view('public.printer_setup', [
            'token'  => $token,
            'config' => $token->printer_config ?? [],
        ]);
    }

    /**
     * GET /printer-setup/script?token=xxx&os=windows
     * Download an install script (.bat or .sh).
     */
    public function downloadScript(Request $request): Response
    {
        $tokenString = $request->query('token');
        $os          = $request->query('os', 'windows'); // 'windows' | 'linux'

        $token = PrinterDeployToken::where('token', $tokenString)->first();

        if (! $token || ! $token->isValid()) {
            abort(404, 'This link is invalid or has expired.');
        }

        $config      = $token->printer_config ?? [];
        $printerName = $config['printer_name'] ?? 'OfficePrinter';
        $ip          = $config['ip_address']   ?? '127.0.0.1';
        $shareName   = $config['share_name']   ?? preg_replace('/[^A-Za-z0-9_-]/', '', $printerName);
        $model       = trim(($config['manufacturer'] ?? '') . ' ' . ($config['model'] ?? ''));

        if ($os === 'windows') {
            $script = $this->buildWindowsScript($printerName, $ip, $shareName, $model);
            $filename = 'install_printer.bat';
            $mime     = 'application/octet-stream';
        } else {
            $script = $this->buildLinuxScript($printerName, $ip, $shareName, $model);
            $filename = 'install_printer.sh';
            $mime     = 'text/plain';
        }

        return response($script, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function buildWindowsScript(string $name, string $ip, string $share, string $model): string
    {
        return <<<BAT
@echo off
:: =========================================================
::  SG NOC - Printer Installation Script (Windows)
::  Printer : {$name}
::  IP      : {$ip}
::  Model   : {$model}
:: =========================================================

echo Installing printer: {$name} ...

:: Add TCP/IP port
netsh interface portproxy add v4tov4 listenport=9100 connectaddress={$ip} connectport=9100 >NUL 2>&1
printui.dll,PrintUIEntry /ga /n "{$name}" /b "{$name}" /if /f "%WINDIR%\inf\ntprint.inf" /m "Generic / Text Only" /r "IP_{$ip}" /q

:: Alternative: direct network path connection
net use \\\\{$ip} /persistent:yes >NUL 2>&1

:: Add printer via WScript.Shell (PowerShell)
powershell -NoProfile -Command ^
  "Add-Printer -ConnectionName '\\\\{$ip}\\{$share}'" >NUL 2>&1

if %ERRORLEVEL% == 0 (
    echo SUCCESS: Printer '{$name}' installed.
) else (
    echo NOTE: Manual installation may be required.
    echo Open: Control Panel > Devices and Printers > Add a printer
    echo Use network address: \\\\{$ip}\\{$share}
)

pause
BAT;
    }

    private function buildLinuxScript(string $name, string $ip, string $share, string $model): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $name);

        return <<<SH
#!/bin/bash
# ==========================================================
#  SG NOC - Printer Installation Script (Linux/macOS)
#  Printer : {$name}
#  IP      : {$ip}
#  Model   : {$model}
# ==========================================================

PRINTER_NAME="{$safeName}"
PRINTER_IP="{$ip}"

echo "Installing printer: \$PRINTER_NAME ..."

# macOS
if [[ "\$(uname)" == "Darwin" ]]; then
    lpadmin -p "\$PRINTER_NAME" -E -v "ipp://\$PRINTER_IP/ipp/print" -m everywhere \
        -D "{$name}" -L "{$model}" && \\
    echo "SUCCESS: Printer '\$PRINTER_NAME' installed on macOS." || \\
    echo "ERROR: Could not install printer automatically. Try System Preferences > Printers."

# Linux (CUPS)
elif command -v lpadmin &> /dev/null; then
    lpadmin -p "\$PRINTER_NAME" -E -v "socket://\$PRINTER_IP:9100" -m everywhere \
        -D "{$name}" && \\
    echo "SUCCESS: Printer '\$PRINTER_NAME' installed via CUPS." || \\
    echo "ERROR: Could not install printer. Run: sudo lpadmin -p '\$PRINTER_NAME' -E -v 'socket://\$PRINTER_IP:9100' -m everywhere"
else
    echo "ERROR: CUPS not found. Please install cups: sudo apt install cups"
fi
SH;
    }
}
