<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Offboarding Complete</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;padding:30px 0;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr>
          <td style="background-color:#6c757d;padding:28px 32px;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Offboarding Complete</h1>
            <p style="margin:6px 0 0;color:#dee2e6;font-size:14px;">IT Deprovisioning Confirmation</p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:32px;">
            @php
              $payload     = $workflow->payload ?? [];
              $displayName = $payload['display_name'] ?? 'Employee';
              $upn         = $payload['upn']          ?? '—';
              $lastDay     = $payload['last_day']      ?? null;
              $hrRef       = $payload['hr_reference']  ?? $workflow->id;
              $managerDecision = $payload['manager_decision'] ?? 'approved';
              $managerNotes    = $payload['manager_notes']    ?? null;
            @endphp

            <p style="margin:0 0 16px;color:#212529;font-size:16px;">
              The IT offboarding process for <strong>{{ $displayName }}</strong> has been completed.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:24px;">
              <tr style="background-color:#f8f9fa;">
                <td colspan="2" style="padding:10px 16px;font-size:13px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:.5px;">Account Details</td>
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
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">{{ $lastDay }}</td>
              </tr>
              @endif
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">HR Reference</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ $hrRef }}</td>
              </tr>
            </table>

            <p style="margin:0 0 8px;color:#212529;font-size:14px;font-weight:600;">Actions Completed:</p>
            <ul style="margin:0 0 24px;padding-left:20px;color:#495057;font-size:14px;line-height:1.8;">
              <li>Azure AD account disabled</li>
              <li>Employee record marked as terminated</li>
              <li>Active asset assignments flagged for return</li>
              @if(!empty($payload['forward_to']))<li>Mailbox forwarding requested to {{ $payload['forward_to'] }}</li>@endif
            </ul>

            @if($managerNotes)
            <p style="margin:0 0 8px;color:#212529;font-size:14px;font-weight:600;">Manager Notes:</p>
            <p style="margin:0 0 24px;color:#495057;font-size:14px;background:#f8f9fa;padding:12px 16px;border-radius:6px;border-left:4px solid #6c757d;">{{ $managerNotes }}</p>
            @endif

            <p style="margin:0;color:#6c757d;font-size:13px;">
              This is an automated notification from the SG NOC IT Management System.
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
