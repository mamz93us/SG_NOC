@extends('layouts.admin')
@section('content')

<div class="mb-4">
    <h4 class="mb-0 fw-bold"><i class="bi bi-receipt me-2 text-primary"></i>Edit PO {{ $po->po_number }}</h4>
    <small class="text-muted">
        <a href="{{ route('admin.itam.purchase-orders.index') }}" class="text-decoration-none">Purchase Orders</a> /
        <a href="{{ route('admin.itam.purchase-orders.show', $po) }}" class="text-decoration-none">{{ $po->po_number }}</a> / Edit
    </small>
    <div class="alert alert-info small mt-2 mb-0">
        Editing a PO updates the header only. To change line items, delete the PO (assets are preserved) and create a new one.
    </div>
</div>

@include('admin.itam.purchase-orders._form')

@endsection
