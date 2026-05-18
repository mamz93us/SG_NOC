@php($settings = \App\Models\Setting::first())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disposal Certificate — Workflow #{{ $workflow->id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; font-size: 12px; color: #222; background: #f5f5f5; line-height: 1.5; }
        .report-page { max-width: 210mm; margin: 20px auto; background: #fff; padding: 30px 35px; box-shadow: 0 2px 10px rgba(0,0,0,.1); }
        .report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #dc3545; padding-bottom: 15px; margin-bottom: 20px; }
        .report-header .company-name { font-size: 18px; font-weight: 700; color: #dc3545; }
        .report-header img { max-height: 55px; max-width: 180px; object-fit: contain; }
        .report-header .report-title { text-align: right; }
        .report-header h1 { font-size: 16px; font-weight: 700; color: #333; margin-bottom: 3px; }
        .report-header .date { font-size: 11px; color: #666; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 13px; font-weight: 700; color: #dc3545; border-bottom: 1px solid #dee2e6; padding-bottom: 4px; margin-bottom: 10px; text-transform: uppercase; letter-spacing: .5px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 30px; }
        .info-item { display: flex; gap: 8px; padding: 3px 0; border-bottom: 1px dotted #eee; }
        .info-label { font-weight: 600; color: #555; min-width: 110px; flex-shrink: 0; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        table th { background: #f8d7da; color: #842029; font-weight: 600; text-align: left; padding: 6px 8px; border: 1px solid #dee2e6; font-size: 10px; text-transform: uppercase; }
        table td { padding: 5px 8px; border: 1px solid #dee2e6; vertical-align: top; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; margin-top: 50px; padding-top: 20px; }
        .signature-block { text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 60px; padding-top: 5px; font-size: 11px; font-weight: 600; color: #555; }
        .approvals-list li { padding: 4px 0; border-bottom: 1px dotted #eee; }
        .stamp { display: inline-block; padding: 8px 16px; border: 3px double #dc3545; color: #dc3545; font-weight: bold; transform: rotate(-5deg); margin-top: 20px; font-size: 14px; }
        .no-print-tools { text-align: center; margin-bottom: 15px; }
        .no-print-tools button { background: #dc3545; color: #fff; border: none; padding: 10px 30px; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
        @media print {
            body { background: #fff; }
            .report-page { margin: 0; padding: 15px 20px; box-shadow: none; max-width: 100%; }
            .no-print-tools { display: none !important; }
            @page { size: A4 portrait; margin: 12mm 10mm; }
        }
    </style>
</head>
<body>

<div class="no-print-tools">
    <button onclick="window.print()">&#128424; Print Disposal Certificate</button>
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
            <h1>ASSET DISPOSAL CERTIFICATE</h1>
            <div class="date">Workflow #{{ $workflow->id }} — Issued: {{ now()->format('d M Y, h:i A') }}</div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Disposal Authorization</div>
        <div class="info-grid">
            <div class="info-item"><span class="info-label">Requested By</span><span>{{ $workflow->requester?->name ?? '—' }}</span></div>
            <div class="info-item"><span class="info-label">Request Date</span><span>{{ $workflow->created_at->format('d M Y') }}</span></div>
            <div class="info-item"><span class="info-label">Disposal Method</span><span>{{ ucwords(str_replace('_', ' ', $workflow->payload['disposal_method'] ?? '—')) }}</span></div>
            <div class="info-item"><span class="info-label">Branch</span><span>{{ $workflow->branch?->name ?? '—' }}</span></div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Reason for Disposal</div>
        <p>{{ $workflow->payload['reason'] ?? $workflow->description }}</p>
    </div>

    @if($devices->count() > 0)
    <div class="section">
        <div class="section-title">Disposed Devices ({{ $devices->count() }})</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Asset Code</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Serial Number</th>
                    <th>Branch</th>
                </tr>
            </thead>
            <tbody>
                @foreach($devices as $i => $d)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td><strong>{{ $d->asset_code }}</strong></td>
                        <td>{{ $d->name }}</td>
                        <td>{{ $d->type }}</td>
                        <td>{{ $d->serial_number ?? '—' }}</td>
                        <td>{{ $d->branch?->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if($accessories->count() > 0)
    @php($accessoryQty = $workflow->payload['accessory_qty'] ?? [])
    <div class="section">
        <div class="section-title">Disposed Accessories ({{ $accessories->count() }})</div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Asset Code</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Qty Disposed</th>
                    <th>Branch</th>
                </tr>
            </thead>
            <tbody>
                @foreach($accessories as $i => $a)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td><strong>{{ $a->asset_code ?? '—' }}</strong></td>
                        <td>{{ $a->name }}</td>
                        <td>{{ $a->category ?: '—' }}</td>
                        <td>{{ $accessoryQty[$a->id] ?? $a->quantity_total }}</td>
                        <td>{{ $a->branch?->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="section">
        <div class="section-title">Approval Chain</div>
        <ul class="approvals-list" style="list-style:none">
            @foreach($workflow->steps as $step)
                <li>
                    <strong>Step {{ $step->step_number }}:</strong> {{ $step->approverRoleLabel() }} —
                    @if($step->status === 'approved')
                        Approved by <strong>{{ $step->actor?->name ?? '—' }}</strong> on {{ $step->acted_at?->format('d M Y H:i') }}
                        @if($step->comments) — "{{ $step->comments }}"@endif
                    @else
                        {{ ucfirst($step->status) }}
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    <div class="signatures">
        <div class="signature-block">
            <div class="signature-line">IT Manager</div>
            <div style="font-size:10px;color:#888">Signature & Date</div>
        </div>
        <div class="signature-block">
            <div class="signature-line">Super Admin</div>
            <div style="font-size:10px;color:#888">Signature & Date</div>
        </div>
    </div>

    <div style="text-align:center;margin-top:30px;padding-top:10px;border-top:1px solid #dee2e6;font-size:10px;color:#999">
        This certificate confirms the official disposal of the assets listed above, under IT supervision.
    </div>
</div>
</body>
</html>
