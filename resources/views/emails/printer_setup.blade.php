<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Printer Setup Instructions</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;padding:30px 0;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr>
          <td style="background-color:#1e3a5f;padding:28px 32px;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">&#128438; Printer Setup for {{ $branchName }}</h1>
            <p style="margin:6px 0 0;color:#b0c4de;font-size:14px;">IT Department &bull; Samir Group IT</p>
          </td>
        </tr>

        <!-- Greeting -->
        <tr>
          <td style="padding:28px 32px 0;">
            <p style="margin:0 0 8px;font-size:16px;color:#1a1a2e;">Hi <strong>{{ $employeeName }}</strong>,</p>
            <p style="margin:0;font-size:14px;color:#555;line-height:1.6;">
              Your printer setup is ready for <strong>{{ $branchName }}</strong>.
              The table below lists all available office printers.
              Click the button at the bottom to open the interactive setup page on your laptop.
            </p>
          </td>
        </tr>

        <!-- Printers table -->
        @if($printers->isNotEmpty())
        <tr>
          <td style="padding:20px 32px 0;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;">
              <tr style="background-color:#f8f9fa;">
                <th style="padding:10px 14px;text-align:left;font-size:12px;text-transform:uppercase;color:#6c757d;font-weight:600;border-bottom:1px solid #e5e7eb;">Printer</th>
                <th style="padding:10px 14px;text-align:left;font-size:12px;text-transform:uppercase;color:#6c757d;font-weight:600;border-bottom:1px solid #e5e7eb;">IP Address</th>
                <th style="padding:10px 14px;text-align:left;font-size:12px;text-transform:uppercase;color:#6c757d;font-weight:600;border-bottom:1px solid #e5e7eb;">Location</th>
              </tr>
              @foreach($printers as $p)
              <tr style="border-bottom:1px solid #f0f0f0;">
                <td style="padding:10px 14px;font-size:14px;color:#1a1a2e;font-weight:600;">{{ $p->printer_name }}</td>
                <td style="padding:10px 14px;font-size:13px;color:#555;font-family:monospace;">{{ $p->ip_address ?? '—' }}</td>
                <td style="padding:10px 14px;font-size:13px;color:#555;">{{ $p->locationLabel() !== '—' ? $p->locationLabel() : ($branchName) }}</td>
              </tr>
              @endforeach
            </table>
          </td>
        </tr>
        @endif

        <!-- CTA Button -->
        <tr>
          <td style="padding:28px 32px;">
            <table cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#1e3a5f;border-radius:6px;">
                  <a href="{{ $setupUrl }}"
                     style="display:inline-block;padding:14px 32px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;letter-spacing:.3px;">
                    &#128438; Open Printer Setup Page &rarr;
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin:12px 0 0;font-size:12px;color:#999;">
              Or copy this link: <a href="{{ $setupUrl }}" style="color:#1e3a5f;word-break:break-all;">{{ $setupUrl }}</a>
            </p>
          </td>
        </tr>

        <!-- Note -->
        <tr>
          <td style="padding:0 32px 24px;">
            <table width="100%" cellpadding="12" cellspacing="0" border="0" style="background-color:#fff8e1;border-left:4px solid #f59e0b;border-radius:4px;">
              <tr>
                <td style="font-size:13px;color:#555;line-height:1.5;">
                  <strong style="color:#92400e;">&#9888; Note:</strong>
                  This link expires in <strong>7 days</strong>
                  ({{ $token->expires_at?->format('d M Y') }}).
                  Open it on the laptop you want to configure.
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background-color:#f8f9fa;padding:18px 32px;border-top:1px solid #e5e7eb;">
            <p style="margin:0;font-size:12px;color:#999;text-align:center;">
              SG NOC System &bull; Samir Group IT<br>
              This is an automated message. Please do not reply.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
