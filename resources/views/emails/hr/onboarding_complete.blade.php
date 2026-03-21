<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Onboarding Complete</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;padding:30px 0;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr>
          <td style="background-color:#0d6efd;padding:28px 32px;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Onboarding Complete</h1>
            <p style="margin:6px 0 0;color:#cfe2ff;font-size:14px;">IT Provisioning Confirmation</p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:32px;">
            @php
              $payload     = $workflow->payload ?? [];
              $displayName = $payload['display_name'] ?? 'New Employee';
              $upn         = $payload['upn'] ?? '—';
              $extension   = $payload['extension'] ?? null;
              $hrRef       = $payload['hr_reference'] ?? $workflow->id;
              $startDate   = $payload['start_date'] ?? null;
              $department  = $payload['department'] ?? null;
              $jobTitle    = $payload['job_title'] ?? null;
              $licenses    = $payload['assigned_licenses'] ?? [];
            @endphp

            <p style="margin:0 0 16px;color:#212529;font-size:16px;">
              The IT onboarding process for <strong>{{ $displayName }}</strong> has been completed successfully.
            </p>

            <!-- Details table -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:24px;">
              <tr style="background-color:#f8f9fa;">
                <td colspan="2" style="padding:10px 16px;font-size:13px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:.5px;">Account Details</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;width:160px;border-top:1px solid #dee2e6;">HR Reference</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;font-weight:600;">{{ $hrRef }}</td>
              </tr>
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Login (UPN)</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-family:monospace;">{{ $upn }}</td>
              </tr>
              @if($department)
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Department</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">{{ $department }}</td>
              </tr>
              @endif
              @if($jobTitle)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Job Title</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ $jobTitle }}</td>
              </tr>
              @endif
              @if($extension)
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Phone Extension</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;font-weight:600;">{{ $extension }}</td>
              </tr>
              @endif
              @if($startDate)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Start Date</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ $startDate }}</td>
              </tr>
              @endif
            </table>

            @if(!empty($licenses))
            <!-- Licenses -->
            <p style="margin:0 0 8px;color:#212529;font-size:14px;font-weight:600;">Assigned Licenses:</p>
            <ul style="margin:0 0 24px;padding-left:20px;color:#495057;font-size:14px;">
              @foreach($licenses as $lic)
              <li style="margin-bottom:4px;">{{ $lic['name'] ?? $lic['sku'] }}</li>
              @endforeach
            </ul>
            @endif

            <p style="margin:0;color:#6c757d;font-size:13px;">
              This is an automated notification from the SG NOC IT Management System.
              If you have any questions, please contact the IT department.
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
