@extends('layouts.admin')
@section('title', 'Universal Store')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-globe me-2 text-primary"></i>Universal Store</h4>
            <small class="text-muted">Unassigned assets not bound to any branch + unlinked Intune devices.</small>
        </div>
        <div class="d-flex gap-2">
            @can('manage-itam')
                <a href="{{ route('admin.itam.transfer.index') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-arrow-left-right me-1"></i>Transfer
                </a>
            @endcan
            <a href="{{ route('admin.itam.stores.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>All Stores
            </a>
        </div>
    </div>

    {{-- Unlinked Intune section --}}
    @if($unlinkedIntuneCount > 0)
        <div class="card border-0 shadow-sm mb-4 border-start border-4 border-warning">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <div>
                    <strong><i class="bi bi-microsoft me-2 text-warning"></i>Unlinked Intune Devices</strong>
                    <span class="badge bg-warning text-dark ms-2">{{ $unlinkedIntuneCount }}</span>
                </div>
                @can('manage-itam')
                    <a href="{{ route('admin.itam.azure.index') }}" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-link-45deg me-1"></i>Manage / Import
                    </a>
                @endcan
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Display Name</th>
                            <th>OS</th>
                            <th>Serial</th>
                            <th>UPN</th>
                            <th>Last Sync</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($unlinkedIntune as $a)
                            <tr>
                                <td>{{ $a->display_name }}</td>
                                <td>{{ $a->os }} {{ $a->os_version }}</td>
                                <td><code>{{ $a->serial_number ?? '—' }}</code></td>
                                <td>{{ $a->upn ?? '—' }}</td>
                                <td>{{ $a->last_sync_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if($unlinkedIntuneCount > $unlinkedIntune->count())
                    <div class="text-center py-2 text-muted small">
                        Showing {{ $unlinkedIntune->count() }} of {{ $unlinkedIntuneCount }} —
                        <a href="{{ route('admin.itam.azure.index') }}">view all</a>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-4">
                    <input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search asset code, name, serial, location...">
                </div>
                <div class="col-md-3">
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        @foreach($types as $t)
                            <option value="{{ $t }}" @selected(request('type') === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="condition" class="form-select form-select-sm">
                        <option value="">Any condition</option>
                        <option value="new" @selected(request('condition') === 'new')>New</option>
                        <option value="used" @selected(request('condition') === 'used')>Used</option>
                        <option value="refurbished" @selected(request('condition') === 'refurbished')>Refurbished</option>
                        <option value="damaged" @selected(request('condition') === 'damaged')>Damaged</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Devices in Universal Store --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><strong>Assets in Universal Store</strong></div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Asset Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Storage Location</th>
                        <th>Status</th>
                        <th>Condition</th>
                        <th>Serial</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($devices as $d)
                        <tr>
                            <td><code>{{ $d->asset_code }}</code></td>
                            <td>{{ $d->name }}</td>
                            <td><span class="badge bg-secondary">{{ $d->type }}</span></td>
                            <td>{{ $d->storage_location ?? '—' }}</td>
                            <td><span class="badge {{ $d->statusBadgeClass() }}">{{ $d->status }}</span></td>
                            <td><span class="badge {{ $d->conditionBadgeClass() }}">{{ $d->conditionLabel() }}</span></td>
                            <td>{{ $d->serial_number ?? '—' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.devices.show', $d->id) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center py-5 text-muted">No assets currently in the universal store.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $devices->links() }}</div>
</div>
@endsection
