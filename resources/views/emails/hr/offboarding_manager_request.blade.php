<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Offboarding Confirmation Required</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;padding:30px 0;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr>
          <td style="background-color:#dc3545;padding:28px 32px;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Action Required</h1>
            <p style="margin:6px 0 0;color:#f8d7da;font-size:14px;">Employee Offboarding Confirmation</p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:32px;">
            @php
              $payload     = $workflow->payload ?? [];
              $displayName = $payload['display_name'] ?? 'Employee';
              $upn         = $payload['upn'] ?? '—';
              $lastDay     = $payload['last_day'] ?? null;
              $reason      = $payload['reason'] ?? null;
              $hrRef       = $payload['hr_reference'] ?? $workflow->id;
              $managerName = $token->manager_name ?? 'Manager';
              $formUrl     = url('/offboarding/respond?token=' . $token->token);
              $expiresAt   = $token->expires_at?->format('d M Y, H:i');
            @endphp

            <p style="margin:0 0 16px;color:#212529;font-size:16px;">
              Dear {{ $managerName }},
            </p>
            <p style="margin:0 0 20px;color:#212529;font-size:15px;">
              HR has initiated an offboarding process for <strong>{{ $displayName }}</strong>.
              As their manager, your confirmation is required before IT can proceed with account deactivation.
            </p>

            <!-- Details table -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:24px;">
              <tr style="background-color:#fff5f5;">
                <td colspan="2" style="padding:10px 16px;font-size:13px;font-weight:700;color:#842029;text-transform:uppercase;letter-spacing:.5px;">Employee Details</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;width:160px;border-top:1px solid #dee2e6;">Employee</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-weight:600;border-top:1px solid #dee2e6;">{{ $displayName }}</td>
              </tr>
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Login (UPN)</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-family:monospace;">{{ $upn }}</td>
              </tr>
              @if($lastDay)
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Last Working Day</td>
                <td style="padding:10px 16px;color:#dc3545;font-size:14px;font-weight:600;border-top:1px solid #dee2e6;">{{ $lastDay }}</td>
              </tr>
              @endif
              @if($reason)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Reason</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ ucfirst($reason) }}</td>
              </tr>
              @endif
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">HR Reference</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">{{ $hrRef }}</td>
              </tr>
            </table>

            <!-- CTA Button -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
              <tr>
                <td align="center">
                  <a href="{{ $formUrl }}"
                     style="display:inline-block;background-color:#dc3545;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;padding:14px 36px;border-radius:6px;">
                    Review &amp; Confirm Offboarding
                  </a>
                </td>
              </tr>
            </table>

            @if($expiresAt)
            <p style="margin:0 0 16px;color:#856404;font-size:13px;background-color:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;">
              &#9888; This link expires on <strong>{{ $expiresAt }}</strong>. After expiry, please contact HR directly.
            </p>
            @endif

            <p style="margin:0;color:#6c757d;font-size:13px;">
              If you have questions about this request, please contact the HR department quoting reference: <strong>{{ $hrRef }}</strong>.
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
