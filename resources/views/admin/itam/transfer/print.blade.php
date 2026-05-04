@php($settings = \App\Models\Setting::first())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Asset Transfer Slip — {{ $transferGroupId }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; font-size: 12px; color: #222; background: #f5f5f5; line-height: 1.5; }
        .report-page { max-width: 210mm; margin: 20px auto; background: #fff; padding: 30px 35px; box-shadow: 0 2px 10px rgba(0,0,0,.1); }
        .report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #0d6efd; padding-bottom: 15px; margin-bottom: 20px; }
        .report-header .company-name { font-size: 18px; font-weight: 700; color: #0d6efd; }
        .report-header img { max-height: 55px; max-width: 180px; object-fit: contain; }
        .report-header .report-title { text-align: right; }
        .report-header h1 { font-size: 16px; font-weight: 700; color: #333; margin-bottom: 3px; }
        .report-header .date { font-size: 11px; color: #666; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 13px; font-weight: 700; color: #0d6efd; border-bottom: 1px solid #dee2e6; padding-bottom: 4px; margin-bottom: 10px; text-transform: uppercase; letter-spacing: .5px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 30px; margin-bottom: 15px; }
        .info-item { display: flex; gap: 8px; padding: 3px 0; border-bottom: 1px dotted #eee; }
        .info-label { font-weight: 600; color: #555; min-width: 110px; flex-shrink: 0; }
        .info-value { color: #222; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        table th { background: #f0f4f8; color: #333; font-weight: 600; text-align: left; padding: 6px 8px; border: 1px solid #dee2e6; font-size: 10px; text-transform: uppercase; }
        table td { padding: 5px 8px; border: 1px solid #dee2e6; vertical-align: top; }
        table tbody tr:nth-child(even) { background: #fafbfc; }
        .badge-sm { display: inline-block; padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: 600; }
        .badge-good { background: #d1e7dd; color: #0f5132; }
        .badge-fair { background: #fff3cd; color: #664d03; }
        .badge-poor { background: #f8d7da; color: #842029; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 30px; margin-top: 50px; padding-top: 20px; }
        .signature-block { text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 60px; padding-top: 5px; font-size: 11px; font-weight: 600; color: #555; }
        .signature-date { font-size: 10px; color: #888; margin-top: 3px; }
        .report-footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #dee2e6; text-align: center; font-size: 10px; color: #999; }
        .no-print-tools { text-align: center; margin-bottom: 15px; }
        .no-print-tools button { background: #0d6efd; color: #fff; border: none; padding: 10px 30px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .no-print-tools a { color: #666; text-decoration: none; margin-left: 15px; font-size: 13px; }
        .ref { font-family: monospace; font-size: 11px; color: #555; }
        @media print {
            body { background: #fff; }
            .report-page { margin: 0; padding: 15px 20px; box-shadow: none; max-width: 100%; }
            .no-print-tools { display: none !important; }
            @page { size: A4 portrait; margin: 12mm 10mm; }
            .section, .signatures { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="no-print-tools">
    <button onclick="window.print()">&#128424; Print Transfer Slip</button>
    <a href="{{ route('admin.itam.transfer.index') }}">&#8592; Back to Transfer</a>
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
            <h1>Asset Transfer Slip</h1>
            <div class="date">Generated: {{ now()->format('d M Y, h:i A') }}</div>
            <div class="ref">Ref: {{ substr($transferGroupId, 0, 8) }}</div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Transfer Details</div>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Transfer Date</span>
                <span class="info-value">{{ $transferDate->format('d M Y') }}</span>
            </div>
            <div class="info-item">
                <span class="info-label">Transfer Type</span>
                <span class="info-value">
                    @if($isStore)
                        <strong style="color:#0dcaf0">Employee &rarr; Branch Store</strong>
                    @else
                        <strong style="color:#0d6efd">Employee &rarr; Employee</strong>
                    @endif
                </span>
            </div>
            <div class="info-item">
                <span class="info-label">From</span>
                <span class="info-value">{{ $fromEmployee?->name ?? '—' }} ({{ $fromEmployee?->email ?? '' }})</span>
            </div>
            <div class="info-item">
                <span class="info-label">To</span>
                <span class="info-value">
                    @if($isStore)
                        {{ $toBranch?->name }} — {{ $storageLocation }}
                    @else
                        {{ $toEmployee?->name ?? '—' }} ({{ $toEmployee?->email ?? '' }})
                    @endif
                </span>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Assets Transferred ({{ $events->count() }})</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Asset Code</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Serial Number</th>
                    <th>Condition</th>
                </tr>
            </thead>
            <tbody>
                @foreach($events as $i => $e)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td><strong>{{ $e->device?->asset_code ?? '—' }}</strong></td>
                        <td>{{ $e->device?->name ?? '—' }}</td>
                        <td>{{ $e->device?->type ?? '—' }}</td>
                        <td>{{ $e->device?->serial_number ?? '—' }}</td>
                        <td><span class="badge-sm badge-{{ $e->meta['condition'] ?? 'good' }}">{{ ucfirst($e->meta['condition'] ?? 'good') }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="signatures">
        <div class="signature-block">
            <div class="signature-line">Outgoing ({{ $fromEmployee?->name ?? '—' }})</div>
            <div class="signature-date">Signature & Date</div>
        </div>
        <div class="signature-block">
            <div class="signature-line">
                @if($isStore)
                    Branch Store Custodian
                @else
                    Incoming ({{ $toEmployee?->name ?? '—' }})
                @endif
            </div>
            <div class="signature-date">Signature & Date</div>
        </div>
        <div class="signature-block">
            <div class="signature-line">IT Department</div>
            <div class="signature-date">Signature & Date</div>
        </div>
    </div>

    <div class="report-footer">
        Transfer reference: {{ $transferGroupId }} — All assets above remain under IT supervision.
    </div>
</div>
</body>
</html>
