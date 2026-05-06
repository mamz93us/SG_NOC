@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-arrow-repeat me-2 text-primary"></i>Bulk Azure Contact Sync</h4>
        <small class="text-muted">
            Scan all employees, preview proposed Azure AD contact-info changes
            (office, city, street, business phone), and apply selectively.
            @if($lastSync)
                Last identity sync: <strong>{{ $lastSync->created_at->diffForHumans() }}</strong>.
            @endif
        </small>
    </div>
    <div class="d-flex gap-2">
        @can('manage-identity')
        <form method="POST" action="{{ route('admin.identity.sync') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-outline-primary btn-sm" title="Pull fresh data from Microsoft Graph before scanning">
                <i class="bi bi-cloud-download me-1"></i>Refresh from Graph
            </button>
        </form>
        @endcan
        <a href="{{ route('admin.identity.users') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

{{-- Flash messages --}}
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}
    @if(session('contact_sync_failures'))
    <ul class="mb-0 mt-2 small">
        @foreach(session('contact_sync_failures') as $f)
        <li>{{ $f }}</li>
        @endforeach
    </ul>
    @endif
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

{{-- Stat row --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="me-3 fs-2 text-warning"><i class="bi bi-pencil-square"></i></div>
                <div>
                    <div class="fs-4 fw-bold">{{ count($withDiffs) }}</div>
                    <small class="text-muted">With proposed changes</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="me-3 fs-2 text-success"><i class="bi bi-check2-circle"></i></div>
                <div>
                    <div class="fs-4 fw-bold">{{ count($noChanges) }}</div>
                    <small class="text-muted">Already in sync</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="me-3 fs-2 text-danger"><i class="bi bi-phone"></i></div>
                <div>
                    <div class="fs-4 fw-bold">{{ count($missingMobile) }}</div>
                    <small class="text-muted">Missing mobile in Azure</small>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Diff Card --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong><i class="bi bi-list-check me-1"></i>Proposed Changes</strong>
        @if(count($withDiffs) > 0)
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll(true)">Select All</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll(false)">Deselect All</button>
        </div>
        @endif
    </div>

    @if(count($withDiffs) === 0)
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-check2-all fs-1 d-block mb-2 text-success"></i>
        All employees with a branch are in sync with Azure AD.
    </div>
    @else
    @can('manage-identity')
    <form method="POST" action="{{ route('admin.identity.contact-sync.apply') }}" id="applyForm">
        @csrf
    @endcan
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        @can('manage-identity')
                        <th style="width:40px">
                            <input type="checkbox" class="form-check-input" id="checkAll" onchange="toggleAll(this)" checked>
                        </th>
                        @endcan
                        <th>Employee</th>
                        <th>Branch</th>
                        <th>Office Location</th>
                        <th>City</th>
                        <th>Street</th>
                        <th>Business Phone</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($withDiffs as $row)
                    @php
                        $emp = $row['employee'];
                        $diffByField = collect($row['diff'])->keyBy('field');
                    @endphp
                    <tr>
                        @can('manage-identity')
                        <td>
                            <input type="checkbox" class="form-check-input row-check"
                                   name="azure_ids[]" value="{{ $emp->azure_id }}"
                                   checked onchange="updateApplyBtn()">
                        </td>
                        @endcan
                        <td>
                            <div class="fw-semibold">{{ $emp->name }}</div>
                            <div class="text-muted" style="font-size:.75em">{{ $emp->email }}</div>
                            @if($emp->extension_number)
                            <span class="badge bg-secondary" style="font-size:.65em">EXT {{ $emp->extension_number }}</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-info text-dark">{{ $emp->branch?->name ?? '—' }}</span>
                        </td>
                        @foreach(['officeLocation', 'city', 'streetAddress', 'businessPhones'] as $field)
                            @php $cell = $diffByField[$field] ?? null; @endphp
                            <td>
                                @if($cell && $cell['changed'])
                                    <div class="text-muted text-decoration-line-through" style="font-size:.8em">
                                        {{ $cell['current'] ?? '∅' }}
                                    </div>
                                    <div class="text-warning fw-semibold">
                                        <i class="bi bi-arrow-down-short"></i>
                                        {{ $cell['proposed'] ?? '∅' }}
                                    </div>
                                @elseif($cell)
                                    <div class="text-muted">{{ $cell['current'] ?? '—' }}</div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @can('manage-identity')
        <div class="card-footer bg-light d-flex justify-content-between align-items-center">
            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Selected rows will be PATCHed to Azure AD via Microsoft Graph and mirrored locally.
            </small>
            <button type="submit" class="btn btn-primary" id="applyBtn"
                    onclick="return confirm('Apply selected Azure AD contact updates? This cannot be undone automatically.')">
                <i class="bi bi-cloud-upload me-1"></i>Apply Selected
            </button>
        </div>
    </form>
    @endcan
    @endif
</div>

{{-- Missing Mobile Card --}}
@if(count($missingMobile) > 0)
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-phone me-1 text-danger"></i>Missing Mobile in Azure AD</strong>
        <div class="text-muted small mt-1">
            These employees have no <code>mobilePhone</code> set in Azure AD. Click below to email them a request to update it.
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Employee</th>
                    <th>Branch</th>
                    <th>Email</th>
                </tr>
            </thead>
            <tbody>
                @foreach($missingMobile as $emp)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $emp->name }}</div>
                        @if($emp->extension_number)
                        <span class="badge bg-secondary" style="font-size:.65em">EXT {{ $emp->extension_number }}</span>
                        @endif
                    </td>
                    <td><span class="badge bg-info text-dark">{{ $emp->branch?->name ?? '—' }}</span></td>
                    <td><a href="mailto:{{ $emp->email }}">{{ $emp->email }}</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @can('manage-identity')
    <div class="card-footer bg-light d-flex justify-content-end">
        <form method="POST" action="{{ route('admin.identity.contact-sync.send-reminders') }}"
              onsubmit="return confirm('Send a reminder email to {{ count($missingMobile) }} employee(s)?');">
            @csrf
            <button type="submit" class="btn btn-outline-danger">
                <i class="bi bi-envelope me-1"></i>Send Reminder Emails ({{ count($missingMobile) }})
            </button>
        </form>
    </div>
    @endcan
</div>
@endif

@push('scripts')
<script>
function selectAll(state) {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = state);
    updateApplyBtn();
}
function toggleAll(masterCb) {
    selectAll(masterCb.checked);
}
function updateApplyBtn() {
    const total   = document.querySelectorAll('.row-check').length;
    const checked = document.querySelectorAll('.row-check:checked').length;
    const btn     = document.getElementById('applyBtn');
    if (btn) btn.disabled = checked === 0;
    const master  = document.getElementById('checkAll');
    if (master) {
        master.checked       = checked > 0 && checked === total;
        master.indeterminate = checked > 0 && checked < total;
    }
}
document.addEventListener('DOMContentLoaded', updateApplyBtn);
</script>
@endpush

@endsection
