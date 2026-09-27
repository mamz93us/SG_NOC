{{-- Everything in one block on purpose: an inline php directive right above a block one swallows it,
     and nothing in the block is assigned. --}}
@php
    $settings = \App\Models\Setting::first();
    $from = \Carbon\Carbon::parse($filters['from']);
    $to = \Carbon\Carbon::parse($filters['to']);
    $kindLabel = $filters['kind'] ? ($kinds[$filters['kind']] ?? $filters['kind']) : 'All movements';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Asset Movements — {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; font-size: 12px; color: #222; background: #f5f5f5; line-height: 1.5; }
        .report-page { max-width: 297mm; margin: 20px auto; background: #fff; padding: 30px 35px; box-shadow: 0 2px 10px rgba(0,0,0,.1); }
        .report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #0d6efd; padding-bottom: 15px; margin-bottom: 20px; }
        .report-header .company-name { font-size: 18px; font-weight: 700; color: #0d6efd; }
        .report-header img { max-height: 55px; max-width: 180px; object-fit: contain; }
        .report-header .report-title { text-align: right; }
        .report-header h1 { font-size: 16px; font-weight: 700; color: #333; margin-bottom: 3px; }
        .report-header .date { font-size: 11px; color: #666; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 13px; font-weight: 700; color: #0d6efd; border-bottom: 1px solid #dee2e6; padding-bottom: 4px; margin-bottom: 10px; text-transform: uppercase; letter-spacing: .5px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 4px 30px; margin-bottom: 15px; }
        .info-item { display: flex; gap: 8px; padding: 3px 0; border-bottom: 1px dotted #eee; }
        .info-label { font-weight: 600; color: #555; min-width: 110px; flex-shrink: 0; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        table th { background: #f0f4f8; color: #333; font-weight: 600; text-align: left; padding: 6px 8px; border: 1px solid #dee2e6; font-size: 10px; text-transform: uppercase; }
        table td { padding: 5px 8px; border: 1px solid #dee2e6; vertical-align: top; }
        table tbody tr:nth-child(even) { background: #fafbfc; }
        .mono { font-family: monospace; }
        .muted { color: #777; }
        .right { text-align: right; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; margin-top: 45px; padding-top: 20px; }
        .signature-block { text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 55px; padding-top: 5px; font-size: 11px; font-weight: 600; color: #555; }
        .signature-date { font-size: 10px; color: #888; margin-top: 3px; }
        .report-footer { margin-top: 25px; padding-top: 10px; border-top: 1px solid #dee2e6; text-align: center; font-size: 10px; color: #999; }
        .no-print-tools { text-align: center; margin-bottom: 15px; }
        .no-print-tools button { background: #0d6efd; color: #fff; border: none; padding: 10px 30px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .no-print-tools a { color: #666; text-decoration: none; margin-left: 15px; font-size: 13px; }
        @media print {
            body { background: #fff; }
            .report-page { margin: 0; padding: 15px 20px; box-shadow: none; max-width: 100%; }
            .no-print-tools { display: none !important; }
            @page { size: A4 landscape; margin: 10mm; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            .signatures { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="no-print-tools">
    <button onclick="window.print()">&#128424; Print</button>
    <a href="{{ url()->previous() }}">&#8592; Back</a>
</div>

<div class="report-page">
    <div class="report-header">
        <div>
            @if($settings && $settings->company_logo)
                <img src="{{ asset('storage/' . $settings->company_logo) }}" alt="Logo">
            @endif
            <div class="company-name">{{ $settings->company_name ?? 'Company' }}</div>
        </div>
        <div class="report-title">
            <h1>Asset Movements</h1>
            <div class="date">{{ $from->format('d M Y') }} — {{ $to->format('d M Y') }}</div>
            <div class="date">Generated {{ now()->format('d M Y, h:i A') }}</div>
        </div>
    </div>

    <div class="section">
        <div class="info-grid">
            <div class="info-item"><span class="info-label">Movements</span><span>{{ number_format($summary['count']) }}</span></div>
            <div class="info-item"><span class="info-label">Shown</span><span>{{ $kindLabel }}</span></div>
            <div class="info-item">
                <span class="info-label">Retired / scrapped at cost</span>
                <span>
                    @forelse ($summary['cost'] as $currency => $total)
                        {{ $currency }} {{ number_format($total, 2) }}@if(! $loop->last), @endif
                    @empty
                        —
                    @endforelse
                </span>
            </div>
            @foreach ($kinds as $key => $label)
                <div class="info-item"><span class="info-label">{{ $label }}</span><span>{{ number_format($summary['kinds'][$key] ?? 0) }}</span></div>
            @endforeach
        </div>
    </div>

    <div class="section">
        <div class="section-title">Movements</div>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Movement</th>
                    <th>Asset code</th>
                    <th>Oracle no.</th>
                    <th>Asset</th>
                    <th>Serial</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Reason</th>
                    <th class="right">Cost</th>
                    <th>Recorded by</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            {{ $row['date']?->format('d M Y') }}
                            @if ($row['recorded_at'] && $row['date'] && ! $row['recorded_at']->isSameDay($row['date']))
                                <div class="muted">rec. {{ $row['recorded_at']->format('d M') }}</div>
                            @endif
                        </td>
                        <td>{{ $row['kind_label'] }}@if($row['workflow_id'])<div class="muted">req #{{ $row['workflow_id'] }}</div>@endif</td>
                        <td class="mono">{{ $row['asset_code'] ?: '—' }}</td>
                        <td class="mono">{{ $row['oracle_asset_number'] ?: '—' }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td class="mono">{{ $row['serial_number'] ?: '—' }}</td>
                        <td>
                            {{ $row['from'] ?: '—' }}
                            @if ($row['from_no'])<div class="muted">Emp #{{ $row['from_no'] }}</div>@endif
                        </td>
                        <td>
                            {{ $row['to'] ?: ($row['storage_location'] ?: '—') }}
                            @if ($row['to_no'])<div class="muted">Emp #{{ $row['to_no'] }}</div>@endif
                        </td>
                        <td>
                            {{ $row['reason_label'] ?: '—' }}
                            @if ($row['reason'] && $row['reason'] !== $row['reason_label'])
                                <div class="muted">{{ $row['reason'] }}</div>
                            @endif
                            @if ($row['disposal_method'])
                                <div class="muted">{{ ucfirst(str_replace('_', ' ', $row['disposal_method'])) }}</div>
                            @endif
                        </td>
                        <td class="right">{{ $row['purchase_cost'] !== null ? $row['currency'].' '.number_format($row['purchase_cost'], 2) : '—' }}</td>
                        <td>{{ $row['by'] ?: 'system' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="muted">No asset moved in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="signatures">
        <div class="signature-block">
            <div class="signature-line">Prepared by (IT)</div>
            <div class="signature-date">Date: ____________</div>
        </div>
        <div class="signature-block">
            <div class="signature-line">IT Manager</div>
            <div class="signature-date">Date: ____________</div>
        </div>
        <div class="signature-block">
            <div class="signature-line">Finance</div>
            <div class="signature-date">Date: ____________</div>
        </div>
    </div>

    <div class="report-footer">
        Asset movements from the NOC's asset history. Oracle asset numbers are the register's own, for posting against Oracle Fixed Assets.
    </div>
</div>

</body>
</html>
