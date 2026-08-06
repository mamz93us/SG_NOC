@php
    /**
     * Create-account / disable-account request for a business system.
     * Work details only — no credentials of any kind.
     */
    $settings   = \App\Models\Setting::get();
    $deactivate = $action === \App\Mail\BusinessAppAccountMail::DEACTIVATE;
    $payload    = $workflow?->payload ?? [];

    $rows = array_filter([
        'Name'       => $employee->name,
        'Work email' => $employee->email,
        'Extension'  => $employee->extension_number,
        'Job title'  => $employee->job_title,
        'Department' => $employee->department?->name,
        'Branch'     => $employee->branch?->name,
        'Manager'    => $employee->manager?->name,
    ]);

    $accent = $deactivate ? '#c8102e' : '#0b5ed7';
@endphp
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06);">

      <tr>
        <td style="background:{{ $accent }};padding:22px 26px;color:#fff;">
          <div style="font-size:19px;font-weight:700;">
            {{ $deactivate ? $app->name.' account should be disabled' : $app->name.' account needed' }}
          </div>
          <div style="font-size:13px;opacity:.9;margin-top:3px;">
            {{ $settings->company_name ?? 'Samir Group' }} — access request from IT
          </div>
        </td>
      </tr>

      <tr>
        <td style="padding:22px 26px 6px;color:#333;font-size:14px;line-height:1.55;">
          @if($deactivate)
            This employee no longer needs <strong><!--f:app_name-->{{ $app->name }}<!--/f--></strong> access. Please disable
            or remove their account. They have already been removed from the corresponding
            security group.
          @else
            Please create <strong>{{ $app->name }}</strong> access for this employee. Their IT
            account is set up and they have been added to the corresponding security group.
          @endif
        </td>
      </tr>

      <tr>
        <td style="padding:10px 26px 4px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#333;">
            <!--f:detail_rows-->@foreach($rows as $label => $value)
            <tr>
              <td style="padding:7px 0;color:#6c757d;width:38%;">{{ $label }}</td>
              <td style="padding:7px 0;font-weight:600;{{ in_array($label, ['Work email','Extension']) ? 'font-family:monospace;' : '' }}">{{ $value }}</td>
            </tr>
            @endforeach<!--/f-->
          </table>
        </td>
      </tr>

      @if($reason)
      <tr>
        <td style="padding:8px 26px 0;">
          <div style="background:#f8f9fa;border-left:3px solid {{ $accent }};padding:10px 12px;border-radius:6px;font-size:13px;color:#444;">
            <strong>Reason:</strong> <!--f:reason-->{{ $reason }}<!--/f-->
          </div>
        </td>
      </tr>
      @endif

      @if(! $deactivate && ! empty($payload['manager_comments']))
      <tr>
        <td style="padding:8px 26px 0;">
          <div style="background:#f8f9fa;border-left:3px solid {{ $accent }};padding:10px 12px;border-radius:6px;font-size:13px;color:#444;">
            <strong>Manager notes:</strong> <!--f:payload_manager_comments-->{{ $payload['manager_comments'] }}<!--/f-->
          </div>
        </td>
      </tr>
      @endif

      <tr>
        <td style="padding:18px 26px 24px;">
          <div style="background:#f1f3f5;border-radius:8px;padding:12px 14px;font-size:12.5px;color:#555;line-height:1.5;">
            Please reply once this is done so IT can update the employee's record.
          </div>
        </td>
      </tr>

      <tr>
        <td style="padding:0 26px 24px;color:#9aa0a6;font-size:11.5px;border-top:1px solid #eee;padding-top:14px;">
          Sent automatically by SG NOC
          @if($workflow) · onboarding request #<!--f:workflow_id-->{{ $workflow->id }}<!--/f-->@endif
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
