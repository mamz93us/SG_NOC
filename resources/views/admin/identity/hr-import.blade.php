@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-spreadsheet me-2 text-primary"></i>Oracle HR Import</h4>
        <small class="text-muted">
            Import the Oracle HRMS employee export, link rows to NOC employees, then
            push job title, department &amp; mobile to Entra via Contact Sync.
        </small>
    </div>
    <div class="d-flex gap-2">
        @if($batch)
        <a href="{{ route('admin.identity.hr-import') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-upload me-1"></i>New Import
        </a>
        @endif
        <a href="{{ route('admin.identity.contact-sync') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrow-repeat me-1"></i>Go to Contact Sync
        </a>
    </div>
</div>

{{-- Flash --}}
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif
@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

@if(! $batch)
{{-- ───────────────────────── Upload + batch list ───────────────────────── --}}
@can('manage-identity')
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent"><strong><i class="bi bi-upload me-1"></i>Upload Oracle Export</strong></div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.identity.hr-import.upload') }}" enctype="multipart/form-data"
              class="d-flex flex-wrap align-items-end gap-3">
            @csrf
            <div>
                <label class="form-label small fw-semibold">Spreadsheet (.xlsx / .xls / .csv)</label>
                <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required style="max-width:420px">
                <div class="form-text">Expected columns: Location Name, Dept No, Dept Name, Emp No, Emp Name, Email Address, Mobile No, Job Name.</div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Parse &amp; Preview</button>
        </form>
    </div>
</div>
@endcan

<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent"><strong><i class="bi bi-clock-history me-1"></i>Recent Imports</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>File</th><th>Uploaded</th><th>By</th><th>Rows</th>
                    <th>Matched</th><th>Unmatched</th><th>Errors</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($batches as $b)
                <tr>
                    <td class="fw-semibold">{{ $b->filename }}</td>
                    <td>{{ $b->created_at->diffForHumans() }}</td>
                    <td>{{ $b->uploader?->name ?? '—' }}</td>
                    <td>{{ $b->total_rows }}</td>
                    <td><span class="badge bg-success">{{ $b->matched_count }}</span></td>
                    <td><span class="badge bg-warning text-dark">{{ $b->unmatched_count }}</span></td>
                    <td><span class="badge bg-danger">{{ $b->error_count }}</span></td>
                    <td><span class="badge bg-secondary">{{ $b->status }}</span></td>
                    <td><a href="{{ route('admin.identity.hr-import.show', $b) }}" class="btn btn-sm btn-outline-primary">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No imports yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@else
{{-- ───────────────────────── Batch preview ───────────────────────── --}}
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
        <div class="me-3 fs-2 text-primary"><i class="bi bi-list-ol"></i></div>
        <div><div class="fs-4 fw-bold">{{ $batch->total_rows }}</div><small class="text-muted">Total rows</small></div>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
        <div class="me-3 fs-2 text-success"><i class="bi bi-link-45deg"></i></div>
        <div><div class="fs-4 fw-bold">{{ $batch->matched_count }}</div><small class="text-muted">Matched</small></div>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
        <div class="me-3 fs-2 text-warning"><i class="bi bi-question-circle"></i></div>
        <div><div class="fs-4 fw-bold">{{ $batch->unmatched_count }}</div><small class="text-muted">Unmatched</small></div>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body d-flex align-items-center">
        <div class="me-3 fs-2 text-danger"><i class="bi bi-exclamation-octagon"></i></div>
        <div><div class="fs-4 fw-bold">{{ $batch->error_count }}</div><small class="text-muted">Errors</small></div>
    </div></div></div>
</div>

