@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2 text-primary"></i>Incidents</h4>
        <small class="text-muted">Track and manage infrastructure incidents</small>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-danger-subtle text-danger border px-3 py-2 fs-6">{{ $openCount }} Open</span>
        @can('manage-incidents')
        <a href="{{ route('admin.noc.incidents.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Incident
        </a>
        @endcan
    </div>
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search..." value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Status</option>
            @foreach(\App\Models\Incident::statuses() as $k => $v)
            <option value="{{ $k }}" {{ request('status') == $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="severity" class="form-select form-select-sm">
            <option value="">All Severity</option>
            @foreach(\App\Models\Incident::severities() as $k => $v)
            <option value="{{ $k }}" {{ request('severity') == $k ? 'selected' : '' }}>{{ $v }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="branch" class="form-select form-select-sm">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b->id }}" {{ request('branch') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.noc.incidents.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($incidents->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-journal-text display-4 d-block mb-2"></i>No incidents found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th>Title</th>
                        <th>Branch</th>
                        <th>Assigned To</th>
                        <th>Created</th>
                        <th>Duration</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($incidents as $inc)
                    <tr class="{{ $inc->isOpen() ? '' : 'opacity-75' }}">
                        <td class="fw-bold text-muted">#{{ $inc->id }}</td>
                        <td><span class="badge {{ $inc->severityBadgeClass() }}">{{ ucfirst($inc->severity) }}</span></td>
                        <td><span class="badge {{ $inc->statusBadgeClass() }}">{{ ucfirst($inc->status) }}</span></td>
                        <td>
                            <a href="{{ route('admin.noc.incidents.show', $inc) }}" class="text-decoration-none fw-semibold">{{ $inc->title }}</a>
                            @if($inc->noc_event_id)
                            <span class="badge bg-dark bg-opacity-10 text-dark border ms-1" style="font-size:9px">from alert</span>
                            @endif
                        </td>
                        <td>{{ $inc->branch?->name ?: '—' }}</td>
                        <td>{{ $inc->assignedTo?->name ?: '—' }}</td>
                        <td class="text-muted">{{ $inc->created_at->diffForHumans() }}</td>
                        <td class="text-muted">{{ $inc->durationHuman() }}</td>
                        <td>
                            <a href="{{ route('admin.noc.incidents.show', $inc) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $incidents->links() }}</div>
        @endif
    </div>
</div>

@endsection
