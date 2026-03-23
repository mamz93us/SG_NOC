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

    // Build logo URL using APP_URL from config
    $logoUrl = (!empty($setting->company_logo))
        ? rtrim(config('app.url'), '/') . '/storage/' . $setting->company_logo
        : null;

    $companyName = $setting->company_name ?? 'Samir Group';

    // Payload labels — humanise the keys
    $payloadLabels = [
        'upn'             => 'UPN / Work Email',
        'first_name'      => 'First Name',
        'last_name'       => 'Last Name',
        'full_name'       => 'Full Name',
        'name'            => 'Name',
        'email'           => 'Email',
        'department'      => 'Department',
        'job_title'       => 'Job Title',
        'branch_id'       => 'Branch ID',
        'phone'           => 'Phone',
        'mobile'          => 'Mobile',
        'manager'         => 'Manager',
        'license_sku'     => 'License SKU',
        'employee_id'     => 'Employee ID',
        'device_id'       => 'Device ID',
        'extension'       => 'Extension',
        'groups'          => 'Groups',
        'reason'          => 'Reason',
        'notes'           => 'Notes',
    ];

    // Payload rows to display (skip internal/empty keys)
    $skipKeys = ['_token', 'password', 'secret'];
    $payloadRows = [];
    if (!empty($workflow->payload)) {
        foreach ($workflow->payload as $k => $v) {
            if (in_array($k, $skipKeys)) continue;
            if ($v === null || $v === '') continue;
            if (is_array($v)) $v = implode(', ', $v);
            $label = $payloadLabels[$k] ?? ucwords(str_replace('_', ' ', $k));
            $payloadRows[] = ['label' => $label, 'value' => $v];
        }
    }
@endphp