{{-- Matched --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-link-45deg me-1"></i>Matched Employees ({{ $matched->count() }})</strong>
        @can('manage-identity')
        @if($matched->where('status', 'matched')->count() > 0)
        <form method="POST" action="{{ route('admin.identity.hr-import.apply', $batch) }}"
              onsubmit="return confirm('Apply Oracle data to {{ $matched->where('status','matched')->count() }} matched employee(s)? This writes to the NOC employees table.');">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-database-check me-1"></i>Apply All Matched</button>
        </form>
        @endif
        @endcan
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Employee</th><th>Match</th><th>Emp No</th><th>Job Title</th>
                    <th>Department</th><th>Branch</th><th>Mobile</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
            @forelse($matched as $row)
                @php $emp = $row->matchedEmployee ?? $row->linkedEmployee; @endphp
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $row->emp_name }}</div>
                        <div class="text-muted" style="font-size:.75em">{{ $row->email }}</div>
                    </td>
                    <td><span class="badge bg-light text-dark border">{{ $row->match_method }}</span></td>
                    <td>{{ $row->emp_no ?? '—' }}</td>
                    <td>
                        @if($emp && $emp->job_title !== $row->job_name)
                            <div class="text-muted text-decoration-line-through" style="font-size:.8em">{{ $emp->job_title ?? '∅' }}</div>
                            <div class="text-warning fw-semibold">{{ $row->job_name ?? '∅' }}</div>
                        @else
                            {{ $row->job_name ?? '—' }}
                        @endif
                    </td>
                    <td>{{ $row->dept_name ?? '—' }}</td>
                    <td>
                        @if($row->resolvedBranch)
                            <span class="badge bg-info text-dark">{{ $row->resolvedBranch->name }}</span>
                        @else
                            <span class="text-danger" title="{{ $row->location_name }}">no match</span>
                        @endif
                    </td>
                    <td>
                        {{ $row->mobile_normalized ?? '—' }}
                        @if($row->mobile_raw && ! $row->mobile_normalized)
                            <i class="bi bi-exclamation-triangle text-warning" title="Raw: {{ $row->mobile_raw }}"></i>
                        @endif
                    </td>
                    <td>
                        @php $sc = ['applied'=>'success','linked'=>'success','created'=>'success','matched'=>'secondary'][$row->status] ?? 'secondary'; @endphp
                        <span class="badge bg-{{ $sc }}">{{ $row->status }}</span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No matched rows.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Unmatched --}}
@if($unmatched->count() > 0)
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-question-circle me-1 text-warning"></i>Unmatched Rows ({{ $unmatched->count() }})</strong>
        <div class="text-muted small mt-1">No NOC employee matched by email. Decide per row: create a new employee, skip, or link to an existing one.</div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr><th>Oracle row</th><th>Branch</th><th style="min-width:430px">Resolve</th></tr>
            </thead>
            <tbody>
            @foreach($unmatched as $row)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $row->emp_name }}</div>
                        <div class="text-muted" style="font-size:.75em">{{ $row->email ?? 'no email' }}</div>
                        <div class="text-muted" style="font-size:.72em">#{{ $row->emp_no }} · {{ $row->job_name }} · {{ $row->dept_name }}</div>
                    </td>
                    <td>
                        @if($row->resolvedBranch)<span class="badge bg-info text-dark">{{ $row->resolvedBranch->name }}</span>
                        @else<span class="text-muted">—</span>@endif
                    </td>
                    <td>
                        @if($row->status === 'skipped')
                            <span class="badge bg-secondary">skipped</span>
                            <span class="text-muted small">— re-resolve below if needed</span>
                        @endif
                        @can('manage-identity')
                        <form method="POST" action="{{ route('admin.identity.hr-import.resolve-row', $row) }}"
                              class="d-flex flex-wrap align-items-center gap-2 resolve-form">
                            @csrf
                            <select name="decision" class="form-select form-select-sm decision-select" style="width:auto" required>
                                <option value="create">Create new employee</option>
                                <option value="link">Link to existing…</option>
                                <option value="skip">Skip</option>
                            </select>
                            <input type="text" class="form-control form-control-sm link-input" list="employeesDL"
                                   placeholder="Search employee…" style="width:240px; display:none" autocomplete="off">
                            <input type="hidden" name="link_employee_id" class="link-id">
                            <button type="submit" class="btn btn-sm btn-outline-primary">Resolve</button>
                        </form>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<datalist id="employeesDL">
    @foreach($employees as $e)
    <option data-id="{{ $e->id }}" value="{{ $e->name }} — {{ $e->email }}"></option>
    @endforeach
