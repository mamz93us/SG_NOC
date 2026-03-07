@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-cloud-arrow-down-fill me-2 text-primary"></i>Sync Employees from Azure</h4>
        <small class="text-muted">Import users from Azure AD that have not yet been linked to an employee record</small>
    </div>
    <a href="{{ route('admin.employees.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Employees
    </a>
</div>


{{-- Auto-mapping info banner --}}
<div class="alert alert-info border-0 mb-4">
    <i class="bi bi-magic me-2"></i>
    <strong>Auto-mapping enabled:</strong>
    Department will be <strong>auto-created/matched</strong> by name from Azure.
    Manager will be <strong>auto-linked</strong> if the manager's employee record is already imported.
    Branch is <strong>matched by Office Location → Branch name</strong>; if not matched, the fallback branch selected below is used.
</div>

@if($azureUsers->isEmpty())
<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    All Azure AD users are already linked to employee records, or no users match the domain filters.
</div>
@else
<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-people me-1"></i>{{ $azureUsers->count() }} unlinked Azure user(s)</strong>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll(true)">Select All</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAll(false)">Deselect All</button>
        </div>
    </div>
    <div class="card-body p-0">
        <form method="POST" action="{{ route('admin.employees.sync.do') }}" id="syncForm">
            @csrf

            {{-- Fallback branch selector --}}
            <div class="p-3 bg-light border-bottom">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold mb-1">
                            <i class="bi bi-building me-1"></i>Fallback Branch
                            <span class="text-muted fw-normal">(used when Office Location doesn't match a branch name)</span>
                        </label>
                        <select name="branch_id" class="form-select form-select-sm">
                            <option value="">— No Fallback Branch —</option>
                            @foreach($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-auto">
                        <button type="submit" class="btn btn-primary btn-sm" id="importBtn" disabled>
                            <i class="bi bi-cloud-arrow-down me-1"></i>Import Selected
                        </button>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px"><input type="checkbox" class="form-check-input" id="checkAll" onchange="toggleAll(this)"></th>
                            <th>Name</th>
                            <th>UPN / Email</th>
                            <th>Department <span class="badge bg-success ms-1" style="font-size:.65em">auto</span></th>
                            <th>Job Title</th>
                            <th>Office Location <span class="badge bg-info text-dark ms-1" style="font-size:.65em">→ branch</span></th>
                            <th>Manager <span class="badge bg-warning text-dark ms-1" style="font-size:.65em">auto</span></th>
                            <th class="text-center">Account</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($azureUsers as $azUser)
                    @php
                        $managerName = null;
                        if ($azUser->manager_azure_id) {
                            $managerIdentity = \App\Models\IdentityUser::where('azure_id', $azUser->manager_azure_id)->first();
                            $managerName = $managerIdentity?->display_name;
                        }
                        $matchedBranch = null;
                        if ($azUser->office_location) {
                            $matchedBranch = \App\Models\Branch::where('name', 'like', $azUser->office_location)->first();
                        }
                    @endphp
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input row-check" name="azure_ids[]"
                                   value="{{ $azUser->azure_id }}" onchange="updateImportBtn()">
                        </td>
                        <td class="fw-semibold">{{ $azUser->display_name }}</td>
                        <td class="text-muted" style="font-size:.85em">{{ $azUser->user_principal_name }}</td>
                        <td>
                            @if($azUser->department)
                            <span class="badge bg-success">{{ $azUser->department }}</span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $azUser->job_title ?? '—' }}</td>
                        <td>
                            @if($azUser->office_location)
                            <span class="badge {{ $matchedBranch ? 'bg-info text-dark' : 'bg-light text-muted border' }}">
                                <i class="bi bi-{{ $matchedBranch ? 'check-circle-fill' : 'geo-alt' }} me-1"></i>{{ $azUser->office_location }}
                            </span>
                            @if($matchedBranch)
                            <div class="text-success" style="font-size:.75em"><i class="bi bi-arrow-right me-1"></i>{{ $matchedBranch->name }}</div>
                            @endif
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($managerName)
                            <span class="badge bg-warning text-dark">{{ $managerName }}</span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <span class="badge {{ $azUser->account_enabled ? 'bg-success' : 'bg-secondary' }}">
                                {{ $azUser->account_enabled ? 'Active' : 'Disabled' }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>
@endif

@push('scripts')
<script>
function selectAll(state) {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = state);
    updateImportBtn();
}
function toggleAll(masterCb) {
    selectAll(masterCb.checked);
}
function updateImportBtn() {
    const checked = document.querySelectorAll('.row-check:checked').length;
    const total   = document.querySelectorAll('.row-check').length;
    document.getElementById('importBtn').disabled = checked === 0;
    document.getElementById('checkAll').checked       = checked > 0 && checked === total;
    document.getElementById('checkAll').indeterminate = checked > 0 && checked < total;
}
</script>
@endpush
@endsection
