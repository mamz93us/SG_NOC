<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>[SG NOC] {{ $notification->title }}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

{{-- ═══════════════════════════ OUTER WRAPPER ═══════════════════════════ --}}
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
       style="background-color:#f4f6f9;min-height:100vh;">
    <tr>
        <td align="center" style="padding:40px 16px;">

            {{-- ── MAIN CARD ─────────────────────────────────── --}}
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0"
                   style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;
                          box-shadow:0 4px 24px rgba(0,0,0,0.08);overflow:hidden;">

                {{-- ── HEADER ──────────────────────────────────── --}}
                <tr>
                    <td style="background:linear-gradient(135deg,#0f2044 0%,#1a3a6e 60%,#1e4d8c 100%);
                               padding:32px 40px 28px;text-align:center;">

                        {{-- Logo / Brand row --}}
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td align="center">
                                    {{-- Shield icon badge --}}
                                    <div style="display:inline-block;background:rgba(255,255,255,0.12);
                                                border-radius:50%;width:64px;height:64px;line-height:64px;
                                                text-align:center;font-size:30px;margin-bottom:12px;">
                                        🛡️
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td align="center">
                                    <p style="margin:0;font-size:22px;font-weight:800;
                                              letter-spacing:3px;color:#ffffff;text-transform:uppercase;">
                                        SAMIR GROUP
                                    </p>
                                    <p style="margin:6px 0 0;font-size:12px;font-weight:400;
                                              letter-spacing:2px;color:#93b8e8;text-transform:uppercase;">
                                        Network Operations Center
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- ── SEVERITY STRIP ──────────────────────────── --}}
                @php
                    $severityColor = match($notification->severity ?? 'info') {
                        'critical' => '#dc3545',
                        'warning'  => '#f59e0b',
                        'info'     => '#0d9488',
                        default    => '#3b82f6',
                    };
                    $severityLabel = match($notification->severity ?? 'info') {
                        'critical' => '🔴  CRITICAL ALERT',
                        'warning'  => '⚠️  WARNING',
                        'info'     => 'ℹ️  INFORMATION',
                        default    => '🔵  NOTICE',
                    };
                    $severityBg = match($notification->severity ?? 'info') {
                        'critical' => 'background:linear-gradient(90deg,#dc3545,#c82333)',
                        'warning'  => 'background:linear-gradient(90deg,#f59e0b,#d97706)',
                        'info'     => 'background:linear-gradient(90deg,#0d9488,#0f766e)',
                        default    => 'background:linear-gradient(90deg,#3b82f6,#2563eb)',
                    };
                @endphp
                <tr>
                    <td style="{{ $severityBg }};padding:10px 40px;text-align:center;">
                        <span style="color:#ffffff;font-size:11px;font-weight:700;
                                     letter-spacing:2px;text-transform:uppercase;">
                            {{ $severityLabel }}
                        </span>
                    </td>
                </tr>

                {{-- ── BODY ─────────────────────────────────────── --}}
                <tr>
                    <td style="padding:36px 40px 28px;background:#ffffff;">

                        {{-- Title --}}
                        <h1 style="margin:0 0 16px;font-size:22px;font-weight:700;
                                   color:#1a2744;line-height:1.3;">
                            {{ $notification->title }}
                        </h1>

                        {{-- Divider --}}
                        <div style="height:3px;width:48px;background:{{ $severityColor }};
                                    border-radius:2px;margin-bottom:20px;"></div>

                        {{-- Message --}}
                        <p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#4b5563;">
                            {{ $notification->message }}
                        </p>

                        {{-- Metadata row --}}
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                               style="margin-bottom:28px;">
                            <tr>
                                <td style="vertical-align:middle;">
                                    {{-- Type badge --}}
                                    <span style="display:inline-block;background:#e5e7eb;color:#374151;
                                                 font-size:11px;font-weight:600;letter-spacing:1px;
                                                 text-transform:uppercase;padding:4px 10px;border-radius:20px;">
                                        {{ str_replace('_', ' ', $notification->type ?? 'system') }}
                                    </span>
                                </td>
                                <td align="right" style="vertical-align:middle;">
                                    <span style="font-size:12px;color:#9ca3af;">
                                        🕐 {{ $notification->created_at?->format('D, d M Y · H:i') ?? now()->format('D, d M Y · H:i') }} UTC
                                    </span>
                                </td>
                            </tr>
                        </table>

                        {{-- CTA button (only if link present) --}}
                        @if($notification->link)
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td align="center" style="padding:4px 0 8px;">
                                    <a href="{{ $notification->link }}"
                                       style="display:block;background:{{ $severityColor }};color:#ffffff;
                                              text-decoration:none;font-size:14px;font-weight:700;
                                              padding:14px 32px;border-radius:8px;letter-spacing:0.5px;
                                              text-align:center;">
                                        → View in SG NOC
                                    </a>
                                </td>
                            </tr>
                        </table>
                        @endif

                    </td>
                </tr>

                {{-- ── DIVIDER ──────────────────────────────────── --}}
                <tr>
                    <td style="padding:0 40px;">
                        <div style="height:1px;background:#e5e7eb;"></div>
                    </td>
                </tr>

                {{-- ── RECIPIENT INFO ───────────────────────────── --}}
                <tr>
                    <td style="padding:20px 40px;background:#f9fafb;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td>
                                    <p style="margin:0;font-size:12px;color:#6b7280;">
                                        <strong style="color:#374151;">Sent to:</strong>
                                        {{ $recipient->name }} &lt;{{ $recipient->email }}&gt;
                                    </p>
                                </td>
                                <td align="right">
                                    <span style="display:inline-block;background:{{ $severityColor }}22;
                                                 color:{{ $severityColor }};font-size:10px;font-weight:700;
                                                 letter-spacing:1px;padding:3px 8px;border-radius:4px;
                                                 text-transform:uppercase;">
                                        {{ $notification->severity ?? 'info' }}
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- ── FOOTER ───────────────────────────────────── --}}
                <tr>
                    <td style="background:#f1f5f9;padding:24px 40px;text-align:center;
                               border-top:1px solid #e2e8f0;">

                        {{-- Footer brand --}}
                        <p style="margin:0 0 4px;font-size:13px;font-weight:600;color:#475569;">
                            🛡️ SG NOC — Samir Group IT Department
                        </p>

                        <p style="margin:0 0 12px;font-size:11px;color:#94a3b8;">
                            This is an automated alert from the Network Operations Center.
                            Please do not reply to this email.
                        </p>

                        {{-- Divider --}}
                        <div style="height:1px;background:#e2e8f0;margin:12px auto;max-width:200px;"></div>

                        <p style="margin:0;font-size:11px;color:#94a3b8;">
                            © {{ date('Y') }} Samir Group. All rights reserved.<br>
                            <span style="color:#cbd5e1;">You are receiving this because you are registered as an admin in SG NOC.</span>
                        </p>
                    </td>
                </tr>

            </table>
            {{-- /MAIN CARD --}}

            {{-- Bottom padding --}}
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr><td style="height:32px;"></td></tr>
            </table>

        </td>
    </tr>
</table>

</body>
</html>
