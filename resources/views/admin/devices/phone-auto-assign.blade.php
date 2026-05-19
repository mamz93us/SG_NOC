@extends('layouts.admin')
@section('content')

@php
    $results       = $results ?? [];
    $total         = count($results);
    $byStatus      = collect($results)->groupBy('status');
    $cntReady      = $byStatus->get('ready',         collect())->count();
    $cntNoAsset    = $byStatus->get('no_asset',      collect())->count();
    $cntNoAccount  = $byStatus->get('no_account',    collect())->count();
    $cntNoEmployee = $byStatus->get('no_employee',   collect())->count();
    $cntWrong      = $byStatus->get('wrong_employee',collect())->count();
    $cntAssigned   = $byStatus->get('assigned',      collect())->count();
    $cntNeedAction = $cntReady + $cntNoAsset + $cntWrong;
@endphp

{{-- ── Header ─────────────────────────────────────────────────────────── --}}
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-telephone-plus me-2 text-success"></i>Phone Auto-Assign</h4>
        <small class="text-muted">Source: GDMS device list + phone_accounts SIP data</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

{{-- ── Alerts ──────────────────────────────────────────────────────────── --}}
@if($gdmsError ?? false)
<div class="alert alert-warning d-flex align-items-start gap-2 py-2 mb-3">
    <i class="bi bi-cloud-slash-fill mt-1 flex-shrink-0"></i>
    <div><strong>GDMS Unreachable</strong> — {{ $gdmsError }}<br>
    <small class="text-muted">Showing data from local database only.</small></div>
