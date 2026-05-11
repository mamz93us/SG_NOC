<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Backup Ready</title></head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:Arial,sans-serif;">

@php
    $ow      = $backup->offboardingWorkflow;
    $emp     = $ow?->employee;
    $url     = url('/offboarding/download/' . $backup->download_token);
    $expires = $backup->download_expires_at?->format('d M Y, H:i');
@endphp

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f6f9;padding:30px 0;">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
      <tr>
        <td style="background-color:#198754;padding:24px 30px;color:#fff;">
          <h2 style="margin:0;font-size:20px;">Backup Ready for Download</h2>
          <p style="margin:6px 0 0;font-size:13px;color:#d1e7dd;">Offboarding archive available from NOC</p>
        </td>
      </tr>
      <tr>
        <td style="padding:28px 30px;">
          <p>Hi,</p>
          <p>The offboarding backup for <strong>{{ $emp?->name ?? 'the employee' }}</strong> is ready.</p>

          <table cellpadding="6" cellspacing="0" border="0" width="100%" style="border:1px solid #dee2e6;border-radius:6px;margin:16px 0;">
            <tr><td style="background:#f8f9fa;width:140px;">Type</td><td><strong>{{ $backup->typeLabel() }}</strong></td></tr>
            <tr><td style="background:#f8f9fa;">Size</td><td>{{ $backup->humanSize() }}</td></tr>
            <tr><td style="background:#f8f9fa;">SHA-256</td><td style="font-family:monospace;font-size:11px;">{{ $backup->file_sha256 ? substr($backup->file_sha256, 0, 16) : '—' }}…</td></tr>
            <tr><td style="background:#f8f9fa;">Link expires</td><td>{{ $expires ?? 'never' }}</td></tr>
          </table>

          <div style="text-align:center;margin:24px 0;">
            <a href="{{ $url }}" style="display:inline-block;background:#198754;color:#fff;font-weight:700;text-decoration:none;padding:12px 28px;border-radius:6px;">
              Download Now
            </a>
          </div>

          <p style="font-size:13px;color:#6c757d;">
            If the button doesn't work, copy this URL:<br>
            <span style="word-break:break-all;font-family:monospace;font-size:11px;">{{ $url }}</span>
          </p>

          <p style="font-size:12px;color:#6c757d;margin-top:24px;">
            For security, this link is single-purpose and expires automatically.
            Downloads are logged for audit. If you didn't expect this email,
            please contact IT immediately.
          </p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
