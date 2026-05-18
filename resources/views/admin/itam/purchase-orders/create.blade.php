@extends('layouts.admin')
@section('content')

<div class="mb-4">
    <h4 class="mb-0 fw-bold"><i class="bi bi-receipt me-2 text-primary"></i>New Purchase Order</h4>
    <small class="text-muted"><a href="{{ route('admin.itam.purchase-orders.index') }}" class="text-decoration-none">Purchase Orders</a> / New</small>
</div>

@include('admin.itam.purchase-orders._form')

@endsection