</div>
@endif
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2 mb-3">
    {{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2 mb-3">
    {{ session('error') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- ── Stats Cards ─────────────────────────────────────────────────────── --}}
<div class="row g-2 mb-3">
    <div class="col">
        <div class="card text-center py-2 h-100">
            <div class="fw-bold fs-4">{{ $total }}</div>
            <small class="text-muted">Total Phones</small>
        </div>
    </div>
    <div class="col">
        <div class="card text-center py-2 h-100 border-success">
            <div class="fw-bold fs-4 text-success">{{ $cntReady }}</div>
            <small class="text-muted">Ready to Assign</small>
        </div>
    </div>
    <div class="col">
        <div class="card text-center py-2 h-100 border-warning">
            <div class="fw-bold fs-4 text-warning">{{ $cntNoAsset }}</div>
            <small class="text-muted">No Asset Record</small>
        </div>
    </div>
    <div class="col">
        <div class="card text-center py-2 h-100">
            <div class="fw-bold fs-4 text-secondary">{{ $cntNoAccount }}</div>
            <small class="text-muted">No SIP Data</small>
        </div>
    </div>
    <div class="col">
        <div class="card text-center py-2 h-100">
            <div class="fw-bold fs-4 text-secondary">{{ $cntNoEmployee }}</div>
            <small class="text-muted">No Employee</small>
        </div>
    </div>
    <div class="col">
        <div class="card text-center py-2 h-100 border-danger">
            <div class="fw-bold fs-4 text-danger">{{ $cntWrong }}</div>
            <small class="text-muted">Wrong Person</small>
        </div>
    </div>
    <div class="col">
        <div class="card text-center py-2 h-100 border-primary">
            <div class="fw-bold fs-4 text-primary">{{ $cntAssigned }}</div>
            <small class="text-muted">Correctly Assigned</small>
        </div>
    </div>
</div>

{{-- ── Action Buttons ───────────────────────────────────────────────────── --}}
<div class="d-flex gap-2 mb-3 flex-wrap">
    @if($cntNoAsset > 0)
    <form method="POST" action="{{ route('admin.devices.phone-auto-assign.create-assets') }}">
        @csrf
        <button type="submit" class="btn btn-warning btn-sm"
                onclick="return confirm('Create {{ $cntNoAsset }} missing phone asset record(s) from GDMS?')">
            <i class="bi bi-plus-circle me-1"></i>Create {{ $cntNoAsset }} Missing Asset(s)
        </button>
    </form>
    @endif
    <button class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
        <i class="bi bi-arrow-clockwise me-1"></i>Refresh from GDMS
    </button>
</div>

{{-- ── Filter Tabs ─────────────────────────────────────────────────────── --}}
@if($total > 0)
<ul class="nav nav-tabs mb-0" id="phoneTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-filter="all">
            All <span class="badge bg-secondary ms-1">{{ $total }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-filter="needs-action">
            Needs Action <span class="badge bg-danger ms-1">{{ $cntNeedAction }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-filter="no_account,no_employee">
            Incomplete Data <span class="badge bg-secondary ms-1">{{ $cntNoAccount + $cntNoEmployee }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-filter="assigned">
            Assigned <span class="badge bg-primary ms-1">{{ $cntAssigned }}</span>
        </button>
    </li>
</ul>

{{-- ── Main Table ───────────────────────────────────────────────────────── --}}
<form method="POST" action="{{ route('admin.devices.phone-auto-assign.store') }}" id="autoAssignForm">
    @csrf
<div class="card shadow-sm border-top-0 rounded-0 rounded-bottom">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small" id="phoneTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width:36px">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th>MAC / Model</th>
                        <th>Online</th>
                        <th>SIP Ext.</th>
                        <th>Contact</th>
                        <th>Employee</th>
                        <th>Asset in DB</th>
                        <th>Currently Assigned To</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($results as $r)
                @php
                    $st = $r['status'];
                    $rowClass = match($st) {
                        'ready'          => 'table-success',
                        'no_asset'       => 'table-warning',
                        'wrong_employee' => 'table-danger',
                        'assigned'       => '',
                        default          => 'table-light',
                    };
                    $macFormatted = strtoupper(implode(':', str_split($r['mac'], 2)));
                @endphp
                <tr class="{{ $rowClass }}" data-status="{{ $st }}">
                    {{-- Checkbox --}}
                    <td class="ps-3">
                        @if($st === 'ready' && $r['device'] && $r['employee'])
                        <input type="checkbox" class="form-check-input assign-cb"
                               name="assignments[]"
                               value="{{ $r['employee']->id }}:{{ $r['device']->id }}">
                        @endif
                    </td>

                    {{-- MAC / Model --}}
                    <td>
                        <div class="font-monospace" style="font-size:.78rem">{{ $macFormatted }}</div>
                        @if($r['model'])
                        <small class="text-muted d-block">{{ $r['model'] }}</small>
                        @endif
                        @if($r['ip'])
                        <small class="text-muted">
                            <a href="https://{{ $r['ip'] }}" target="_blank" class="text-decoration-none text-muted">
                                {{ $r['ip'] }} <i class="bi bi-box-arrow-up-right" style="font-size:.65rem"></i>
                            </a>
                        </small>
                        @endif
                        @if($r['serial'] ?? false)
                        <small class="text-muted d-block font-monospace" style="font-size:.7rem">S/N: {{ $r['serial'] }}</small>
                        @endif
                        @if($r['firmware'] ?? false)
                        <small class="text-muted d-block" style="font-size:.7rem">FW: {{ $r['firmware'] }}</small>
                        @endif
                    </td>

                    {{-- Online Status --}}
                    <td>
                        @if($r['online'] === true)
                            <span class="badge bg-success bg-opacity-75"><i class="bi bi-circle-fill me-1" style="font-size:.55rem"></i>Online</span>
                        @elseif($r['online'] === false)
                            <span class="badge bg-secondary"><i class="bi bi-circle me-1" style="font-size:.55rem"></i>Offline</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    {{-- SIP Extension --}}
                    <td>
                        @if($r['sipUserId'])
                        <span class="badge bg-primary bg-opacity-75 font-monospace">{{ $r['sipUserId'] }}</span>
                        @if($r['accounts']->count() > 1)
                        <span class="badge bg-light text-muted ms-1" title="{{ $r['accounts']->pluck('sip_user_id')->filter()->implode(', ') }}">
                            +{{ $r['accounts']->count() - 1 }} more
                        </span>
                        @endif
                        @else
                        <span class="badge bg-light text-danger">No SIP data</span>
                        @endif
                    </td>

                    {{-- Contact --}}
                    <td>
                        @if($r['contact'])
                        <span class="fw-semibold">{{ $r['contact']->first_name }} {{ $r['contact']->last_name }}</span>
                        @if($r['contact']->branch?->name)
                        <small class="text-muted d-block">{{ $r['contact']->branch->name }}</small>
                        @endif
                        @elseif($r['sipUserId'])
                        <span class="text-muted fst-italic small">No contact matched</span>
                        @else
                        <span class="text-muted">—</span>
                        @endif
                    </td>

                    {{-- Employee --}}
                    <td>
                        @if($r['employee'])
                        <a href="{{ route('admin.employees.show', $r['employee']) }}" class="text-decoration-none fw-semibold">
                            {{ $r['employee']->name }}
                        </a>
                        <small class="text-muted d-block">{{ $r['employee']->branch?->name }}</small>
                        @elseif($r['contact'])
                        <span class="text-muted fst-italic small">Contact not linked to employee</span>
                        @else
                        <span class="text-muted">—</span>
                        @endif
                    </td>

                    {{-- Asset in DB --}}
                    <td>
                        @if($r['device'])
                        <a href="{{ route('admin.devices.show', $r['device']) }}" class="text-decoration-none fw-semibold">
                            {{ $r['device']->name }}
                        </a>
                        <small class="text-muted d-block">{{ $r['device']->asset_code }}</small>
                        @else
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-exclamation-triangle me-1"></i>Not in DB
                        </span>
                        @endif
                    </td>

                    {{-- Currently Assigned To --}}
                    <td>
                        @if($r['assignedEmployee'])
                        <a href="{{ route('admin.employees.show', $r['assignedEmployee']) }}" class="text-decoration-none">
                            {{ $r['assignedEmployee']->name }}
                        </a>
                        @else
                        <span class="text-muted">—</span>
                        @endif
                    </td>

                    {{-- Status Badge --}}
                    <td>
                        @switch($st)
                            @case('assigned')
                                <span class="badge bg-primary"><i class="bi bi-check-circle me-1"></i>Assigned</span>
                                @break
                            @case('ready')
                                <span class="badge bg-success"><i class="bi bi-lightning me-1"></i>Ready</span>
                                @break
                            @case('no_asset')
                                <span class="badge bg-warning text-dark"><i class="bi bi-hdd-x me-1"></i>No Asset</span>
                                @break
                            @case('no_account')
                                <span class="badge bg-secondary"><i class="bi bi-telephone-x me-1"></i>No SIP Data</span>
                                @break
                            @case('no_employee')
                                <span class="badge bg-secondary"><i class="bi bi-person-x me-1"></i>No Employee</span>
                                @break
                            @case('wrong_employee')
                                <span class="badge bg-danger"><i class="bi bi-person-exclamation me-1"></i>Wrong Person</span>
                                @break
                            @default
                                <span class="badge bg-secondary">{{ $st }}</span>
                        @endswitch
                    </td>

                    {{-- Action: Manual Assign --}}
                    <td class="text-end pe-3">
                        @if($r['device'])
                            @php
                                $btnLabel = $st === 'assigned' || $st === 'wrong_employee' ? 'Reassign' : 'Manual Assign';
                                $btnClass = $st === 'wrong_employee' ? 'btn-outline-danger' : 'btn-outline-primary';
                            @endphp
                            <button type="button" class="btn btn-sm {{ $btnClass }} manual-assign-btn"
                                    data-device-id="{{ $r['device']->id }}"
                                    data-device-name="{{ $r['device']->name }}"
                                    data-asset-code="{{ $r['device']->asset_code }}"
                                    data-current-employee="{{ $r['assignedEmployee']?->name ?? '' }}"
                                    data-suggested-employee="{{ $r['employee']?->id ?? '' }}">
                                <i class="bi bi-person-plus me-1"></i>{{ $btnLabel }}
                            </button>
                        @else
                            <span class="text-muted small">Create asset first</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="10" class="text-center py-5 text-muted">
                        <i class="bi bi-telephone-x display-4 d-block mb-2"></i>
                        No phones found. GDMS may be unreachable or no devices are registered.
                    </td>
                </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ── Assign Toolbar ───────────────────────────────────────────────────── --}}
@if($cntReady > 0)
<div class="card mt-2 border-success">
    <div class="card-body py-2 d-flex justify-content-between align-items-center">
        <span class="text-muted small">
            <strong id="selectedCount">0</strong> of {{ $cntReady }} ready device(s) selected
        </span>
        <button type="submit" class="btn btn-success btn-sm" id="assignBtn" disabled>
            <i class="bi bi-check-lg me-1"></i>Assign Selected
        </button>
    </div>
</div>
@endif

</form>
@else
<div class="card shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-telephone-x display-4 d-block mb-2"></i>
        No phone data available. Check GDMS connectivity or run
        <code>php artisan gdms:sync-device-accounts</code> to populate SIP data.
        @php($manualPhones = \App\Models\Device::where('type', 'phone')->with('currentAssignment.employee')->orderBy('name')->get())
        @if($manualPhones->count() > 0)
        <div class="mt-4 text-start">
            <h6 class="text-muted small text-uppercase fw-semibold mb-2"><i class="bi bi-keyboard me-1"></i>Manual Assignment</h6>
            <p class="small text-muted mb-2">GDMS data isn't available, but you have {{ $manualPhones->count() }} phone(s) in the local database. You can assign them manually.</p>
            <div class="table-responsive border rounded">
                <table class="table table-sm table-hover mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Asset Code</th>
                            <th>Phone</th>
                            <th>Currently Assigned To</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($manualPhones as $ph)
                        <tr>
                            <td class="font-monospace">{{ $ph->asset_code ?? '—' }}</td>
                            <td>{{ $ph->name }}</td>
                            <td>{{ $ph->currentAssignment?->employee?->name ?? '—' }}</td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm btn-outline-primary manual-assign-btn"
                                        data-device-id="{{ $ph->id }}"
                                        data-device-name="{{ $ph->name }}"
                                        data-asset-code="{{ $ph->asset_code }}"
                                        data-current-employee="{{ $ph->currentAssignment?->employee?->name ?? '' }}"
                                        data-suggested-employee="">
                                    <i class="bi bi-person-plus me-1"></i>{{ $ph->currentAssignment ? 'Reassign' : 'Manual Assign' }}
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</div>
@endif

{{-- ── Manual Assign Modal ─────────────────────────────────────────────────── --}}
<div class="modal fade" id="manualAssignModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.devices.phone-auto-assign.manual-assign') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-semibold"><i class="bi bi-person-plus me-2 text-primary"></i>Manual Phone Assignment</h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="device_id" id="maDeviceId">
                    <div class="mb-3">
                        <label class="form-label small text-muted">Phone</label>
                        <div class="fw-semibold" id="maDeviceName">—</div>
                        <code class="small text-muted" id="maAssetCode"></code>
                    </div>
                    <div class="mb-3" id="maCurrentWrap" style="display:none">
                        <label class="form-label small text-muted">Currently Assigned To</label>
                        <div id="maCurrentEmployee" class="fw-semibold text-danger"></div>
                        <small class="text-muted">Confirming will close the existing assignment and reassign.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Assign To Employee <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm mb-1" id="maSearch"
                               placeholder="Filter employees by name, email, or extension..." autocomplete="off">
                        <select name="employee_id" id="maEmployeeSelect" class="form-select" required size="6" style="height:auto">
                            @foreach($allEmployees ?? [] as $emp)
                            <option value="{{ $emp->id }}"
                                    data-search="{{ strtolower($emp->name . ' ' . ($emp->email ?? '') . ' ' . ($emp->extension_number ?? '')) }}">
                                {{ $emp->name }}@if($emp->extension_number) — ext {{ $emp->extension_number }}@endif
                            </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes <span class="text-muted small">(optional)</span></label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Reason for manual assignment..."></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Assign</button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
// ── Filter tabs ─────────────────────────────────────────────
document.querySelectorAll('#phoneTabs button[data-filter]').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('#phoneTabs button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');

        const filter = this.dataset.filter;
        const statuses = filter === 'all' ? null : filter.split(',');

        document.querySelectorAll('#phoneTable tbody tr[data-status]').forEach(row => {
            if (!statuses || statuses.includes(row.dataset.status)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
                // Uncheck hidden checkboxes
                const cb = row.querySelector('.assign-cb');
                if (cb) cb.checked = false;
            }
        });
        updateCount();
    });
});

