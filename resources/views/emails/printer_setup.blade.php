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
          <td style="background-color:#0d6efd;padding:28px 32px;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">&#128438; Printer Setup Instructions</h1>
            <p style="margin:6px 0 0;color:#cfe2ff;font-size:14px;">IT Department &bull; SG NOC</p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:32px;">
            @php
              $config      = $token->printer_config ?? [];
              $printerName = $config['printer_name'] ?? 'Office Printer';
              $ip          = $config['ip_address']   ?? '—';
              $mfr         = $config['manufacturer'] ?? '';
              $model       = $config['model']         ?? '';
              $branch      = $config['branch']        ?? null;
              $location    = $config['location']      ?? null;
              $setupUrl    = url('/printer-setup?token=' . $token->token);
              $expiresAt   = $token->expires_at?->format('d M Y');
            @endphp

            <p style="margin:0 0 16px;color:#212529;font-size:16px;">
              Your IT team has sent you printer installation instructions for <strong>{{ $printerName }}</strong>.
            </p>

            <!-- Printer info table -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:24px;">
              <tr style="background-color:#f8f9fa;">
                <td colspan="2" style="padding:10px 16px;font-size:13px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:.5px;">Printer Details</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;width:140px;border-top:1px solid #dee2e6;">Printer Name</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-weight:600;border-top:1px solid #dee2e6;">{{ $printerName }}</td>
              </tr>
              @if($mfr || $model)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Make / Model</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ trim($mfr . ' ' . $model) }}</td>
              </tr>
              @endif
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">IP Address</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-family:monospace;border-top:1px solid #dee2e6;">{{ $ip }}</td>
              </tr>
              @if($branch)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Branch</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ $branch }}</td>
              </tr>
              @endif
              @if($location && $location !== '—')
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Location</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">{{ $location }}</td>
              </tr>
              @endif
            </table>

            <!-- CTA -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
              <tr>
                <td align="center">
                  <a href="{{ $setupUrl }}"
                     style="display:inline-block;background-color:#0d6efd;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;padding:14px 36px;border-radius:6px;">
                    Open Setup Page &amp; Download Script
                  </a>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 16px;color:#495057;font-size:14px;">
              The setup page includes one-click scripts for Windows and macOS/Linux that will automatically install the printer for you.
            </p>

            @if($expiresAt)
            <p style="margin:0 0 16px;color:#856404;font-size:13px;background-color:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;">
              &#9888; This link expires on <strong>{{ $expiresAt }}</strong>. Contact IT if you need it resent.
            </p>
            @endif

            <p style="margin:0;color:#6c757d;font-size:13px;">
              If you have any issues, please contact the IT helpdesk.
            </p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background-color:#f8f9fa;padding:16px 32px;border-top:1px solid #dee2e6;">
            <p style="margin:0;color:#adb5bd;font-size:12px;text-align:center;">
              SG NOC &bull; IT Management &bull; Automated System Email
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
