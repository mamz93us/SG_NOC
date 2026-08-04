@php
    /**
     * Account-creation request for a business system. Work details only —
     * no credentials of any kind. Named payload keys, never the whole payload.
     */
    $payload  = $workflow->payload ?? [];
    $employee = ! empty($payload['employee_id'])
        ? \App\Models\Employee::with(['branch','department','manager'])->find($payload['employee_id'])
        : null;
    $settings = \App\Models\Setting::get();

    $name      = $payload['display_name'] ?? trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? ''));
    $startDate = ! empty($payload['start_date']) ? \Carbon\Carbon::parse($payload['start_date'])->format('d M Y') : null;

    $rows = array_filter([
        'Name'       => $name,
        'Work email' => $payload['upn'] ?? null,
        'Extension'  => $payload['extension'] ?? $employee?->extension_number,
        'Job title'  => $payload['job_title'] ?? $employee?->job_title,
        'Department' => $employee?->department?->name,
        'Branch'     => $employee?->branch?->name ?? $workflow->branch?->name,
        'Manager'    => $payload['manager_name'] ?? $employee?->manager?->name,
        'Start date' => $startDate,
    ]);
@endphp
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06);">

      <tr>
        <td style="background:#0b5ed7;padding:22px 26px;color:#fff;">
          <div style="font-size:19px;font-weight:700;">{{ $app->name }} account needed</div>
          <div style="font-size:13px;opacity:.9;margin-top:3px;">Requested for a new {{ $settings->company_name ?? 'Samir Group' }} employee</div>
        </td>
      </tr>

      <tr>
        <td style="padding:22px 26px 6px;color:#333;font-size:14px;line-height:1.55;">
          The reporting manager has confirmed this new starter needs a
          <strong>{{ $app->name }}</strong> account. Their IT account is already set up —
          please create their {{ $app->name }} access using the details below.
        </td>
      </tr>

      <tr>
        <td style="padding:10px 26px 4px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#333;">
            @foreach($rows as $label => $value)
            <tr>
              <td style="padding:7px 0;color:#6c757d;width:38%;">{{ $label }}</td>
              <td style="padding:7px 0;font-weight:600;{{ in_array($label, ['Work email','Extension']) ? 'font-family:monospace;' : '' }}">{{ $value }}</td>
            </tr>
            @endforeach
          </table>
        </td>
      </tr>

      @if(! empty($payload['manager_comments']))
      <tr>
        <td style="padding:8px 26px 0;">
          <div style="background:#f8f9fa;border-left:3px solid #0b5ed7;padding:10px 12px;border-radius:6px;font-size:13px;color:#444;">
            <strong>Manager notes:</strong> {{ $payload['manager_comments'] }}
          </div>
        </td>
      </tr>
      @endif

      <tr>
        <td style="padding:18px 26px 24px;">
          <div style="background:#f1f3f5;border-radius:8px;padding:12px 14px;font-size:12.5px;color:#555;line-height:1.5;">
            The employee has already been added to the relevant security group.
            Once the {{ $app->name }} account exists, please reply so IT can mark it active
            on the employee's record.
          </div>
        </td>
      </tr>

      <tr>
        <td style="padding:0 26px 24px;color:#9aa0a6;font-size:11.5px;border-top:1px solid #eee;padding-top:14px;">
          Sent automatically by SG NOC · onboarding request #{{ $workflow->id }}
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
