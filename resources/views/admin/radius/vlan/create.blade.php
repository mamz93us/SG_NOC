@extends('layouts.admin')
@section('title', 'Add RADIUS VLAN Policy')

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2 text-primary"></i>Add VLAN Policy</h4>
            <small class="text-muted">Default VLAN for a branch. Wins over more general rows; loses to per-MAC override.</small>
        </div>
        <a href="{{ route('admin.radius.vlan.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <form action="{{ route('admin.radius.vlan.store') }}" method="POST">
        @csrf
        @include('admin.radius.vlan._form', ['policy' => null])

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-lg me-1"></i>Save Policy
            </button>
            <a href="{{ route('admin.radius.vlan.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

</div>
@endsection
