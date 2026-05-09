@php
    $branchName = $printer->branch?->name ?? '—';
    $assetCode  = $printer->device?->asset_code ?? null;
    $sev        = strtolower($event->severity);
    $sevColor   = match ($sev) {
        'critical' => '#d93025',
        'warning'  => '#f9a825',
        'info'     => '#1976d2',
        default    => '#6c757d',
    };
    $detailUrl = $appUrl . '/admin/printers/unified/' . $printer->id;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $event->title }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#222;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <tr>
                        <td style="background:{{ $sevColor }};color:#fff;padding:16px 24px;font-size:18px;font-weight:bold;">
                            {{ strtoupper($event->severity) }} — Printer Alert
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <h2 style="margin:0 0 8px 0;font-size:18px;color:#222;">{{ $event->title }}</h2>
                            <p style="margin:0 0 16px 0;color:#555;line-height:1.5;">{{ $event->message }}</p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0;">
                                <tr>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;background:#fafafa;width:160px;font-weight:bold;">Printer</td>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;">{{ $printer->printer_name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;background:#fafafa;font-weight:bold;">Branch</td>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;">{{ $branchName }}</td>
                                </tr>
                                @if($assetCode)
                                <tr>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;background:#fafafa;font-weight:bold;">Asset Code</td>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;">{{ $assetCode }}</td>
                                </tr>
                                @endif
                                <tr>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;background:#fafafa;font-weight:bold;">IP Address</td>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;">{{ $printer->ip_address ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;background:#fafafa;font-weight:bold;">Model</td>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;">{{ $printer->manufacturer }} {{ $printer->model }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;background:#fafafa;font-weight:bold;">Detected</td>
                                    <td style="padding:8px 12px;border:1px solid #e5e7eb;">{{ optional($event->first_seen)->format('Y-m-d H:i') }}</td>
                                </tr>
                            </table>

                            <p style="margin:16px 0;">
                                <a href="{{ $detailUrl }}" style="display:inline-block;background:#0d6efd;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;font-weight:bold;">
                                    Open Printer Dashboard
                                </a>
                            </p>

                            <p style="margin:24px 0 0 0;font-size:12px;color:#888;line-height:1.4;">
                                You are receiving this because your address is on the printer-alert recipient list for the <strong>{{ $branchName }}</strong> branch. Only one email is sent per condition — the next email will only be sent after the issue is resolved and re-occurs.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f9fafb;color:#888;font-size:12px;padding:12px 24px;text-align:center;">
                            {{ $companyName }} — sent by {{ $fromName }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
