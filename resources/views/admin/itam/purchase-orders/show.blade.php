@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-receipt me-2 text-primary"></i>PO {{ $po->po_number }}</h4>
        <small class="text-muted"><a href="{{ route('admin.itam.purchase-orders.index') }}" class="text-decoration-none">Purchase Orders</a> / {{ $po->po_number }}</small>
    </div>
    <div>
        <a href="{{ route('admin.itam.purchase-orders.print', $po) }}" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-printer me-1"></i>Print</a>
        @can('manage-itam')
        <a href="{{ route('admin.itam.purchase-orders.edit', $po) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</a>
        @endcan
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3 small">
            <div class="col-md-3"><strong>Date:</strong> {{ $po->po_date->format('Y-m-d') }}</div>
            <div class="col-md-3"><strong>Supplier:</strong> {{ $po->supplier?->name ?: '—' }}</div>
            <div class="col-md-2"><strong>Currency:</strong> {{ $po->currency }}</div>
            <div class="col-md-2"><strong>Status:</strong> <span class="badge {{ $po->statusBadgeClass() }}">{{ ucfirst($po->status) }}</span></div>
            <div class="col-md-2"><strong>Total:</strong> {{ number_format($po->total, 2) }}</div>
            @if($po->notes)
            <div class="col-12"><strong>Notes:</strong> {{ $po->notes }}</div>
            @endif
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header"><strong>Line Items ({{ $po->items->count() }})</strong></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Serial / Detail</th>
                        <th>Branch</th>
                        <th>Qty</th>
                        <th>Unit Cost</th>
                        <th>Line Total</th>
                        <th>Asset</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($po->items as $line)
                    <tr>
                        <td><span class="badge bg-secondary">{{ ucfirst($line->line_type) }}</span></td>
                        <td>{{ $line->name }}{{ $line->manufacturer ? ' — '.$line->manufacturer : '' }}{{ $line->model ? ' / '.$line->model : '' }}</td>
                        <td class="font-monospace small">
                            @if($line->line_type === 'device')
                                {{ $line->serial_number ?? '—' }}
                            @elseif($line->line_type === 'accessory')
                                {{ $line->category }}
                            @else
                                {{ $line->license_type }} / {{ $line->seats }} seats{{ $line->expiry_date ? ' / exp '.$line->expiry_date->format('Y-m-d') : '' }}
                            @endif
                        </td>
                        <td>{{ $line->branch?->name ?: '—' }}</td>
                        <td>{{ $line->quantity }}</td>
                        <td>{{ number_format($line->unit_cost, 2) }}</td>
                        <td>{{ number_format($line->lineTotal(), 2) }}</td>
                        <td>
                            @if($line->asset_id)
                                @if($line->line_type === 'device')
                                    <a href="{{ route('admin.devices.show', $line->asset_id) }}" class="small">#{{ $line->asset_id }}</a>
                                @else
                                    <span class="text-muted small">#{{ $line->asset_id }}</span>
                                @endif
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="6" class="text-end fw-semibold">Subtotal</td>
                        <td>{{ number_format($po->subtotal, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="6" class="text-end fw-semibold">Tax</td>
                        <td>{{ number_format($po->tax, 2) }}</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="6" class="text-end fw-bold">Total ({{ $po->currency }})</td>
                        <td class="fw-bold">{{ number_format($po->total, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

@endsection
