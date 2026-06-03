@php
    $barColor = fn ($p) => $p <= 5 ? '#d93025' : ($p <= 20 ? '#f9a825' : '#2e7d32');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Monthly Low-Toner Report</title>
</head>
<body style="margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#222;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f6f8;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">
                    <tr>
                        <td style="background:#0d6efd;color:#fff;padding:16px 24px;font-size:18px;font-weight:bold;">
                            Monthly Low-Toner Report — {{ $period }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            @if($total === 0)
                            <p style="margin:0;color:#2e7d32;font-size:16px;">✓ No printers are low on toner this month.</p>
                            @else
                            <p style="margin:0 0 16px 0;color:#555;line-height:1.5;">
                                <strong>{{ $total }}</strong> cartridge{{ $total === 1 ? '' : 's' }} across
                                <strong>{{ count($groups) }}</strong> branch{{ count($groups) === 1 ? '' : 'es' }}
                                {{ $total === 1 ? 'is' : 'are' }} at or below the warning threshold and should be ordered / replaced.
                            </p>

                            @foreach($groups as $group)
                            <h3 style="margin:20px 0 8px 0;font-size:15px;color:#222;border-bottom:2px solid #eee;padding-bottom:4px;">
                                {{ $group['branch'] }}
                                <span style="font-weight:normal;color:#888;font-size:13px;">({{ count($group['rows']) }})</span>
                            </h3>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:8px;font-size:13px;">
                                <tr style="background:#fafafa;">
                                    <td style="padding:6px 10px;border:1px solid #e5e7eb;font-weight:bold;">Printer</td>
                                    <td style="padding:6px 10px;border:1px solid #e5e7eb;font-weight:bold;">Location</td>
                                    <td style="padding:6px 10px;border:1px solid #e5e7eb;font-weight:bold;">Cartridge</td>
                                    <td style="padding:6px 10px;border:1px solid #e5e7eb;font-weight:bold;width:120px;">Level</td>
                                </tr>
                                @foreach($group['rows'] as $row)
                                <tr>
                                    <td style="padding:6px 10px;border:1px solid #e5e7eb;">{{ $row['printer'] }}</td>
                                    <td style="padding:6px 10px;border:1px solid #e5e7eb;color:#666;">{{ $row['location'] }}</td>
                                    <td style="padding:6px 10px;border:1px solid #e5e7eb;">{{ $row['color'] }}</td>
                                    <td style="padding:6px 10px;border:1px solid #e5e7eb;">
                                        <span style="display:inline-block;min-width:34px;font-weight:bold;color:{{ $barColor($row['percent']) }};">{{ $row['percent'] }}%</span>
                                        <span style="display:inline-block;width:60px;height:8px;background:#eee;border-radius:4px;vertical-align:middle;overflow:hidden;">
                                            <span style="display:inline-block;height:8px;width:{{ max(2, min(100, $row['percent'])) }}%;background:{{ $barColor($row['percent']) }};"></span>
                                        </span>
                                    </td>
                                </tr>
                                @endforeach
                            </table>
                            @endforeach

                            <p style="margin:20px 0 0 0;">
                                <a href="{{ $appUrl }}/admin/printers/snmp-status" style="display:inline-block;background:#0d6efd;color:#fff;padding:10px 20px;border-radius:4px;text-decoration:none;font-weight:bold;">
                                    Open Printer SNMP Dashboard
                                </a>
                            </p>
                            @endif

                            <p style="margin:24px 0 0 0;font-size:12px;color:#888;line-height:1.4;">
                                This is the monthly low-toner summary. Individual toner emails are turned off to reduce inbox noise —
                                you receive this one consolidated report each month. Printer errors and paper-out alerts are still sent immediately.
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
