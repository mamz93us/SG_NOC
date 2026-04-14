<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Printer Setup Instructions</title>
</head>
<body style="margin:0;padding:0;background-color:#f0f4f8;font-family:'Segoe UI',Arial,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f4f8;padding:30px 15px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">

    {{-- Header --}}
    <tr>
        <td style="background:linear-gradient(135deg,#1e3a5f 0%,#2d6a9f 100%);padding:32px 40px;text-align:center;">
            <div style="font-size:40px;margin-bottom:10px;">&#x1F5A8;</div>
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Printer Setup Instructions</h1>
            <p style="margin:8px 0 0;color:#b0d0f0;font-size:14px;">{{ $cupsPrinter->name }} &mdash; {{ $cupsPrinter->branch?->name ?? 'Network Printer' }}</p>
        </td>
    </tr>

    {{-- Body --}}
    <tr>
        <td style="padding:32px 40px;">

            {{-- Greeting --}}
            <p style="margin:0 0 20px;color:#333;font-size:15px;">
                Hi <strong>{{ $recipientName }}</strong>,<br><br>
                A network printer has been set up for you. Follow the instructions below for your device to start printing.
            </p>

            {{-- Printer Info --}}
            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:24px;font-size:13px;background-color:#f8f9fa;border-radius:6px;">
                <tr>
                    <td style="padding:10px 14px;color:#555;font-weight:600;border-bottom:1px solid #eee;width:35%;">Printer Name</td>
                    <td style="padding:10px 14px;color:#333;border-bottom:1px solid #eee;">{{ $cupsPrinter->name }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 14px;color:#555;font-weight:600;border-bottom:1px solid #eee;">Location</td>
                    <td style="padding:10px 14px;color:#333;border-bottom:1px solid #eee;">{{ $cupsPrinter->location ?? $cupsPrinter->branch?->name ?? '—' }}</td>
                </tr>
                <tr>
                    <td style="padding:10px 14px;color:#555;font-weight:600;">IPP Address</td>
                    <td style="padding:10px 14px;color:#333;font-family:monospace;font-size:12px;">{{ $ippAddress }}</td>
                </tr>
            </table>

            {{-- ── iPhone / iPad ── --}}
            <div style="background-color:#f0f7ff;border-left:4px solid #007aff;padding:18px 20px;border-radius:4px;margin-bottom:20px;">
                <h3 style="margin:0 0 10px;color:#007aff;font-size:16px;">&#x1F4F1; iPhone / iPad (AirPrint)</h3>
                <p style="margin:0 0 12px;color:#333;font-size:13px;line-height:1.6;">
                    The easiest way to add this printer on your iPhone or iPad:
                </p>
                <ol style="margin:0 0 14px;padding-left:20px;color:#333;font-size:13px;line-height:1.8;">
                    <li>Open the camera app on your iPhone</li>
                    <li>Scan the QR code below (or tap the button)</li>
                    <li>Tap <strong>"Allow"</strong> when prompted to download the profile</li>
                    <li>Go to <strong>Settings &rarr; General &rarr; VPN &amp; Device Management</strong></li>
                    <li>Tap the downloaded profile &rarr; tap <strong>"Install"</strong></li>
                    <li>Enter your passcode if asked &rarr; tap <strong>"Install"</strong> again</li>
                    <li>Done! The printer will appear automatically when you tap <strong>Print</strong> in any app</li>
                </ol>

                {{-- QR Code --}}
                <div style="text-align:center;margin:16px 0;">
                    <div style="display:inline-block;background:#ffffff;padding:12px;border:1px solid #ddd;border-radius:8px;">
                        <img src="{{ $qrCid }}" alt="Scan to install AirPrint profile" width="180" height="180" style="display:block;">
                    </div>
                    <p style="margin:8px 0 0;color:#555;font-size:12px;font-weight:600;">
                        Scan with your iPhone camera
                    </p>
                </div>

                {{-- AirPrint Download Button --}}
                <div style="text-align:center;margin:16px 0 8px;">
                    <a href="{{ $airprintUrl }}"
                       style="background-color:#007aff;color:#ffffff;padding:12px 28px;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;display:inline-block;">
                        &#x2B07; Install AirPrint Profile
                    </a>
                </div>
                <p style="margin:8px 0 0;color:#888;font-size:11px;text-align:center;">
                    Or tap the button above on your iPhone/iPad
                </p>
            </div>

            {{-- ── Windows ── --}}
            <div style="background-color:#f5f0ff;border-left:4px solid #5b2d8e;padding:18px 20px;border-radius:4px;margin-bottom:20px;">
                <h3 style="margin:0 0 10px;color:#5b2d8e;font-size:16px;">&#x1F5A5; Windows</h3>
                <ol style="margin:0 0 10px;padding-left:20px;color:#333;font-size:13px;line-height:1.8;">
                    <li>Open <strong>Settings &rarr; Bluetooth &amp; devices &rarr; Printers &amp; scanners</strong></li>
                    <li>Click <strong>"Add device"</strong></li>
                    <li>Click <strong>"Add manually"</strong> (or "The printer I want isn't listed")</li>
                    <li>Select <strong>"Select a shared printer by name"</strong></li>
                    <li>Enter the address below and click <strong>Next</strong>:</li>
                </ol>
                <div style="background-color:#2d2d2d;color:#e0e0e0;padding:12px 16px;border-radius:4px;font-family:monospace;font-size:13px;word-break:break-all;margin:8px 0 10px;">
                    {{ $httpAddress }}
                </div>
                <ol start="6" style="margin:0;padding-left:20px;color:#333;font-size:13px;line-height:1.8;">
                    <li>If asked for a driver, select <strong>"Microsoft IPP Class Driver"</strong></li>
                    <li>Click <strong>Finish</strong> &mdash; print a test page to verify</li>
                </ol>
            </div>

            {{-- ── Android ── --}}
            <div style="background-color:#f0faf0;border-left:4px solid #34a853;padding:18px 20px;border-radius:4px;margin-bottom:20px;">
                <h3 style="margin:0 0 10px;color:#34a853;font-size:16px;">&#x1F4F1; Android</h3>
                <ol style="margin:0 0 10px;padding-left:20px;color:#333;font-size:13px;line-height:1.8;">
                    <li>Open <strong>Settings &rarr; Connected devices &rarr; Connection preferences &rarr; Printing</strong></li>
                    <li>Tap <strong>"Default Print Service"</strong> and make sure it's turned <strong>ON</strong></li>
                    <li>Tap the <strong>&#x22EE;</strong> (three dots) menu &rarr; <strong>"Add printer"</strong></li>
                    <li>Select <strong>"Add printer by IP address"</strong></li>
                    <li>Enter the following details:</li>
                </ol>
                <table cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:8px 0 12px 20px;font-size:13px;">
                    <tr>
                        <td style="padding:4px 12px 4px 0;color:#555;font-weight:600;">Hostname:</td>
                        <td style="padding:4px 0;color:#333;font-family:monospace;">{{ $domain }}</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 12px 4px 0;color:#555;font-weight:600;">Port:</td>
                        <td style="padding:4px 0;color:#333;font-family:monospace;">631</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 12px 4px 0;color:#555;font-weight:600;">Path:</td>
                        <td style="padding:4px 0;color:#333;font-family:monospace;">printers/{{ $cupsPrinter->queue_name }}</td>
                    </tr>
                </table>
                <p style="margin:0;color:#555;font-size:12px;">
                    Alternatively, some Android devices allow entering the full IPP address:<br>
                    <code style="background-color:#e8f5e9;padding:2px 6px;border-radius:3px;font-size:12px;">{{ $ippAddress }}</code>
                </p>
            </div>

            {{-- ── macOS ── --}}
            <div style="background-color:#f5f5f5;border-left:4px solid #555;padding:18px 20px;border-radius:4px;margin-bottom:20px;">
                <h3 style="margin:0 0 10px;color:#333;font-size:16px;">&#x1F4BB; macOS</h3>
                <ol style="margin:0;padding-left:20px;color:#333;font-size:13px;line-height:1.8;">
                    <li>Open <strong>System Settings &rarr; Printers &amp; Scanners</strong></li>
                    <li>Click <strong>"Add Printer, Scanner, or Fax..."</strong></li>
                    <li>Click the <strong>IP</strong> tab (globe icon)</li>
                    <li>Protocol: <strong>Internet Printing Protocol (IPP)</strong></li>
                    <li>Address: <code style="background:#eee;padding:1px 4px;border-radius:3px;">{{ $domain }}</code></li>
                    <li>Queue: <code style="background:#eee;padding:1px 4px;border-radius:3px;">printers/{{ $cupsPrinter->queue_name }}</code></li>
                    <li>Click <strong>Add</strong></li>
                </ol>
            </div>

            {{-- Help note --}}
            <div style="background-color:#fff8e1;border-left:4px solid #ffc107;padding:14px 18px;border-radius:4px;margin-top:8px;">
                <p style="margin:0;color:#555;font-size:13px;line-height:1.5;">
                    <strong>Need help?</strong> Contact the IT Department if you have any issues setting up the printer.
                </p>
            </div>

        </td>
    </tr>

    {{-- Footer --}}
    <tr>
        <td style="background-color:#f8f9fa;padding:18px 40px;text-align:center;border-top:1px solid #eee;">
            <p style="margin:0;color:#888;font-size:12px;">
                SG NOC System &nbsp;|&nbsp; Samir Group IT Department<br>
                <a href="mailto:support@samirgroup.com" style="color:#2d6a9f;text-decoration:none;">support@samirgroup.com</a>
            </p>
        </td>
    </tr>

</table>
</td></tr>
</table>

</body>
</html>
