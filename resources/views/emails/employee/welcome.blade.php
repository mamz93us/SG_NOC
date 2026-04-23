<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome to Samir Group</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;padding:30px 0;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <tr>
          <td style="background:linear-gradient(135deg,#0d6efd,#6610f2);padding:36px 32px;text-align:center;">
            <h1 style="margin:0;color:#ffffff;font-size:26px;font-weight:700;">Welcome aboard! 🎉</h1>
            <p style="margin:8px 0 0;color:#dbe7ff;font-size:15px;">We're happy to have you on the team.</p>
          </td>
        </tr>

        <tr>
          <td style="padding:32px;">
            @php
              $payload     = $workflow->payload ?? [];
              $firstName   = $payload['first_name'] ?? null;
              $displayName = $payload['display_name'] ?? 'there';
              $greetName   = $firstName ?: $displayName;
              $upn         = $payload['upn'] ?? null;
              $extension   = $payload['extension'] ?? null;
              $jobTitle    = $payload['job_title'] ?? null;
              $department  = $payload['department'] ?? null;
              $branch      = $workflow->branch_id ? \App\Models\Branch::find($workflow->branch_id) : null;
            @endphp

            <p style="margin:0 0 16px;color:#212529;font-size:16px;">
              Hi <strong>{{ $greetName }}</strong>,
            </p>
            <p style="margin:0 0 20px;color:#495057;font-size:15px;line-height:1.6;">
              Welcome to <strong>Samir Group</strong>! Your IT account has been set up and is ready to use.
              Below are the details you'll need to get started.
            </p>

            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:20px;">
              <tr style="background-color:#f8f9fa;">
                <td colspan="2" style="padding:10px 16px;font-size:13px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:.5px;">Your Details</td>
              </tr>
              @if($upn)
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;width:160px;border-top:1px solid #dee2e6;">Email / Login</td>
                <td style="padding:10px 16px;color:#0d6efd;font-size:14px;border-top:1px solid #dee2e6;font-family:monospace;font-weight:600;">{{ $upn }}</td>
              </tr>
              @endif
              @if($extension)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Phone Extension</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-weight:700;">{{ $extension }}</td>
              </tr>
              @endif
              @if($jobTitle)
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Job Title</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">{{ $jobTitle }}</td>
              </tr>
              @endif
              @if($department)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Department</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ $department }}</td>
              </tr>
              @endif
              @if($branch)
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Office</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">{{ $branch->name }}</td>
              </tr>
              @endif
            </table>

            <p style="margin:0 0 12px;color:#495057;font-size:14px;line-height:1.6;">
              Your IT team will reach out separately with your initial password and any device you need.
              If you have any questions or run into issues, please contact the IT department.
            </p>

            <p style="margin:20px 0 0;color:#212529;font-size:15px;">
              Wishing you a great start! 🚀
            </p>
            <p style="margin:6px 0 0;color:#6c757d;font-size:14px;">
              — The IT Team, Samir Group
            </p>
          </td>
        </tr>

        <tr>
          <td style="background-color:#f8f9fa;padding:16px 32px;border-top:1px solid #dee2e6;">
            <p style="margin:0;color:#adb5bd;font-size:12px;text-align:center;">
              Samir Group &bull; Automated Welcome Message
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