// ── Checkbox logic ─────────────────────────────────────────
const selectAll = document.getElementById('selectAll');
const countEl   = document.getElementById('selectedCount');
const assignBtn = document.getElementById('assignBtn');

function updateCount() {
    const checked = document.querySelectorAll('.assign-cb:checked').length;
    const total   = document.querySelectorAll('.assign-cb').length;
    if (countEl)   countEl.textContent = checked;
    if (assignBtn) assignBtn.disabled  = checked === 0;
    if (selectAll) selectAll.indeterminate = checked > 0 && checked < total;
    if (selectAll) selectAll.checked = checked === total && total > 0;
}

if (selectAll) {
    selectAll.addEventListener('change', function () {
        // Only check visible rows
        document.querySelectorAll('#phoneTable tbody tr:not([style*="none"]) .assign-cb')
            .forEach(cb => cb.checked = this.checked);
        updateCount();
    });
}

document.querySelectorAll('.assign-cb').forEach(cb => {
    cb.addEventListener('change', updateCount);
});

// ── Manual assign modal ────────────────────────────────────
(function () {
    const modalEl = document.getElementById('manualAssignModal');
    if (!modalEl) return;
    const modal = new bootstrap.Modal(modalEl);
    const deviceIdInput = document.getElementById('maDeviceId');
    const nameEl = document.getElementById('maDeviceName');
    const codeEl = document.getElementById('maAssetCode');
    const currentWrap = document.getElementById('maCurrentWrap');
    const currentEl = document.getElementById('maCurrentEmployee');
    const searchEl = document.getElementById('maSearch');
    const selectEl = document.getElementById('maEmployeeSelect');

    document.querySelectorAll('.manual-assign-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            deviceIdInput.value = this.dataset.deviceId;
            nameEl.textContent = this.dataset.deviceName || '—';
            codeEl.textContent = this.dataset.assetCode || '';

            const current = this.dataset.currentEmployee;
            if (current) {
                currentWrap.style.display = '';
                currentEl.textContent = current;
            } else {
                currentWrap.style.display = 'none';
            }

            // Pre-select the suggested employee if one came back from auto-match
            const suggested = this.dataset.suggestedEmployee;
            if (suggested) selectEl.value = suggested;
            else selectEl.selectedIndex = -1;

            // Reset search filter so all options are visible again
            searchEl.value = '';
            Array.from(selectEl.options).forEach(o => { o.hidden = false; });

            modal.show();
            setTimeout(() => searchEl.focus(), 250);
        });
    });

    if (searchEl) {
        searchEl.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            let firstVisible = null;
            Array.from(selectEl.options).forEach(o => {
                const hay = o.dataset.search || o.text.toLowerCase();
                const match = !q || hay.includes(q);
                o.hidden = !match;
                if (match && !firstVisible) firstVisible = o;
            });
            // Keep selection valid if hidden by filter
            if (selectEl.selectedOptions[0]?.hidden && firstVisible) {
                selectEl.value = firstVisible.value;
            }
        });
    }
})();
</script>
@endpush

@endsection
