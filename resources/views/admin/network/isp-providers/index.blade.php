@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-building me-2 text-primary"></i>ISP Providers</h4>
        <small class="text-muted">Editable list of providers and their packages. Used by ISP Connections.</small>
    </div>
    @can('manage-network-settings')
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newProviderModal">
        <i class="bi bi-plus-lg me-1"></i>Add Provider
    </button>
    @endcan
</div>

@if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

@if($providers->isEmpty())
<div class="card shadow-sm"><div class="card-body text-center py-5 text-muted">
    <i class="bi bi-building display-4 d-block mb-2"></i>No providers yet. Click "Add Provider" to start.
</div></div>
@else

@foreach($providers as $provider)
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <strong>{{ $provider->name }}</strong>
            <span class="text-muted small">— {{ $provider->connections_count }} connection(s), {{ $provider->packages->count() }} package(s)</span>
        </div>
        @can('manage-network-settings')
        <div>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editProvider{{ $provider->id }}"><i class="bi bi-pencil"></i></button>
            <form method="POST" action="{{ route('admin.network.isp-providers.destroy', $provider) }}" class="d-inline" onsubmit="return confirm('Delete provider {{ $provider->name }}?')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
        </div>
        @endcan
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-2">
                <thead class="table-light">
                    <tr>
                        <th>Package Name</th>
                        <th style="width:120px">Down Mbps</th>
                        <th style="width:120px">Up Mbps</th>
                        <th style="width:160px">Monthly Cost</th>
                        <th>Notes</th>
                        <th style="width:120px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($provider->packages as $pkg)
                    <tr>
                        <td class="fw-semibold">{{ $pkg->name }}</td>
                        <td>{{ $pkg->speed_down ?: '—' }}</td>
                        <td>{{ $pkg->speed_up ?: '—' }}</td>
                        <td>{{ $pkg->monthly_cost ? number_format($pkg->monthly_cost, 2) : '—' }}</td>
                        <td class="text-muted small">{{ $pkg->notes ?: '—' }}</td>
                        <td class="text-nowrap">
                            @can('manage-network-settings')
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editPackage{{ $pkg->id }}"><i class="bi bi-pencil"></i></button>
                            <form method="POST" action="{{ route('admin.network.isp-providers.packages.destroy', [$provider, $pkg]) }}" class="d-inline" onsubmit="return confirm('Delete package {{ $pkg->name }}?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>

                    {{-- Edit-package modal --}}
                    @can('manage-network-settings')
                    <div class="modal fade" id="editPackage{{ $pkg->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <form method="POST" action="{{ route('admin.network.isp-providers.packages.update', [$provider, $pkg]) }}">
                                @csrf @method('PUT')
                                <div class="modal-content">
                                    <div class="modal-header"><h5 class="modal-title">Edit Package</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                    <div class="modal-body row g-2">
                                        <div class="col-12"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" value="{{ $pkg->name }}" required></div>
                                        <div class="col-md-6"><label class="form-label">Down Mbps</label><input type="number" name="speed_down" class="form-control" value="{{ $pkg->speed_down }}" min="0"></div>
                                        <div class="col-md-6"><label class="form-label">Up Mbps</label><input type="number" name="speed_up" class="form-control" value="{{ $pkg->speed_up }}" min="0"></div>
                                        <div class="col-md-6"><label class="form-label">Monthly Cost</label><input type="number" step="0.01" name="monthly_cost" class="form-control" value="{{ $pkg->monthly_cost }}" min="0"></div>
                                        <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2">{{ $pkg->notes }}</textarea></div>
                                    </div>
                                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
                                </div>
                            </form>
                        </div>
                    </div>
                    @endcan
                    @empty
                    <tr><td colspan="6" class="text-center text-muted small py-3">No packages yet for this provider.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @can('manage-network-settings')
        <form method="POST" action="{{ route('admin.network.isp-providers.packages.store', $provider) }}" class="row g-2 align-items-end small">
            @csrf
            <div class="col-md-3">
                <label class="form-label small mb-1">Add Package</label>
                <input type="text" name="name" class="form-control form-control-sm" placeholder="Package name" required>
            </div>
            <div class="col-md-2"><input type="number" name="speed_down" class="form-control form-control-sm" placeholder="Down Mbps" min="0"></div>
            <div class="col-md-2"><input type="number" name="speed_up" class="form-control form-control-sm" placeholder="Up Mbps" min="0"></div>
            <div class="col-md-2"><input type="number" step="0.01" name="monthly_cost" class="form-control form-control-sm" placeholder="Monthly cost" min="0"></div>
            <div class="col-md-2"><input type="text" name="notes" class="form-control form-control-sm" placeholder="Notes (opt.)"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg"></i></button></div>
        </form>
        @endcan
    </div>
</div>

{{-- Edit-provider modal --}}
@can('manage-network-settings')
<div class="modal fade" id="editProvider{{ $provider->id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.network.isp-providers.update', $provider) }}">
            @csrf @method('PUT')
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Edit Provider</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body row g-2">
                    <div class="col-12"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" value="{{ $provider->name }}" required></div>
                    <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2">{{ $provider->notes }}</textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
            </div>
        </form>
    </div>
</div>
@endcan
@endforeach

@endif

{{-- New-provider modal --}}
@can('manage-network-settings')
<div class="modal fade" id="newProviderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.network.isp-providers.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">New Provider</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body row g-2">
                    <div class="col-12"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" placeholder="e.g. STC, Mobily, Zain" required></div>
                    <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create</button></div>
            </div>
        </form>
    </div>
</div>
@endcan

@endsection
