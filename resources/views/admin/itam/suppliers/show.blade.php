@extends('layouts.admin')
@section('title', $supplier->name)

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-building me-2"></i>{{ $supplier->name }}</h4>
            <a href="{{ route('admin.itam.suppliers.index') }}" class="text-muted small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to suppliers
            </a>
        </div>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    {{-- Contact + summary cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold">Contact</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th class="text-muted" style="width:35%">Contact Person</th><td>{{ $supplier->contact_person ?: '—' }}</td></tr>
                        <tr><th class="text-muted">Email</th><td>
                            @if($supplier->email)
                                <a href="mailto:{{ $supplier->email }}">{{ $supplier->email }}</a>
                            @else
                                —
                            @endif
                        </td></tr>
                        <tr><th class="text-muted">Phone</th><td class="font-monospace">{{ $supplier->phone ?: '—' }}</td></tr>
                        <tr><th class="text-muted">Address</th><td>{{ $supplier->address ?: '—' }}</td></tr>
                        @if($supplier->notes)
                        <tr><th class="text-muted">Notes</th><td class="text-muted small">{{ $supplier->notes }}</td></tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold">Summary</div>
                <div class="card-body">
                    <div class="row g-3 text-center">
                        <div class="col-4">
                            <div class="display-6 fw-bold text-primary">{{ $supplier->devices_count }}</div>
                            <div class="small text-muted">Devices</div>
                        </div>
                        <div class="col-4">
                            <div class="display-6 fw-bold text-info">{{ $supplier->accessories_count }}</div>
                            <div class="small text-muted">Accessories</div>
                        </div>
                        <div class="col-4">
                            <div class="display-6 fw-bold text-success">{{ $supplier->licenses_count }}</div>
                            <div class="small text-muted">Licenses</div>
                        </div>
                    </div>
                    <hr>
                    <div class="text-muted small mb-1">Total Spend</div>
                    @php($spend = $supplier->totalSpendByCurrency())
                    @if(empty($spend))
                        <div class="fw-bold text-dark">—</div>
                    @else
                        @foreach($spend as $code => $amount)
                            <div class="fw-bold text-dark font-monospace">{{ $code }} {{ number_format($amount, 2) }}</div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Devices --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">
            <i class="bi bi-pc-display me-1"></i>Devices ({{ $supplier->devices_count }})
        </div>
        <div class="card-body p-0">
            @if($devices->isEmpty())
                <p class="text-muted small p-3 mb-0">No devices linked to this supplier.</p>
            @else
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Asset Code</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Branch</th>
                            <th>Status</th>
                            <th class="text-end">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($devices as $d)
                        <tr>
                            <td class="font-monospace small">{{ $d->asset_code ?: '—' }}</td>
                            <td><a href="{{ route('admin.devices.show', $d) }}" class="text-decoration-none fw-semibold">{{ $d->name }}</a></td>
                            <td><span class="badge bg-secondary">{{ $d->type }}</span></td>
                            <td>{{ $d->branch?->name ?: '—' }}</td>
                            <td><span class="badge {{ $d->statusBadgeClass() }}">{{ $d->status }}</span></td>
                            <td class="text-end font-monospace">
                                {{ $d->purchase_cost ? ($d->currency ?? 'USD') . ' ' . number_format($d->purchase_cost, 2) : '—' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Accessories --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">
            <i class="bi bi-box-seam me-1"></i>Accessories ({{ $supplier->accessories_count }})
        </div>
        <div class="card-body p-0">
            @if($accessories->isEmpty())
                <p class="text-muted small p-3 mb-0">No accessories linked to this supplier.</p>
            @else
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Available</th>
                            <th class="text-end">Cost (per unit)</th>
                            <th class="text-end">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($accessories as $a)
                        <tr>
                            <td class="fw-semibold">{{ $a->name }}</td>
                            <td><span class="badge bg-secondary">{{ $a->category ?: '—' }}</span></td>
                            <td class="text-center">{{ $a->quantity_total }}</td>
                            <td class="text-center"><span class="badge bg-{{ $a->availabilityBadgeClass() }}">{{ $a->quantity_available }}</span></td>
                            <td class="text-end font-monospace">
                                {{ $a->purchase_cost ? ($a->currency ?? 'USD') . ' ' . number_format($a->purchase_cost, 2) : '—' }}
                            </td>
                            <td class="text-end font-monospace fw-semibold">
                                {{ $a->purchase_cost ? ($a->currency ?? 'USD') . ' ' . number_format($a->purchase_cost * $a->quantity_total, 2) : '—' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Licenses --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">
            <i class="bi bi-key me-1"></i>Licenses ({{ $supplier->licenses_count }})
        </div>
        <div class="card-body p-0">
            @if($licenses->isEmpty())
                <p class="text-muted small p-3 mb-0">No licenses linked to this supplier.</p>
            @else
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>License</th>
                            <th>Type</th>
                            <th>Seats</th>
                            <th>Expiry</th>
                            <th class="text-end">Cost (per seat)</th>
                            <th class="text-end">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($licenses as $l)
                        <tr>
                            <td class="fw-semibold">{{ $l->license_name }}</td>
                            <td><span class="badge bg-secondary">{{ ucfirst($l->license_type) }}</span></td>
                            <td>{{ $l->usedSeats() }}/{{ $l->seats }}</td>
                            <td>
                                @if($l->expiry_date)
                                    <span class="badge bg-{{ $l->expiryBadgeClass() }}">{{ $l->expiry_date->format('d M Y') }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end font-monospace">
                                {{ $l->cost ? ($l->currency ?? 'USD') . ' ' . number_format($l->cost, 2) : '—' }}
                            </td>
                            <td class="text-end font-monospace fw-semibold">
                                {{ $l->cost ? ($l->currency ?? 'USD') . ' ' . number_format($l->cost * max(1, $l->seats), 2) : '—' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
@endsection
