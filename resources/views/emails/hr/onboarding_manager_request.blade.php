<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Employee Setup Form</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;padding:30px 0;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr>
          <td style="background-color:#0d6efd;padding:28px 32px;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Action Required</h1>
            <p style="margin:6px 0 0;color:#cfe2ff;font-size:14px;">New Employee Onboarding — Manager Input Form</p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:32px;">
            @php
              $payload     = $workflow->payload ?? [];
              $displayName = $payload['display_name'] ?? 'New Employee';
              $upn         = $payload['upn'] ?? ($payload['first_name'] . '.' . $payload['last_name'] . '@company.com');
              $hrRef       = $payload['hr_reference'] ?? $workflow->id;
              $startDate   = $payload['start_date'] ?? null;
              $managerName = $token->manager_name ?? 'Manager';
              $formUrl     = url('/onboarding/form/' . $token->token);
              $expiresAt   = $token->expires_at?->format('d M Y, H:i');
            @endphp

            <p style="margin:0 0 16px;color:#212529;font-size:16px;">
              Dear {{ $managerName }},
            </p>
            <p style="margin:0 0 20px;color:#212529;font-size:15px;">
              A new employee is being onboarded. Before IT can complete the setup, we need your input
              to configure the right equipment, access level, and phone extension.
            </p>

            <!-- Employee details table -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:24px;">
              <tr style="background-color:#e7f1ff;">
                <td colspan="2" style="padding:10px 16px;font-size:13px;font-weight:700;color:#084298;text-transform:uppercase;letter-spacing:.5px;">New Employee Details</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;width:160px;border-top:1px solid #dee2e6;">Full Name</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-weight:600;border-top:1px solid #dee2e6;">{{ $displayName }}</td>
              </tr>
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Email (UPN)</td>
                <td style="padding:10px 16px;color:#0d6efd;font-size:14px;font-family:monospace;">{{ $upn }}</td>
              </tr>
              @if($startDate)
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Start Date</td>
                <td style="padding:10px 16px;color:#198754;font-size:14px;font-weight:600;border-top:1px solid #dee2e6;">{{ $startDate }}</td>
              </tr>
              @endif
              <tr style="{{ $startDate ? 'background-color:#f8f9fa;' : '' }}">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">HR Reference</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">{{ $hrRef }}</td>
              </tr>
            </table>

            <p style="margin:0 0 20px;color:#495057;font-size:14px;">
              Please click the button below to fill in the required setup information (laptop, extension,
              internet access level, and group memberships):
            </p>

            <!-- CTA Button -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
              <tr>
                <td align="center">
                  <a href="{{ $formUrl }}"
                     style="display:inline-block;background-color:#0d6efd;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;padding:14px 36px;border-radius:6px;">
                    Fill New Employee Setup Form
                  </a>
                </td>
              </tr>
            </table>

            @if($expiresAt)
            <p style="margin:0 0 16px;color:#856404;font-size:13px;background-color:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;">
              &#9888; This link expires on <strong>{{ $expiresAt }}</strong>.
              If you are unable to complete the form, please contact the IT department directly.
            </p>
            @endif

            <p style="margin:0;color:#6c757d;font-size:13px;">
              If you have questions, contact IT quoting reference: <strong>{{ $hrRef }}</strong>.
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
