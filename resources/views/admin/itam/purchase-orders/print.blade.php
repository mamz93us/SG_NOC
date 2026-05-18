<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>PO {{ $po->po_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; font-size: 12px; }
        h1 { font-size: 18px; margin: 0 0 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; }
        th { background: #eee; }
        .totals td { text-align: right; }
        .totals td.label { font-weight: bold; }
        .header { display: flex; justify-content: space-between; margin-bottom: 10px; }
        @media print { button { display: none; } }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Purchase Order — {{ $po->po_number }}</h1>
            <div>Date: {{ $po->po_date->format('Y-m-d') }}</div>
            <div>Supplier: {{ $po->supplier?->name ?: '—' }}</div>
            <div>Status: {{ ucfirst($po->status) }}</div>
        </div>
        <div>
            <button onclick="window.print()">Print</button>
        </div>
    </div>

    @if($po->notes)<p><strong>Notes:</strong> {{ $po->notes }}</p>@endif

    <table>
        <thead>
            <tr><th>#</th><th>Type</th><th>Name</th><th>Manufacturer/Model</th><th>Serial/Detail</th><th>Branch</th><th>Qty</th><th>Unit Cost</th><th>Total</th></tr>
        </thead>
        <tbody>
        @foreach($po->items as $i => $line)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ ucfirst($line->line_type) }}</td>
                <td>{{ $line->name }}</td>
                <td>{{ trim(($line->manufacturer ?? '').' '.($line->model ?? '')) ?: '—' }}</td>
                <td>{{ $line->serial_number ?? $line->category ?? ($line->license_type.'/'.$line->seats.'st') }}</td>
                <td>{{ $line->branch?->name ?: '—' }}</td>
                <td>{{ $line->quantity }}</td>
                <td>{{ number_format($line->unit_cost, 2) }} {{ $po->currency }}</td>
                <td>{{ number_format($line->lineTotal(), 2) }} {{ $po->currency }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot class="totals">
            <tr><td colspan="8" class="label">Subtotal</td><td>{{ number_format($po->subtotal, 2) }} {{ $po->currency }}</td></tr>
            <tr><td colspan="8" class="label">Tax</td><td>{{ number_format($po->tax, 2) }} {{ $po->currency }}</td></tr>
            <tr><td colspan="8" class="label">Total</td><td>{{ number_format($po->total, 2) }} {{ $po->currency }}</td></tr>
        </tfoot>
    </table>
</body>
</html>
