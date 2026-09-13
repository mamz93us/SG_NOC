@extends('layouts.admin')

@section('title', 'Attendance — Owners')

@section('content')
@include('admin.attendance._tabs')

<div class="mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Attendance owners</h4>
    <small class="text-muted">
        People who can ask the assistant on the home portal about the attendance of everyone in the company, or of
        everyone in the branches ticked here — a general manager, a branch GM. Nobody needs a row to see their own
        attendance there, and managers and supervisors already see the people who report to them (the employee
        record's Manager and Supervisor). No one sees the attendance of people who have left.
    </small>
</div>

@can('manage-attendance-owners')
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent fw-semibold"><i class="bi bi-person-plus me-1"></i>Add to the list</div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.attendance.owners.store') }}">
                @csrf
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label small mb-1">Person</label>
                        <input name="employee" list="owner-employee-options" class="form-control form-control-sm" autocomplete="off" required
                               placeholder="Type a name, email or Oracle no…" value="{{ old('employee') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Title <span class="text-muted">(optional)</span></label>
                        <input name="title" class="form-control form-control-sm" maxlength="150" value="{{ old('title') }}"
                               placeholder="e.g. General Manager, GM Jeddah">
                    </div>
                </div>
                @include('admin.attendance.owners._access', [
                    'prefix' => 'new',
                    'scope' => old('scope', \App\Models\Attendance\AttendanceOwner::SCOPE_BRANCHES),
                    'selected' => old('branch_ids', []),
                ])
                <button class="btn btn-sm btn-primary mt-3"><i class="bi bi-plus-lg me-1"></i>Add</button>
            </form>
        </div>
    </div>

    <datalist id="owner-employee-options">
        @foreach ($employeeOptions as $e)
            <option value="{{ $e->id }} · {{ $e->name }}{{ $e->oracle_emp_no ? ' · Oracle '.$e->oracle_emp_no : '' }}{{ $e->branch ? ' · '.$e->branch->name : '' }}">{{ $e->email }}</option>
        @endforeach
    </datalist>
@endcan

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Person</th>
                    <th>Title</th>
                    <th>Can see the attendance of</th>
                    <th>Added</th>
                    @can('manage-attendance-owners')
                        <th style="width:110px"></th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($owners as $owner)
                    @php $employee = $owner->employee; @endphp
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $employee?->name ?? 'Deleted employee #'.$owner->employee_id }}</div>
                            <div class="small text-muted">{{ collect([$employee?->email, $employee?->branch?->name])->filter()->implode(' · ') }}</div>
                            @if ($employee && $employee->status === 'terminated')
                                <span class="badge bg-danger">Has left — no access</span>
                            @elseif ($employee && ! $employee->email)
                                <span class="badge bg-warning text-dark">No email — cannot sign in to the home portal</span>
                            @endif
                        </td>
                        <td class="small">{{ $owner->title ?: '—' }}</td>
                        <td>
                            @if ($owner->isCompanyWide())
                                <span class="badge bg-primary"><i class="bi bi-globe2 me-1"></i>Whole company</span>
                            @else
                                @foreach ($owner->branchIds() as $branchId)
                                    <span class="badge bg-info-subtle text-body border me-1">{{ $branches->get($branchId)?->name ?? 'Deleted branch #'.$branchId }}</span>
                                @endforeach
                            @endif
                        </td>
                        <td class="small text-muted text-nowrap">
                            {{ $owner->created_at?->format('d M Y') }}
                            @if ($owner->creator)
                                <div>by {{ $owner->creator->name }}</div>
                            @endif
                        </td>
                        @can('manage-attendance-owners')
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse"
                                        data-bs-target="#owner-edit-{{ $owner->id }}" title="Change">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="{{ route('admin.attendance.owners.destroy', $owner) }}" class="d-inline"
                                      onsubmit="return confirm('Remove this person from the owner list? They keep seeing their own attendance and their direct reports.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-x-lg"></i></button>
                                </form>
                            </td>
                        @endcan
                    </tr>
                    @can('manage-attendance-owners')
                        <tr class="collapse" id="owner-edit-{{ $owner->id }}">
                            <td colspan="5" class="bg-body-tertiary">
                                <form method="POST" action="{{ route('admin.attendance.owners.update', $owner) }}">
                                    @csrf
                                    @method('PUT')
                                    <div class="row g-2">
                                        <div class="col-md-4">
                                            <label class="form-label small mb-1">Title</label>
                                            <input name="title" class="form-control form-control-sm" maxlength="150" value="{{ $owner->title }}">
                                        </div>
                                    </div>
                                    @include('admin.attendance.owners._access', [
                                        'prefix' => 'owner-'.$owner->id,
                                        'scope' => $owner->scope,
                                        'selected' => $owner->branchIds(),
                                    ])
                                    <button class="btn btn-sm btn-primary mt-3">Save</button>
                                </form>
                            </td>
                        </tr>
                    @endcan
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            Nobody is on the list yet — beyond their own, people see only the attendance of those who report to them.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('manage-attendance-owners')
    <script>
    // The branch boxes only apply to "Chosen branches".
    document.querySelectorAll('[data-owner-access]').forEach(function (box) {
        var branches = box.querySelector('[data-owner-branches]');
        box.querySelectorAll('input[name="scope"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                branches.hidden = box.querySelector('input[name="scope"]:checked').value !== 'branches';
            });
        });
    });
    </script>
@endcan
@endsection