{{-- ═══════════════════════════ OUTER WRAPPER ═══════════════════════════ --}}
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
       style="background-color:#f4f6f9;min-height:100vh;">
    <tr>
        <td align="center" style="padding:40px 16px;">

            {{-- ── MAIN CARD ─────────────────────────────────── --}}
            <table role="presentation" width="620" cellspacing="0" cellpadding="0" border="0"
                   style="max-width:620px;width:100%;background:#ffffff;border-radius:12px;
                          box-shadow:0 4px 24px rgba(0,0,0,0.08);overflow:hidden;">

                {{-- ── HEADER ──────────────────────────────────── --}}
                <tr>
                    <td style="background:linear-gradient(135deg,#0f2044 0%,#1a3a6e 60%,#1e4d8c 100%);
                               padding:28px 40px 24px;text-align:center;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td align="center" style="padding-bottom:10px;">
                                    @if($logoUrl)
                                    <img src="{{ $logoUrl }}"
                                         alt="{{ $companyName }}"
                                         width="56" height="56"
                                         style="width:56px;height:56px;border-radius:50%;
                                                object-fit:contain;background:#ffffff;
                                                padding:5px;display:block;
                                                margin:0 auto;
                                                border:2px solid rgba(255,255,255,0.3);">
                                    @else
                                    <div style="display:inline-block;background:rgba(255,255,255,0.15);
                                                border-radius:50%;width:56px;height:56px;line-height:56px;
                                                text-align:center;font-size:26px;">🛡️</div>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td align="center">
                                    <p style="margin:0;font-size:20px;font-weight:800;letter-spacing:3px;
                                              color:#ffffff;text-transform:uppercase;">
                                        {{ strtoupper($companyName) }}
                                    </p>
                                    <p style="margin:5px 0 0;font-size:11px;font-weight:400;letter-spacing:2px;
                                              color:#93b8e8;text-transform:uppercase;">
                                        Network Operations Center
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- ── SEVERITY STRIP ──────────────────────────── --}}
                <tr>
                    <td style="{{ $severityBg }};padding:9px 40px;text-align:center;">
                        <span style="color:#ffffff;font-size:11px;font-weight:700;
                                     letter-spacing:2px;text-transform:uppercase;">
                            {{ $severityLabel }}
                        </span>
                    </td>
                </tr>

                {{-- ── BODY ─────────────────────────────────────── --}}
                <tr>
                    <td style="padding:32px 40px 24px;background:#ffffff;">

                        {{-- Title --}}
                        <h1 style="margin:0 0 10px;font-size:21px;font-weight:700;color:#1a2744;line-height:1.3;">
                            {{ $notification->title }}
                        </h1>

                        {{-- Accent divider --}}
                        <div style="height:3px;width:44px;background:{{ $severityColor }};
                                    border-radius:2px;margin-bottom:16px;"></div>

                        {{-- Notification message --}}
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#4b5563;">
                            {{ $notification->message }}
                        </p>

                        {{-- Type + timestamp row --}}
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                               style="margin-bottom:24px;">
                            <tr>
                                <td style="vertical-align:middle;">
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

                        {{-- ── WORKFLOW DETAILS PANEL ──────────────── --}}
                        @if($workflow)
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                               style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;
                                      margin-bottom:24px;overflow:hidden;">
                            {{-- Panel header --}}
                            <tr>
                                <td colspan="2"
                                    style="background:#1a2744;padding:10px 18px;">
                                    <span style="color:#ffffff;font-size:12px;font-weight:700;
                                                 letter-spacing:1px;text-transform:uppercase;">
                                        📋 Request Details
                                    </span>
                                </td>
                            </tr>

                            {{-- Request ID --}}
                            <tr>
                                <td style="padding:10px 18px 6px;font-size:12px;color:#6b7280;
                                           font-weight:600;width:38%;vertical-align:top;">
                                    Request ID
                                </td>
                                <td style="padding:10px 18px 6px;font-size:13px;color:#1e293b;
                                           font-weight:600;vertical-align:top;">
                                    #{{ $workflow->id }}
                                </td>
                            </tr>

                            {{-- Type --}}
                            <tr style="background:#ffffff;">
                                <td style="padding:6px 18px;font-size:12px;color:#6b7280;
                                           font-weight:600;vertical-align:top;">
                                    Workflow Type
                                </td>
                                <td style="padding:6px 18px;font-size:13px;color:#1e293b;vertical-align:top;">
                                    {{ $workflow->typeLabel() }}
                                </td>
                            </tr>

                            {{-- Title --}}
                            <tr>
                                <td style="padding:6px 18px;font-size:12px;color:#6b7280;
                                           font-weight:600;vertical-align:top;">
                                    Title
                                </td>
                                <td style="padding:6px 18px;font-size:13px;color:#1e293b;vertical-align:top;">
                                    {{ $workflow->title }}
                                </td>
                            </tr>

                            @if($workflow->description)
                            {{-- Description --}}
                            <tr style="background:#ffffff;">
                                <td style="padding:6px 18px;font-size:12px;color:#6b7280;
                                           font-weight:600;vertical-align:top;">
                                    Description
                                </td>
                                <td style="padding:6px 18px;font-size:13px;color:#1e293b;vertical-align:top;">
                                    {{ $workflow->description }}
                                </td>
                            </tr>
                            @endif

                            {{-- Requested By --}}
                            <tr @if(!$workflow->description) style="background:#ffffff;" @endif>
                                <td style="padding:6px 18px;font-size:12px;color:#6b7280;
                                           font-weight:600;vertical-align:top;">
                                    Requested By
                                </td>
                                <td style="padding:6px 18px;font-size:13px;color:#1e293b;vertical-align:top;">
                                    {{ $workflow->requester?->name ?? 'System / API' }}
                                </td>
                            </tr>

                            @if($workflow->branch)
                            {{-- Branch --}}
                            <tr style="background:#ffffff;">
                                <td style="padding:6px 18px;font-size:12px;color:#6b7280;
                                           font-weight:600;vertical-align:top;">
                                    Branch
                                </td>
                                <td style="padding:6px 18px;font-size:13px;color:#1e293b;vertical-align:top;">
                                    {{ $workflow->branch->name }}
                                </td>
                            </tr>
                            @endif

                            {{-- Step Progress --}}
                            <tr>
                                <td style="padding:6px 18px;font-size:12px;color:#6b7280;
                                           font-weight:600;vertical-align:top;">
                                    Approval Progress
                                </td>
                                <td style="padding:6px 18px;font-size:13px;color:#1e293b;vertical-align:top;">
                                    Step {{ $workflow->current_step }} of {{ $workflow->total_steps }}
                                    &nbsp;
                                    <span style="display:inline-block;background:{{ $severityColor }}1a;
                                                 color:{{ $severityColor }};font-size:10px;font-weight:700;
                                                 letter-spacing:1px;padding:2px 8px;border-radius:4px;
                                                 text-transform:uppercase;">
                                        {{ ucfirst($workflow->status) }}
                                    </span>
                                </td>
                            </tr>

                            {{-- Submitted At --}}
                            <tr style="background:#ffffff;">
                                <td style="padding:6px 18px 10px;font-size:12px;color:#6b7280;
                                           font-weight:600;vertical-align:top;">
                                    Submitted At
                                </td>
                                <td style="padding:6px 18px 10px;font-size:13px;color:#1e293b;vertical-align:top;">
                                    {{ $workflow->created_at?->format('D, d M Y · H:i') }} UTC
                                </td>
                            </tr>

                            {{-- Payload rows (if any) --}}
                            @if(count($payloadRows) > 0)
                            <tr>
                                <td colspan="2"
                                    style="background:#e2e8f0;padding:8px 18px;">
                                    <span style="color:#475569;font-size:11px;font-weight:700;
                                                 letter-spacing:1px;text-transform:uppercase;">
                                        Request Data
                                    </span>
                                </td>
                            </tr>
                            @foreach($payloadRows as $i => $row)
                            <tr @if($i % 2 === 0) style="background:#ffffff;" @endif>
                                <td style="padding:6px 18px @if($loop->last) 12px @endif;font-size:12px;
                                           color:#6b7280;font-weight:600;vertical-align:top;">
                                    {{ $row['label'] }}
                                </td>
                                <td style="padding:6px 18px @if($loop->last) 12px @endif;font-size:13px;
                                           color:#1e293b;word-break:break-word;vertical-align:top;">
                                    {{ $row['value'] }}
                                </td>
                            </tr>
                            @endforeach
                            @endif

                        </table>
                        @endif
                        {{-- /workflow details panel --}}

                        {{-- CTA button --}}
                        @if($notification->link)
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td align="center" style="padding:4px 0 8px;">
                                    <a href="{{ $notification->link }}"
                                       style="display:inline-block;background:{{ $severityColor }};color:#ffffff;
                                              text-decoration:none;font-size:14px;font-weight:700;
                                              padding:14px 40px;border-radius:8px;letter-spacing:0.5px;">
                                        @if($workflow)
                                            → Review &amp; Approve Request
                                        @else
                                            → View in SG NOC
                                        @endif
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
                    <td style="padding:16px 40px;background:#f9fafb;">
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
                    <td style="background:#f1f5f9;padding:20px 40px;text-align:center;
                               border-top:1px solid #e2e8f0;">
                        <p style="margin:0 0 4px;font-size:13px;font-weight:600;color:#475569;">
                            🛡️ SG NOC — {{ $companyName }} IT Department
                        </p>
                        <p style="margin:0 0 10px;font-size:11px;color:#94a3b8;">
                            This is an automated alert from the Network Operations Center.
                            Please do not reply to this email.
                        </p>
                        <div style="height:1px;background:#e2e8f0;margin:10px auto;max-width:200px;"></div>
                        <p style="margin:0;font-size:11px;color:#94a3b8;">
                            © {{ date('Y') }} {{ $companyName }}. All rights reserved.<br>
                            <span style="color:#cbd5e1;">You are receiving this because you are registered as an admin in SG NOC.</span>
                        </p>
                    </td>
                </tr>

            </table>

            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr><td style="height:32px;"></td></tr>
            </table>

        </td>
    </tr>
</table>

</body>
</html>
