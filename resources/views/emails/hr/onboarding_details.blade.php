@php
    /**
     * Sent to HR and the reporting manager once a new starter is fully set up.
     * Contains NO credentials — no password, no extension secret, no personal
     * mobile. Named payload keys only; never dump $payload here.
     */
    $payload    = $workflow->payload ?? [];
    $employee   = ! empty($payload['employee_id']) ? \App\Models\Employee::with(['branch','department','manager'])->find($payload['employee_id']) : null;
    $settings   = \App\Models\Setting::get();

    $name       = $payload['display_name'] ?? trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? ''));
    $upn        = $payload['upn'] ?? null;
    $extension  = $payload['extension'] ?? $employee?->extension_number;
    $jobTitle   = $payload['job_title'] ?? $employee?->job_title;
    $department = $employee?->department?->name;
    $branch     = $employee?->branch?->name ?? $workflow->branch?->name;
    $manager    = $payload['manager_name'] ?? $employee?->manager?->name;
    $startDate  = ! empty($payload['start_date']) ? \Carbon\Carbon::parse($payload['start_date'])->format('d M Y') : null;

    $intro = $recipientRole === 'manager'
        ? 'Your new team member is set up. Here are their contact details.'
        : 'This new starter has been fully set up by IT. Here are their contact details for your records.';

    $rows = array_filter([
        'Name'        => $name,
        'Work email'  => $upn,
        'Extension'   => $extension,
        'Job title'   => $jobTitle,
        'Department'  => $department,
        'Branch'      => $branch,
        'Manager'     => $manager,
        'Start date'  => $startDate,
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
        <td style="background:linear-gradient(135deg,#4a00e0,#8e2de2);padding:22px 26px;color:#fff;">
          <div style="font-size:19px;font-weight:700;"><!--f:name-->{{ $name }}<!--/f--> is ready</div>
          <div style="font-size:13px;opacity:.9;margin-top:3px;">{{ $settings->company_name ?? 'Samir Group' }} — IT onboarding complete</div>
        </td>
      </tr>

      <tr>
        <td style="padding:22px 26px 6px;color:#333;font-size:14px;line-height:1.55;">
          <!--f:intro-->{{ $intro }}<!--/f-->
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

      @if(empty($extension))
      <tr>
        <td style="padding:6px 26px 0;">
          <div style="background:#fff8e1;border-left:3px solid #ffc107;padding:10px 12px;border-radius:6px;font-size:13px;color:#7a5b00;">
            No IP phone extension was requested for this employee.
          </div>
        </td>
      </tr>
      @endif

      <tr>
        <td style="padding:18px 26px 24px;">
          <div style="background:#f1f3f5;border-radius:8px;padding:12px 14px;font-size:12.5px;color:#555;line-height:1.5;">
            <strong>Sign-in details are not included in this email.</strong>
            IT shares the password with {{ $name ?: 'the employee' }} directly.
            If they cannot sign in, contact IT rather than forwarding this message.
          </div>
        </td>
      </tr>

      <tr>
        <td style="padding:0 26px 24px;color:#9aa0a6;font-size:11.5px;border-top:1px solid #eee;padding-top:14px;">
          Sent automatically by SG NOC · request #<!--f:workflow_id-->{{ $workflow->id }}<!--/f-->
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
