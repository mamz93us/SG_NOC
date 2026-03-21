<?php

namespace App\Services;

use App\Models\Printer;
use App\Models\PrinterDriver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrinterScriptService
{
    public function __construct()
    {
        if (! class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive PHP extension is required for printer package generation.');
        }
    }

    // ── Method A: Windows .bat ────────────────────────────────────

    public function generateWindowsBat(Printer $printer, ?PrinterDriver $driver, ?string $driverDownloadUrl = null): string
    {
        $printerName = $printer->printer_name;
        $ip          = $printer->ip_address ?? '127.0.0.1';
        $portName    = 'IP_' . $ip;
        $location    = $printer->locationLabel();
        $branch      = $printer->branch?->name ?? 'N/A';
        $driverName  = $driver?->driver_name ?? 'Microsoft IPP Class Driver';

        $header = "@echo off\r\n"
            . "setlocal enabledelayedexpansion\r\n"
            . "echo ============================================\r\n"
            . "echo  Printer Setup - Samir Group IT\r\n"
            . "echo  Printer : {$printerName}\r\n"
            . "echo  Location: {$location}\r\n"
            . "echo  Branch  : {$branch}\r\n"
            . "echo  IP      : {$ip}\r\n"
            . "echo ============================================\r\n"
            . "echo.\r\n"
            . "REM Check for admin rights\r\n"
            . "net session >nul 2>&1\r\n"
            . "IF %ERRORLEVEL% NEQ 0 (\r\n"
            . "    echo ERROR: Please right-click this script and Run as Administrator.\r\n"
            . "    pause & exit /b 1\r\n"
            . ")\r\n"
            . "SET PORT_NAME={$portName}\r\n"
            . "SET PRINTER_IP={$ip}\r\n"
            . "SET \"PRINTER_NAME={$printerName}\"\r\n"
            . "SET \"DRIVER_NAME={$driverName}\"\r\n";

        if ($driverDownloadUrl !== null) {
            // CASE A: Driver file is available online — download, extract, install
            $pnpCmd = $driver->inf_path
                ? "pnputil /add-driver \"%EXTRACT_DIR%\\{$driver->inf_path}\" /install"
                : 'pnputil /add-driver "%EXTRACT_DIR%\*.inf" /subdirs /install';

            return $header
                . "SET DRIVER_ZIP=%TEMP%\\sg_printer_driver.zip\r\n"
                . "SET EXTRACT_DIR=%TEMP%\\sg_printer_driver_extracted\r\n"
                . "SET \"DRIVER_URL={$driverDownloadUrl}\"\r\n"
                . "echo.\r\n"
                . "echo [1/5] Downloading driver package...\r\n"
                . "powershell -Command \"Invoke-WebRequest -Uri '!DRIVER_URL!' -OutFile '!DRIVER_ZIP!' -UseBasicParsing\"\r\n"
                . "IF !ERRORLEVEL! NEQ 0 (\r\n"
                . "    echo ERROR: Failed to download driver. Check your internet connection.\r\n"
                . "    echo Contact IT: support@samirgroup.com\r\n"
                . "    pause & exit /b 1\r\n"
                . ")\r\n"
                . "echo       Driver downloaded.\r\n"
                . "echo [2/5] Extracting driver...\r\n"
                . "IF EXIST \"%EXTRACT_DIR%\" rmdir /s /q \"%EXTRACT_DIR%\"\r\n"
                . "powershell -Command \"Expand-Archive -Path '!DRIVER_ZIP!' -DestinationPath '!EXTRACT_DIR!' -Force\"\r\n"
                . "IF !ERRORLEVEL! NEQ 0 (\r\n"
                . "    echo ERROR: Extraction failed.\r\n"
                . "    pause & exit /b 1\r\n"
                . ")\r\n"
                . "echo       Extracted.\r\n"
                . "echo [3/5] Installing driver...\r\n"
                . "{$pnpCmd}\r\n"
                . "IF !ERRORLEVEL! NEQ 0 (\r\n"
                . "    echo WARNING: Driver install had issues. Continuing...\r\n"
                . ") ELSE (\r\n"
                . "    echo       Driver installed successfully.\r\n"
                . ")\r\n"
                . "echo [4/5] Creating TCP/IP port...\r\n"
                . "powershell -Command \"Get-PrinterPort -Name '%PORT_NAME%' -ErrorAction SilentlyContinue\" >nul 2>&1\r\n"
                . "IF !ERRORLEVEL! NEQ 0 (\r\n"
                . "    powershell -Command \"Add-PrinterPort -Name '%PORT_NAME%' -PrinterHostAddress '%PRINTER_IP%'\"\r\n"
                . "    echo       Port created.\r\n"
                . ") ELSE (\r\n"
                . "    echo       Port already exists. Skipping.\r\n"
                . ")\r\n"
                . "echo [5/5] Adding printer...\r\n"
                . "powershell -Command \"Remove-Printer -Name '%PRINTER_NAME%' -ErrorAction SilentlyContinue\"\r\n"
                . "powershell -Command \"Add-Printer -Name '%PRINTER_NAME%' -DriverName '!DRIVER_NAME!' -PortName '%PORT_NAME%'\"\r\n"
                . "IF !ERRORLEVEL! NEQ 0 (\r\n"
                . "    echo Trying IPP fallback driver...\r\n"
                . "    powershell -Command \"Add-Printer -Name '%PRINTER_NAME%' -DriverName 'Microsoft IPP Class Driver' -PortName '%PORT_NAME%'\"\r\n"
                . ")\r\n"
                . "echo Verifying installation...\r\n"
                . "powershell -Command \"Get-Printer -Name '%PRINTER_NAME%'\" >nul 2>&1\r\n"
                . "IF !ERRORLEVEL! EQU 0 (\r\n"
                . "    echo.\r\n"
                . "    echo ============================================\r\n"
                . "    echo  DONE! %PRINTER_NAME% is ready to use.\r\n"
                . "    echo  Check: Control Panel ^> Printers\r\n"
                . "    echo ============================================\r\n"
                . ") ELSE (\r\n"
                . "    echo.\r\n"
                . "    echo ============================================\r\n"
                . "    echo  WARNING: Could not verify printer install.\r\n"
                . "    echo  Try manually: Settings ^> Bluetooth ^& devices\r\n"
                . "    echo               ^> Printers ^& scanners ^> Add device\r\n"
                . "    echo  IP Address: %PRINTER_IP%\r\n"
                . "    echo  Contact IT: support@samirgroup.com\r\n"
                . "    echo ============================================\r\n"
                . ")\r\n"
                . "REM Cleanup temp files\r\n"
                . "IF EXIST \"%DRIVER_ZIP%\" del /f /q \"%DRIVER_ZIP%\"\r\n"
                . "IF EXIST \"%EXTRACT_DIR%\" rmdir /s /q \"%EXTRACT_DIR%\"\r\n"
                . "pause\r\n";
        }

        // CASE B: No driver download URL — PowerShell IPP install
        return $header
            . "echo [1/3] Creating TCP/IP printer port...\r\n"
            . "powershell -Command \"Get-PrinterPort -Name '%PORT_NAME%' -ErrorAction SilentlyContinue\" >nul 2>&1\r\n"
            . "IF !ERRORLEVEL! NEQ 0 (\r\n"
            . "    powershell -Command \"Add-PrinterPort -Name '%PORT_NAME%' -PrinterHostAddress '%PRINTER_IP%'\"\r\n"
            . "    echo       Port created.\r\n"
            . ") ELSE (\r\n"
            . "    echo       Port already exists. Skipping.\r\n"
            . ")\r\n"
            . "echo [2/3] Adding printer...\r\n"
            . "powershell -Command \"Remove-Printer -Name '%PRINTER_NAME%' -ErrorAction SilentlyContinue\"\r\n"
            . "powershell -Command \"Add-Printer -Name '%PRINTER_NAME%' -DriverName 'Microsoft IPP Class Driver' -PortName '%PORT_NAME%'\"\r\n"
            . "IF !ERRORLEVEL! NEQ 0 (\r\n"
            . "    echo Trying alternative driver...\r\n"
            . "    powershell -Command \"Add-Printer -Name '%PRINTER_NAME%' -DriverName 'Microsoft Print To PDF' -PortName '%PORT_NAME%'\"\r\n"
            . ")\r\n"
            . "echo [3/3] Verifying installation...\r\n"
            . "powershell -Command \"Get-Printer -Name '%PRINTER_NAME%'\" >nul 2>&1\r\n"
            . "IF !ERRORLEVEL! EQU 0 (\r\n"
            . "    echo.\r\n"
            . "    echo ============================================\r\n"
            . "    echo  DONE! %PRINTER_NAME% is ready to use.\r\n"
            . "    echo  Check: Control Panel ^> Printers\r\n"
            . "    echo ============================================\r\n"
            . ") ELSE (\r\n"
            . "    echo.\r\n"
            . "    echo ============================================\r\n"
            . "    echo  WARNING: Could not verify printer install.\r\n"
            . "    echo  Try manually: Settings ^> Bluetooth ^& devices\r\n"
            . "    echo               ^> Printers ^& scanners ^> Add device\r\n"
            . "    echo  IP Address: %PRINTER_IP%\r\n"
            . "    echo  Contact IT: support@samirgroup.com\r\n"
            . "    echo ============================================\r\n"
            . ")\r\n"
            . "pause\r\n";
    }

    // ── Method B: macOS .sh ───────────────────────────────────────

    public function generateMacSh(Printer $printer, ?PrinterDriver $driver): string
    {
        $printerName = $printer->printer_name;
        $ip          = $printer->ip_address ?? '127.0.0.1';
        $location    = $printer->locationLabel();
        $branch      = $printer->branch?->name ?? 'N/A';
        $safeName    = preg_replace('/[^A-Za-z0-9_]/', '_', $printerName);

        $driverNote = '';
        if ($driver && $driver->driver_name) {
            $driverNote = "echo \"Driver: {$driver->driver_name}\"\n"
                . "echo \"NOTE: Install the manufacturer driver package first if available.\"\n"
                . "echo \"\"\n";
        }

        return "#!/bin/bash\n"
            . "set -e\n"
            . "echo \"============================================\"\n"
            . "echo \" Printer Setup - Samir Group IT\"\n"
            . "echo \" Printer : {$printerName}\"\n"
            . "echo \" Location: {$location}\"\n"
            . "echo \" Branch  : {$branch}\"\n"
            . "echo \"============================================\"\n"
            . "echo \"\"\n"
            . "PRINTER_NAME=\"{$safeName}\"\n"
            . "PRINTER_IP=\"{$ip}\"\n"
            . $driverNote
            . "echo \"Adding printer via IPP...\"\n"
            . "lpadmin -p \"\$PRINTER_NAME\" -E -v \"ipp://\$PRINTER_IP/ipp/print\" -m everywhere\n"
            . "if lpstat -p \"\$PRINTER_NAME\" > /dev/null 2>&1; then\n"
            . "    echo \"✅ Printer '\$PRINTER_NAME' added successfully!\"\n"
            . "    echo \"   Check: System Settings > Printers & Scanners\"\n"
            . "else\n"
            . "    echo \"❌ Automatic setup failed.\"\n"
            . "    echo \"   Manual: System Settings > Printers & Scanners > Add Printer\"\n"
            . "    echo \"   Address: \$PRINTER_IP\"\n"
            . "    exit 1\n"
            . "fi\n";
    }

    // ── Method C: Intune PowerShell .ps1 ─────────────────────────

    public function generateIntunePowerShell(Printer $printer, ?PrinterDriver $driver): string
    {
        $printerName      = $printer->printer_name;
        $ip               = $printer->ip_address ?? '127.0.0.1';
        $branch           = $printer->branch?->name ?? 'N/A';
        $driverOrFallback = $driver?->driver_name ?? 'Microsoft IPP Class Driver';
        $generatedDate    = now()->toDateString();

        return "# ============================================================\n"
            . "# Generated by SG NOC - Samir Group IT\n"
            . "# Printer  : {$printerName}\n"
            . "# Branch   : {$branch}\n"
            . "# IP       : {$ip}\n"
            . "# Driver   : {$driverName}\n"
            . "# Generated: {$generatedDate}\n"
            . "#\n"
            . "# Intune Deployment Instructions:\n"
            . "# 1. Intune > Devices > Scripts > Add > Windows 10 and later\n"
            . "# 2. Upload this .ps1 file\n"
            . "# 3. Run script in 64-bit PowerShell: Yes\n"
            . "# 4. Run as account: System\n"
            . "# 5. Assign to group: {$branch} Azure AD Group\n"
            . "# ============================================================\n"
            . "\$ErrorActionPreference = \"Stop\"\n"
            . "\$PrinterName = \"{$printerName}\"\n"
            . "\$PrinterIP   = \"{$ip}\"\n"
            . "\$PortName    = \"IP_{$ip}\"\n"
            . "\$DriverName  = \"{$driverOrFallback}\"\n"
            . "\$LogSource   = \"SG_NOC_PrinterSetup\"\n"
            . "\n"
            . "if (![System.Diagnostics.EventLog]::SourceExists(\$LogSource)) {\n"
            . "    New-EventLog -LogName Application -Source \$LogSource\n"
            . "}\n"
            . "\n"
            . "try {\n"
            . "    Write-Host \"[1/4] Checking port \$PortName...\"\n"
            . "    if (!(Get-PrinterPort -Name \$PortName -ErrorAction SilentlyContinue)) {\n"
            . "        Add-PrinterPort -Name \$PortName -PrinterHostAddress \$PrinterIP\n"
            . "        Write-EventLog -LogName Application -Source \$LogSource -EntryType Information -EventId 1001 -Message \"Created port \$PortName for \$PrinterIP\"\n"
            . "        Write-Host \"      Port created.\"\n"
            . "    } else {\n"
            . "        Write-Host \"      Port already exists. Skipping.\"\n"
            . "    }\n"
            . "\n"
            . "    Write-Host \"[2/4] Verifying driver...\"\n"
            . "    if (!(Get-PrinterDriver -Name \$DriverName -ErrorAction SilentlyContinue)) {\n"
            . "        Write-EventLog -LogName Application -Source \$LogSource -EntryType Warning -EventId 1002 -Message \"Driver not found, using IPP fallback\"\n"
            . "        \$DriverName = \"Microsoft IPP Class Driver\"\n"
            . "        Write-Host \"      Using fallback driver.\"\n"
            . "    } else {\n"
            . "        Write-Host \"      Driver found: \$DriverName\"\n"
            . "    }\n"
            . "\n"
            . "    Write-Host \"[3/4] Removing old printer entry if exists...\"\n"
            . "    if (Get-Printer -Name \$PrinterName -ErrorAction SilentlyContinue) {\n"
            . "        Remove-Printer -Name \$PrinterName -Confirm:\$false\n"
            . "        Write-Host \"      Old entry removed.\"\n"
            . "    }\n"
            . "\n"
            . "    Write-Host \"[4/4] Adding printer...\"\n"
            . "    Add-Printer -Name \$PrinterName -DriverName \$DriverName -PortName \$PortName\n"
            . "    Write-EventLog -LogName Application -Source \$LogSource -EntryType Information -EventId 1003 -Message \"SUCCESS: Printer \$PrinterName installed via SG NOC.\"\n"
            . "\n"
            . "    Write-Host \"\"\n"
            . "    Write-Host \"============================================\" -ForegroundColor Green\n"
            . "    Write-Host \" SUCCESS: \$PrinterName installed!\" -ForegroundColor Green\n"
            . "    Write-Host \" Check: Settings > Printers and Scanners\" -ForegroundColor Green\n"
            . "    Write-Host \"============================================\" -ForegroundColor Green\n"
            . "    exit 0\n"
            . "} catch {\n"
            . '    $errMsg = "FAILED to install printer ${PrinterName}: " + $_.Exception.Message' . "\n"
            . "    Write-EventLog -LogName Application -Source \$LogSource -EntryType Error -EventId 1099 -Message \$errMsg\n"
            . "\n"
            . "    Write-Host \"\"\n"
            . "    Write-Host \"============================================\" -ForegroundColor Red\n"
            . "    Write-Host \" ERROR: \$errMsg\" -ForegroundColor Red\n"
            . "    Write-Host \" Contact IT: support@samirgroup.com\" -ForegroundColor Red\n"
            . "    Write-Host \"============================================\" -ForegroundColor Red\n"
            . "    exit 1\n"
            . "}\n";
    }

    // ── Method D: Build Windows zip (script + driver) ─────────────

    public function buildWindowsZip(Printer $printer, PrinterDriver $driver): string
    {
        $tempDir = sys_get_temp_dir() . '/printer_' . $printer->id . '_' . time();
        mkdir($tempDir, 0755, true);

        // Write install.bat
        file_put_contents($tempDir . '/install.bat', $this->generateWindowsBat($printer, $driver));

        // Copy driver zip as driver.zip (script expects this exact name)
        if ($driver->driver_file_path) {
            copy(Storage::disk('private')->path($driver->driver_file_path), $tempDir . '/driver.zip');
        }

        // Write README.txt
        $readme  = "Printer Setup Package — Samir Group IT\n";
        $readme .= "========================================\n";
        $readme .= "Printer : {$printer->printer_name}\n";
        $readme .= "Branch  : {$printer->branch?->name}\n";
        $readme .= "IP      : {$printer->ip_address}\n";
        $readme .= "Driver  : {$driver->driver_name}\n";
        $readme .= "Version : {$driver->version}\n\n";
        $readme .= "HOW TO INSTALL:\n";
        $readme .= "1. Extract this entire zip to a folder\n";
        $readme .= "2. Right-click install.bat\n";
        $readme .= "3. Select 'Run as Administrator'\n";
        $readme .= "4. Follow on-screen instructions\n\n";
        $readme .= "Support: IT Department — support@samirgroup.com\n";
        file_put_contents($tempDir . '/README.txt', $readme);

        // Build final zip
        $zipPath = sys_get_temp_dir() . '/printer_pkg_' . $printer->id . '_' . time() . '.zip';
        $zip     = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach (glob($tempDir . '/*') as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        // Cleanup temp dir
        array_map('unlink', glob($tempDir . '/*'));
        rmdir($tempDir);

        return $zipPath;
    }
}
