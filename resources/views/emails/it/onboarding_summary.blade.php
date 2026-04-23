<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IT Onboarding Summary</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;padding:30px 0;">
  <tr>
    <td align="center">
      <table width="640" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <tr>
          <td style="background-color:#198754;padding:28px 32px;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">IT Onboarding Summary</h1>
            <p style="margin:6px 0 0;color:#d1e7dd;font-size:14px;">New-hire credentials &amp; handoff details</p>
          </td>
        </tr>

        <tr>
          <td style="padding:32px;">
            @php
              $payload     = $workflow->payload ?? [];
              $displayName = $payload['display_name'] ?? 'New Employee';
              $upn         = $payload['upn'] ?? '—';
              $initialPwd  = $payload['initial_password'] ?? '(not set)';
              $extension   = $payload['extension'] ?? null;
              $ucmPass     = $payload['ucm_extension_secret'] ?? null;
              $ucmServerId = $payload['ucm_server_id'] ?? null;
              $ucmServer   = $ucmServerId ? \App\Models\UcmServer::find($ucmServerId) : null;
              $department  = $payload['department'] ?? null;
              $jobTitle    = $payload['job_title'] ?? null;
              $licenses    = $payload['assigned_licenses'] ?? [];
              $internetLvl = $payload['internet_level'] ?? null;
              $managerCmts = $payload['manager_comments'] ?? null;
              $branch      = $workflow->branch_id ? \App\Models\Branch::find($workflow->branch_id) : null;
            @endphp

            <p style="margin:0 0 16px;color:#212529;font-size:16px;">
              A new employee has been provisioned. Below are the credentials and configuration details.
              Please hand these to the employee securely.
            </p>

            <!-- Account -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:18px;">
              <tr style="background-color:#f8f9fa;">
                <td colspan="2" style="padding:10px 16px;font-size:13px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:.5px;">Account</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;width:180px;border-top:1px solid #dee2e6;">Full Name</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;font-weight:600;">{{ $displayName }}</td>
              </tr>
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Login (UPN)</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-family:monospace;">{{ $upn }}</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Initial Password</td>
                <td style="padding:10px 16px;color:#dc3545;font-size:14px;border-top:1px solid #dee2e6;font-family:monospace;font-weight:700;">{{ $initialPwd }}</td>
              </tr>
              @if($department)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Department</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ $department }}</td>
              </tr>
              @endif
              @if($jobTitle)
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Job Title</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">{{ $jobTitle }}</td>
              </tr>
              @endif
              @if($branch)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Branch</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ $branch->name }}</td>
              </tr>
              @endif
            </table>

            @if($extension)
            <!-- Phone / Extension -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:18px;">
              <tr style="background-color:#f8f9fa;">
                <td colspan="2" style="padding:10px 16px;font-size:13px;font-weight:700;color:#495057;text-transform:uppercase;letter-spacing:.5px;">IP Phone / Extension</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;width:180px;border-top:1px solid #dee2e6;">Extension Number</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;font-weight:700;">{{ $extension }}</td>
              </tr>
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Extension ID (UCM login)</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-family:monospace;">{{ $extension }}</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Extension Password</td>
                <td style="padding:10px 16px;color:#dc3545;font-size:14px;border-top:1px solid #dee2e6;font-family:monospace;font-weight:700;">{{ $ucmPass ?? '(reset via UCM admin)' }}</td>
              </tr>
              @if($ucmServer)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">UCM Server</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ $ucmServer->name }} <span style="color:#6c757d;">({{ $ucmServer->url ?? '—' }})</span></td>
              </tr>
              @endif
            </table>
            @endif

            @if(!empty($licenses))
            <p style="margin:16px 0 8px;color:#212529;font-size:14px;font-weight:600;">Assigned Licenses:</p>
            <ul style="margin:0 0 18px;padding-left:20px;color:#495057;font-size:14px;">
              @foreach($licenses as $lic)
              <li style="margin-bottom:4px;">{{ $lic['name'] ?? $lic['sku'] }}</li>
              @endforeach
            </ul>
            @endif

            @if($internetLvl)
            <p style="margin:8px 0;color:#495057;font-size:14px;"><strong>Internet Level:</strong> {{ $internetLvl }}</p>
            @endif

            @if($managerCmts)
            <div style="margin:16px 0;padding:12px 14px;border-left:3px solid #0d6efd;background:#f0f6ff;border-radius:4px;">
              <div style="font-size:12px;font-weight:700;color:#0d6efd;text-transform:uppercase;margin-bottom:4px;">Manager Comments</div>
              <div style="font-size:14px;color:#212529;white-space:pre-wrap;">{{ $managerCmts }}</div>
            </div>
            @endif

            <p style="margin:20px 0 0;padding:10px 14px;background:#fff3cd;border:1px solid #ffecb5;border-radius:4px;color:#664d03;font-size:13px;">
              <strong>Security note:</strong> Initial password is shown for handoff only.
              Advise the employee to change it on first login.
            </p>

            <p style="margin:20px 0 0;color:#6c757d;font-size:13px;">
              Workflow #{{ $workflow->id }} — automated by SG NOC IT Management System.
            </p>
          </td>
        </tr>

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
