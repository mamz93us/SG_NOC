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
            <div style="font-size:40px;margin-bottom:10px;">🖨️</div>
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Printer Setup Instructions</h1>
            <p style="margin:8px 0 0;color:#b0d0f0;font-size:14px;">{{ $token->branch?->name ?? 'Your Branch' }}</p>
        </td>
    </tr>

    {{-- Body --}}
    <tr>
        <td style="padding:32px 40px;">

            {{-- Greeting --}}
            <p style="margin:0 0 20px;color:#333;font-size:15px;">
                Hi <strong>{{ $token->employee?->name ?? $token->sent_to_email }}</strong>,<br><br>
                Your printer setup is ready. Click the button below on the laptop or computer you want to configure.
            </p>

            {{-- Printers table --}}
            @if($printers->isNotEmpty())
            <h3 style="margin:0 0 12px;color:#1e3a5f;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
                Your Branch Printers
            </h3>
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="border-collapse:collapse;margin-bottom:24px;font-size:13px;">
                <tr style="background-color:#f0f4f8;">
                    <th style="padding:8px 12px;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #dde3ea;">Printer</th>
                    <th style="padding:8px 12px;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #dde3ea;">Location</th>
                    <th style="padding:8px 12px;text-align:left;color:#555;font-weight:600;border-bottom:2px solid #dde3ea;">IP Address</th>
                </tr>
                @foreach($printers as $printer)
                <tr style="border-bottom:1px solid #eef0f3;">
                    <td style="padding:8px 12px;color:#333;font-weight:500;">{{ $printer->printer_name }}</td>
                    <td style="padding:8px 12px;color:#666;">{{ $printer->locationLabel() ?: '—' }}</td>
                    <td style="padding:8px 12px;color:#666;font-family:monospace;">{{ $printer->ip_address ?: '—' }}</td>
                </tr>
                @endforeach
            </table>
            @endif

            {{-- CTA Button --}}
            <div style="text-align:center;margin:28px 0;">
                <a href="{{ $setup_url }}"
                   style="background-color:#1e3a5f;color:#ffffff;padding:14px 32px;text-decoration:none;border-radius:6px;font-size:15px;font-weight:600;display:inline-block;">
                    Open Printer Setup Page →
                </a>
            </div>

            {{-- Note --}}
            <div style="background-color:#fff8e1;border-left:4px solid #ffc107;padding:14px 18px;border-radius:4px;margin-top:20px;">
                <p style="margin:0;color:#555;font-size:13px;line-height:1.5;">
                    <strong>📌 Note:</strong> Open this link on the laptop or computer you want to configure.
                    The link expires in <strong>7 days</strong>.
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
