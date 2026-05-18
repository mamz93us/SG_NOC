@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-receipt me-2 text-primary"></i>Purchase Orders</h4>
        <small class="text-muted">Add devices, accessories, and licenses through a PO with line items</small>
    </div>
    @can('manage-itam')
    <a href="{{ route('admin.itam.purchase-orders.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New Purchase Order
    </a>
    @endcan
</div>

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="PO number" value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="supplier_id" class="form-select form-select-sm">
            <option value="">All Suppliers</option>
            @foreach($suppliers as $s)
            <option value="{{ $s->id }}" {{ request('supplier_id') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <input type="date" name="from" class="form-control form-control-sm" value="{{ request('from') }}">
    </div>
    <div class="col-auto">
        <input type="date" name="to" class="form-control form-control-sm" value="{{ request('to') }}">
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.itam.purchase-orders.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($orders->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-receipt display-4 d-block mb-2"></i>No purchase orders found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>PO Number</th>
                        <th>Date</th>
                        <th>Supplier</th>
                        <th>Lines</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orders as $po)
                    <tr>
                        <td class="fw-semibold font-monospace"><a href="{{ route('admin.itam.purchase-orders.show', $po) }}">{{ $po->po_number }}</a></td>
                        <td>{{ $po->po_date->format('Y-m-d') }}</td>
                        <td>{{ $po->supplier?->name ?: '—' }}</td>
                        <td>{{ $po->items_count }}</td>
                        <td>{{ number_format($po->total, 2) }} {{ $po->currency }}</td>
                        <td><span class="badge {{ $po->statusBadgeClass() }}">{{ ucfirst($po->status) }}</span></td>
                        <td class="text-nowrap">
                            <a href="{{ route('admin.itam.purchase-orders.show', $po) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                            @can('manage-itam')
                            <a href="{{ route('admin.itam.purchase-orders.edit', $po) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('admin.itam.purchase-orders.destroy', $po) }}" class="d-inline" onsubmit="return confirm('Delete PO {{ $po->po_number }}? Underlying assets stay; only the PO link is removed.')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $orders->links() }}</div>
        @endif
    </div>
</div>

@endsection
