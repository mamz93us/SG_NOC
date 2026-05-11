<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Offboarding Confirmation Required</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">

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
    $live        = $payload['live_graph_data'] ?? [];
    $mailbox     = $live['mailbox']  ?? [];
    $onedrive    = $live['onedrive'] ?? [];
    $groups      = $live['groups']   ?? [];
    $reminder    = $reminder ?? false;

    $humanSize = function ($bytes) {
        if (! $bytes) return '—';
        $units = ['B','KB','MB','GB','TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) { $size /= 1024; $i++; }
        return sprintf('%.2f %s', $size, $units[$i]);
    };
@endphp

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;padding:30px 0;">
  <tr>
    <td align="center">
      <table width="640" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        <tr>
          <td style="background-color:{{ $reminder ? '#fd7e14' : '#dc3545' }};padding:28px 32px;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">
              {{ $reminder ? 'REMINDER: Action Required' : 'Action Required' }}
            </h1>
            <p style="margin:6px 0 0;color:#f8d7da;font-size:14px;">Employee Offboarding Confirmation</p>
          </td>
        </tr>

        <tr>
          <td style="padding:32px;">
            <p style="margin:0 0 16px;color:#212529;font-size:16px;">Dear {{ $managerName }},</p>
            <p style="margin:0 0 20px;color:#212529;font-size:15px;">
              HR has initiated an offboarding process for <strong>{{ $displayName }}</strong>.
              <strong>Mailbox and OneDrive will be backed up to the NOC archive automatically</strong> —
              you'll receive secure download links once each backup is ready. Please review the details
              below and submit your decisions so IT can proceed with the rest of the deprovisioning.
            </p>

            <!-- Employee details -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:18px;">
              <tr style="background-color:#fff5f5;">
                <td colspan="2" style="padding:10px 16px;font-size:13px;font-weight:700;color:#842029;text-transform:uppercase;letter-spacing:.5px;">Employee Details</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;width:170px;border-top:1px solid #dee2e6;">Employee</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-weight:600;border-top:1px solid #dee2e6;">{{ $displayName }}</td>
              </tr>
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Login (UPN)</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;font-family:monospace;">{{ $upn }}</td>
              </tr>
              @if(!empty($live['job_title']) || !empty($live['department']))
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Role</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">
                  {{ $live['job_title'] ?? '—' }}@if(!empty($live['department'])) <span style="color:#6c757d;"> · {{ $live['department'] }}</span>@endif
                </td>
              </tr>
              @endif
              @if($lastDay)
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">Last Working Day</td>
                <td style="padding:10px 16px;color:#dc3545;font-size:14px;font-weight:600;">{{ $lastDay }}</td>
              </tr>
              @endif
              @if($reason)
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;border-top:1px solid #dee2e6;">Reason</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">{{ ucfirst($reason) }}</td>
              </tr>
              @endif
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">HR Reference</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">{{ $hrRef }}</td>
              </tr>
            </table>

            <!-- Mailbox & OneDrive footprint -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:18px;">
              <tr style="background-color:#e7f1ff;">
                <td colspan="2" style="padding:10px 16px;font-size:13px;font-weight:700;color:#084298;text-transform:uppercase;letter-spacing:.5px;">Data Footprint (Microsoft 365)</td>
              </tr>
              <tr>
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;width:170px;border-top:1px solid #dee2e6;">Mailbox size</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;border-top:1px solid #dee2e6;">
                  <strong>{{ $humanSize($mailbox['size_bytes'] ?? null) }}</strong>
                  @if(!empty($mailbox['item_count']))
                    <span style="color:#6c757d;"> · {{ number_format($mailbox['item_count']) }} items</span>
                  @endif
                </td>
              </tr>
              <tr style="background-color:#f8f9fa;">
                <td style="padding:10px 16px;color:#6c757d;font-size:14px;">OneDrive size</td>
                <td style="padding:10px 16px;color:#212529;font-size:14px;">
                  <strong>{{ $humanSize($onedrive['size_bytes'] ?? null) }}</strong>
                  @if(!empty($onedrive['file_count']))
                    <span style="color:#6c757d;"> · {{ number_format($onedrive['file_count']) }} files</span>
                  @endif
                </td>
              </tr>
            </table>

            @if(!empty($groups))
              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:24px;">
                <tr style="background-color:#fff3cd;">
                  <td style="padding:10px 16px;font-size:13px;font-weight:700;color:#664d03;text-transform:uppercase;letter-spacing:.5px;">
                    Mail-Enabled Group Memberships ({{ count($groups) }})
                  </td>
                </tr>
                <tr>
                  <td style="padding:10px 16px;color:#212529;font-size:13px;border-top:1px solid #dee2e6;">
                    @foreach($groups as $i => $g)
                      <span style="display:inline-block;background-color:#fffaf0;border:1px solid #ffecb5;padding:2px 8px;border-radius:12px;margin:2px 4px 2px 0;">
                        {{ $g['display_name'] ?? '(unnamed)' }}
                      </span>
                    @endforeach
                    <div style="margin-top:8px;color:#6c757d;font-size:12px;">
                      Security groups are not shown. The user will be removed from all groups as part of deprovisioning.
                    </div>
                  </td>
                </tr>
              </table>
            @endif

            <!-- CTA Button -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
              <tr>
                <td align="center">
                  <a href="{{ $formUrl }}"
                     style="display:inline-block;background-color:#dc3545;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;padding:14px 36px;border-radius:6px;">
                    Open Offboarding Form
                  </a>
                </td>
              </tr>
            </table>

            @if($expiresAt)
            <p style="margin:0 0 16px;color:#856404;font-size:13px;background-color:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;">
              &#9888; This link expires on <strong>{{ $expiresAt }}</strong>. After expiry, please contact IT directly.
            </p>
            @endif

            <p style="margin:0;color:#6c757d;font-size:13px;">
              If you have questions about this request, please contact the HR department quoting reference: <strong>{{ $hrRef }}</strong>.
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
