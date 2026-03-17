@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-telephone-plus me-2 text-success"></i>Phone Auto-Assign</h4>
        <small class="text-muted">Match employees to phone devices via their extension number</small>
    </div>
    <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Devices
    </a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    {{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
    {{ session('error') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- Summary Stats --}}
@php
    $total       = count($results);
    $available   = collect($results)->where('status', 'available')->count();
    $assigned    = collect($results)->where('status', 'already_assigned')->count();
    $elsewhere   = collect($results)->where('status', 'assigned_elsewhere')->count();
    $notFound    = collect($results)->where('status', 'not_found')->count();
@endphp
<div class="row g-2 mb-3">
    <div class="col"><div class="card text-center py-2"><div class="fw-bold fs-5">{{ $total }}</div><small class="text-muted">Total Scanned</small></div></div>
    <div class="col"><div class="card text-center py-2 border-success"><div class="fw-bold fs-5 text-success">{{ $available }}</div><small class="text-muted">Ready to Assign</small></div></div>
    <div class="col"><div class="card text-center py-2"><div class="fw-bold fs-5 text-primary">{{ $assigned }}</div><small class="text-muted">Already Assigned</small></div></div>
    <div class="col"><div class="card text-center py-2"><div class="fw-bold fs-5 text-warning">{{ $elsewhere }}</div><small class="text-muted">Assigned Elsewhere</small></div></div>
    <div class="col"><div class="card text-center py-2"><div class="fw-bold fs-5 text-secondary">{{ $notFound }}</div><small class="text-muted">No Device Found</small></div></div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($total === 0)
        <div class="text-center py-5 text-muted">
            <i class="bi bi-telephone-x display-4 d-block mb-2"></i>No employees with extension numbers found.
        </div>
        @else
        <form method="POST" action="{{ route('admin.devices.phone-auto-assign.store') }}" id="autoAssignForm">
            @csrf
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:40px">
                                <input type="checkbox" class="form-check-input" id="selectAll"
                                       {{ $available === 0 ? 'disabled' : '' }}>
                            </th>
                            <th>Employee</th>
                            <th>Extension</th>
                            <th>Device</th>
                            <th>MAC Address</th>
                            <th>IP</th>
                            <th>Model</th>
                            <th>Source</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($results as $r)
                        @php
                            $rowClass = match($r['status']) {
                                'available'         => 'table-success',
                                'already_assigned'  => '',
                                'assigned_elsewhere'=> 'table-warning',
                                'not_found'         => 'table-light text-muted',
                                default             => '',
                            };
                        @endphp
                        <tr class="{{ $rowClass }}">
                            <td class="ps-3">
                                @if($r['status'] === 'available')
                                <input type="checkbox" class="form-check-input assign-cb"
                                       name="assignments[]"
                                       value="{{ $r['employee']->id }}:{{ $r['device']->id }}">
                                @endif
                            </td>
                            <td class="fw-semibold">
                                <a href="{{ route('admin.employees.show', $r['employee']) }}" class="text-decoration-none">
                                    {{ $r['employee']->name }}
                                </a>
                            </td>
                            <td>
                                <span class="badge bg-primary bg-opacity-75">{{ $r['employee']->extension_number }}</span>
                            </td>
                            <td>
                                @if($r['device'])
                                <a href="{{ route('admin.devices.show', $r['device']) }}" class="text-decoration-none">
                                    {{ $r['device']->name }}
                                </a>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="font-monospace small">
                                @if($r['mac'])
                                {{ strtoupper(implode(':', str_split($r['mac'], 2))) }}
                                @else
                                —
                                @endif
                            </td>
                            <td>
                                @if($r['ip'])
                                <a href="https://{{ $r['ip'] }}" target="_blank" class="text-decoration-none" title="Open phone settings">
                                    {{ $r['ip'] }} <i class="bi bi-box-arrow-up-right" style="font-size:.7rem"></i>
                                </a>
                                @else
                                —
                                @endif
                            </td>
                            <td>{{ $r['model'] ?: '—' }}</td>
                            <td><span class="badge bg-secondary bg-opacity-50">{{ $r['source'] ?: '—' }}</span></td>
                            <td>
                                @switch($r['status'])
                                    @case('available')
                                        <span class="badge bg-success">Available</span>
                                        @break
                                    @case('already_assigned')
                                        <span class="badge bg-primary">Already Assigned</span>
                                        @break
                                    @case('assigned_elsewhere')
                                        <span class="badge bg-warning text-dark">Assigned to Someone Else</span>
                                        @break
                                    @default
                                        <span class="badge bg-secondary">No Device Found</span>
                                @endswitch
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($available > 0)
            <div class="p-3 bg-light border-top d-flex justify-content-between align-items-center">
                <span class="text-muted small"><strong id="selectedCount">0</strong> device(s) selected</span>
                <button type="submit" class="btn btn-success btn-sm" id="assignBtn" disabled>
                    <i class="bi bi-check-lg me-1"></i>Assign Selected
                </button>
            </div>
            @endif
        </form>
        @endif
    </div>
</div>

@push('scripts')
<script>
const selectAll = document.getElementById('selectAll');
const checkboxes = document.querySelectorAll('.assign-cb');
const countEl = document.getElementById('selectedCount');
const btn = document.getElementById('assignBtn');

function updateCount() {
    const checked = document.querySelectorAll('.assign-cb:checked').length;
    if (countEl) countEl.textContent = checked;
    if (btn) btn.disabled = checked === 0;
    if (selectAll) selectAll.checked = checked === checkboxes.length && checkboxes.length > 0;
}

if (selectAll) {
    selectAll.addEventListener('change', function () {
        checkboxes.forEach(cb => cb.checked = this.checked);
        updateCount();
    });
}

checkboxes.forEach(cb => cb.addEventListener('change', updateCount));
</script>
@endpush

@endsection
