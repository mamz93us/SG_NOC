@extends('layouts.admin')
@section('title', 'Branch Store - ' . $branch->name)

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-box-seam me-2"></i>{{ $branch->name }} — Store</h4>
            <small class="text-muted">{{ $devices->total() }} asset(s) currently held in this branch's store</small>
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

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Asset Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Storage Location</th>
                        <th>Condition</th>
                        <th>Serial</th>
                        <th>Supplier</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($devices as $d)
                        <tr>
                            <td><code>{{ $d->asset_code }}</code></td>
                            <td>{{ $d->name }}</td>
                            <td><span class="badge bg-secondary">{{ $d->type }}</span></td>
                            <td><strong>{{ $d->storage_location ?? '—' }}</strong></td>
                            <td><span class="badge {{ $d->conditionBadgeClass() }}">{{ $d->conditionLabel() }}</span></td>
                            <td>{{ $d->serial_number ?? '—' }}</td>
                            <td>{{ $d->supplier?->name ?? '—' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.devices.show', $d->id) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center py-5 text-muted">No assets currently in this branch's store.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $devices->links() }}</div>
</div>
@endsection