</datalist>
@endif

{{-- Errors --}}
@if($errorRows->count() > 0)
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent"><strong><i class="bi bi-exclamation-octagon me-1 text-danger"></i>Errors ({{ $errorRows->count() }})</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light"><tr><th>Row</th><th>Name</th><th>Email</th><th>Issue</th></tr></thead>
            <tbody>
            @foreach($errorRows as $row)
                <tr>
                    <td>{{ $row->row_number }}</td>
                    <td>{{ $row->emp_name ?? '—' }}</td>
                    <td>{{ $row->email ?? '—' }}</td>
                    <td class="text-danger">{{ $row->error_note ?? 'Unknown' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- Reconciliation --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-shield-exclamation me-1 text-danger"></i>Reconciliation</strong>
        <div class="text-muted small mt-1">NOC employees that the Oracle export does not account for, and inactive/disabled accounts. Report only — no changes are made here.</div>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#notInHr" type="button">
                Not in HR <span class="badge bg-danger">{{ $flagged['not_in_hr']->count() }}</span></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#inactive" type="button">
                Inactive / disabled <span class="badge bg-warning text-dark">{{ $flagged['inactive']->count() }}</span></button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="notInHr">
                <div class="text-muted small mb-2">Have no Oracle match (<code>oracle_synced_at</code> is empty) — likely terminated or never in HRMS.</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Employee</th><th>Branch</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        @forelse($flagged['not_in_hr'] as $emp)
                            <tr>
                                <td><div class="fw-semibold">{{ $emp->name }}</div><div class="text-muted" style="font-size:.75em">{{ $emp->email }}</div></td>
                                <td>{{ $emp->branch?->name ?? '—' }}</td>
                                <td><span class="badge {{ $emp->statusBadgeClass() }}">{{ $emp->status }}</span></td>
                                <td><a href="{{ route('admin.employees.show', $emp->id) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">None — every employee is accounted for in HR.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="tab-pane fade" id="inactive">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Employee</th><th>Branch</th><th>NOC status</th><th>Azure</th><th></th></tr></thead>
                        <tbody>
                        @forelse($flagged['inactive'] as $emp)
                            <tr>
                                <td><div class="fw-semibold">{{ $emp->name }}</div><div class="text-muted" style="font-size:.75em">{{ $emp->email }}</div></td>
                                <td>{{ $emp->branch?->name ?? '—' }}</td>
                                <td><span class="badge {{ $emp->statusBadgeClass() }}">{{ $emp->status }}</span></td>
                                <td>
                                    @if($emp->azure_removed_at)<span class="badge bg-danger">removed</span>
                                    @elseif($emp->azure_disabled_at)<span class="badge bg-warning text-dark">disabled</span>
                                    @elseif($emp->identityUser && ! $emp->identityUser->account_enabled)<span class="badge bg-warning text-dark">disabled</span>
                                    @else<span class="text-muted">—</span>@endif
                                </td>
                                <td><a href="{{ route('admin.employees.show', $emp->id) }}" class="btn btn-sm btn-outline-secondary">View</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">No inactive or disabled accounts.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
(function () {
    // Build label → employee id map from the datalist.
    const map = {};
    document.querySelectorAll('#employeesDL option').forEach(o => map[o.value] = o.dataset.id);

    document.querySelectorAll('.resolve-form').forEach(form => {
        const sel   = form.querySelector('.decision-select');
        const input = form.querySelector('.link-input');
        const hidden= form.querySelector('.link-id');

        const sync = () => {
            const isLink = sel.value === 'link';
            input.style.display = isLink ? '' : 'none';
            input.required = isLink;
            if (!isLink) { hidden.value = ''; input.value = ''; }
        };
        sel.addEventListener('change', sync);
        input.addEventListener('change', () => { hidden.value = map[input.value] || ''; });
        form.addEventListener('submit', e => {
            if (sel.value === 'link' && !hidden.value) {
                e.preventDefault();
                alert('Pick an employee from the list to link to.');
            }
        });
        sync();
    });
})();
</script>
@endpush

@endsection
