@extends('layouts.admin')
@section('title', 'Edit RADIUS VLAN Policy')

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2 text-primary"></i>Edit VLAN Policy</h4>
            <small class="text-muted">{{ $policy->branch?->name }} · {{ $policy->adapter_type }} · VLAN {{ $policy->vlan_id }}</small>
        </div>
        <a href="{{ route('admin.radius.vlan.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <form action="{{ route('admin.radius.vlan.update', $policy) }}" method="POST">
        @csrf
        @method('PUT')
        @include('admin.radius.vlan._form', ['policy' => $policy])

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-lg me-1"></i>Save Changes
            </button>
            <a href="{{ route('admin.radius.vlan.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

</div>
@endsection
