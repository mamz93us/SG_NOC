@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-patch-check-fill me-2 text-primary"></i>License Overview</h4>
        <small class="text-muted">
            Microsoft 365 license subscriptions
            @if($lastSync)
            &mdash; last sync {{ $lastSync->created_at->diffForHumans() }}
            @endif
        </small>
    </div>
    @can('manage-identity')
    <form method="POST" action="{{ route('admin.identity.sync') }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-arrow-repeat me-1"></i>Sync Now
        </button>
    </form>
    @endcan
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

@if($licenses->isEmpty())
<div class="card shadow-sm">
    <div class="text-center py-5 text-muted">
        <i class="bi bi-patch-check display-4 d-block mb-2"></i>
        No license data. Run a sync to import from Entra ID.
    </div>
</div>
@else
<div class="row g-3">
    @foreach($licenses as $lic)
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="fw-bold mb-0">{{ $lic->friendlyName() }}</h6>
                        <div class="text-muted" style="font-size:.75rem">{{ $lic->sku_part_number }}</div>
                    </div>
                    <span class="badge bg-{{ $lic->capability_status === 'Enabled' ? 'success' : 'secondary' }}">
                        {{ $lic->capability_status }}
                    </span>
                </div>

                {{-- Usage bar --}}
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>{{ $lic->consumed }} / {{ $lic->total }} used</span>
                    <span>{{ $lic->available }} available</span>
                </div>
                <div class="progress" style="height:6px">
                    <div class="progress-bar {{ $lic->usageBarClass() }}"
                         role="progressbar"
                         style="width:{{ $lic->usagePercent() }}%"
                         aria-valuenow="{{ $lic->usagePercent() }}"
                         aria-valuemin="0" aria-valuemax="100">
                    </div>
                </div>
                <div class="text-end small text-muted mt-1">{{ $lic->usagePercent() }}%</div>
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif
@endsection
