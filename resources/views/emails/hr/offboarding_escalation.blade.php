<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Offboarding Escalation</title></head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,sans-serif;">

@php
    $ow      = $offboardingWorkflow;
    $emp     = $ow->employee;
    $token   = $ow->token;
    // Canonical NOC host — this link is emailed, so it must not inherit the
    // host that happened to generate it (hr./em./vcard. all 404 on /admin).
    $adminUrl = \App\Support\Noc::route('admin.offboarding.show', $ow);
@endphp

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;padding:30px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
      <tr>
        <td style="background-color:#dc3545;padding:24px 30px;color:#fff;">
          <h2 style="margin:0;font-size:20px;">Offboarding Escalation</h2>
          <p style="margin:6px 0 0;font-size:13px;color:#f8d7da;">Manager did not respond in grace window — IT action required.</p>
        </td>
      </tr>
      <tr>
        <td style="padding:28px 30px;">
          <p>
            The offboarding workflow for <strong><!--f:employee_name-->{{ $emp?->name ?? 'an employee' }}<!--/f--></strong>
            has reached the manager grace window without a response.
            The Azure user has been disabled. No destructive deprovisioning has run.
          </p>

          <table cellpadding="6" cellspacing="0" border="0" width="100%" style="border:1px solid #dee2e6;border-radius:6px;margin:16px 0;">
            <tr><td style="background:#f8f9fa;width:160px;">Employee</td><td><strong><!--f:employee_name_row-->{{ $emp?->name ?? '—' }}<!--/f--></strong></td></tr>
            <tr><td style="background:#f8f9fa;">UPN</td><td style="font-family:monospace;"><!--f:employee_email-->{{ $emp?->email ?? '—' }}<!--/f--></td></tr>
            <tr><td style="background:#f8f9fa;">Last working day</td><td><!--f:last_working_day-->{{ $ow->expected_last_day?->format('Y-m-d') }}<!--/f--></td></tr>
            <tr><td style="background:#f8f9fa;">Manager email</td><td><!--f:manager_email-->{{ $token?->manager_email ?? '—' }}<!--/f--></td></tr>
            <tr><td style="background:#f8f9fa;">Grace expired</td><td><!--f:grace_expired-->{{ $ow->manager_grace_until?->format('Y-m-d') }}<!--/f--></td></tr>
          </table>

          <p>Please take one of these actions:</p>
          <ol>
            <li>Contact the manager directly to obtain their decisions.</li>
            <li>Resend the manager email from the NOC admin page (button: "Resend Manager Email").</li>
            <li>Cancel the workflow and let HR re-submit.</li>
            <li>Force-execute the final delete once all backups are uploaded.</li>
          </ol>

          <div style="text-align:center;margin:24px 0;">
            <!--f:noc_button--><a href="{{ $adminUrl }}" style="display:inline-block;background:#dc3545;color:#fff;font-weight:700;text-decoration:none;padding:12px 28px;border-radius:6px;">
              Open in NOC
            </a><!--/f-->
          </div>

          <p style="font-size:12px;color:#6c757d;">SG NOC &bull; Offboarding escalation &bull; Automated email</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
