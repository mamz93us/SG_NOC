@extends('layouts.admin')

@section('title', 'Attendance — Shifts')

@section('content')
@include('admin.attendance._tabs')

<div class="mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i>Shifts</h4>
    <small class="text-muted">
        A shift says when someone is due in and out. Assign one to everyone, a branch, a department or one person —
        the most specific assignment wins. <strong>Late</strong> counts from the shift start once the grace is passed;
        <strong>early leave</strong> up to the shift end; <strong>overtime</strong> is time after the end once it reaches the
        minimum, and every worked minute on a day off or a holiday. People with no shift still get check-in / check-out,
        but no lateness and no absences. A change recalculates the last {{ $rebuildDays }} days straight away.
    </small>
</div>

{{-- ── Shifts ───────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent fw-semibold">Shifts</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Shift</th>
                    <th>Hours</th>
                    <th>Grace in / out</th>
                    <th>Max hours</th>
                    <th>Overtime after</th>
                    <th>Days off</th>
                    <th class="text-end">Assigned</th>
                    @can('manage-attendance')
                        <th style="width:110px"></th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($shifts as $shift)
                    <tr>
                        <td>
                            <span class="fw-semibold">{{ $shift->name }}</span>
                            @unless ($shift->is_active)
                                <span class="badge bg-secondary ms-1">off</span>
                            @endunless
                        </td>
                        <td class="font-monospace">
                            {{ $shift->timesLabel() }}
                            @if ($shift->crosses_midnight)
                                <span class="badge bg-dark-subtle text-body border ms-1">overnight</span>
                            @endif
                        </td>
                        <td class="small">{{ $shift->grace_in_minutes }} / {{ $shift->grace_out_minutes }} min</td>
                        <td class="small">{{ $shift->max_hours ? $shift->max_hours.' h' : '—' }}</td>
                        <td class="small">{{ $shift->min_overtime_minutes }} min</td>
                        <td class="small">{{ $shift->offDaysLabel() }}</td>
                        <td class="text-end">{{ $shift->assignments_count }}</td>
                        @can('manage-attendance')
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse"
                                        data-bs-target="#shift-edit-{{ $shift->id }}" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @if ($shift->assignments_count === 0)
                                    <form method="POST" action="{{ route('admin.attendance.shifts.destroy', $shift) }}" class="d-inline"
                                          onsubmit="return confirm('Delete this shift?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                @endif
                            </td>
                        @endcan
                    </tr>
                    @can('manage-attendance')
                        <tr class="collapse" id="shift-edit-{{ $shift->id }}">
                            <td colspan="8" class="bg-body-tertiary">
                                <form method="POST" action="{{ route('admin.attendance.shifts.update', $shift) }}">
                                    @csrf
                                    @method('PUT')
                                    @include('admin.attendance.shifts._fields', ['shift' => $shift])
                                    <button class="btn btn-sm btn-primary mt-2">Save shift</button>
                                </form>
                            </td>
                        </tr>
                    @endcan
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No shifts yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('manage-attendance')
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent fw-semibold"><i class="bi bi-plus-lg me-1"></i>New shift</div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.attendance.shifts.store') }}">
                @csrf
                @include('admin.attendance.shifts._fields', [
                    'shift' => new \App\Models\Attendance\AttendanceShift([
                        'grace_in_minutes' => 15, 'grace_out_minutes' => 0, 'max_hours' => 16,
                        'min_overtime_minutes' => 30, 'off_days' => [5, 6], 'is_active' => true,
                    ]),
                    'useOld' => true,
                ])
                <button class="btn btn-sm btn-primary mt-2">Create shift</button>
                <span class="small text-muted ms-2">An end time before the start (22:00–06:00) makes an overnight shift.</span>
            </form>
        </div>
    </div>
@endcan

{{-- ── Assignments ──────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent fw-semibold">Who works which shift</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Applies to</th>
                    <th>Shift</th>
                    <th>From</th>
                    <th>To</th>
                    @can('manage-attendance')
                        <th style="width:60px"></th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($assignments as $assignment)
                    @php
                        $scopeLabel = match ($assignment->scope_type) {
                            'all' => 'Everyone',
                            'branch' => 'Branch: '.($branches->firstWhere('id', $assignment->scope_id)?->name ?? '#'.$assignment->scope_id),
                            'department' => 'Department: '.($departments->firstWhere('id', $assignment->scope_id)?->name ?? '#'.$assignment->scope_id),
                            'employee' => 'Employee: '.($assignedEmployees->get($assignment->scope_id)?->name ?? '#'.$assignment->scope_id),
                            default => $assignment->scope_type,
                        };
                    @endphp
                    <tr>
                        <td class="fw-semibold">{{ $scopeLabel }}</td>
                        <td>
                            {{ $assignment->shift?->name }}
                            <span class="small text-muted font-monospace">{{ $assignment->shift?->timesLabel() }}</span>
                        </td>
                        <td class="small">{{ $assignment->effective_from->format('d M Y') }}</td>
                        <td class="small">{{ $assignment->effective_to?->format('d M Y') ?? 'open-ended' }}</td>
                        @can('manage-attendance')
                            <td class="text-end">
                                <form method="POST" action="{{ route('admin.attendance.shifts.unassign', $assignment) }}"
                                      onsubmit="return confirm('Remove this assignment? The affected days are recalculated.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-x-lg"></i></button>
                                </form>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No one has a shift yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @can('manage-attendance')
        <div class="card-footer bg-transparent">
            <form method="POST" action="{{ route('admin.attendance.shifts.assign') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-2">
                    <label class="form-label small mb-1">Shift</label>
                    <select name="attendance_shift_id" class="form-select form-select-sm" required>
                        @foreach ($shifts->where('is_active', true) as $shift)
                            <option value="{{ $shift->id }}" @selected((string) old('attendance_shift_id') === (string) $shift->id)>{{ $shift->name }} ({{ $shift->timesLabel() }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Applies to</label>
                    <select name="scope_type" id="scopeType" class="form-select form-select-sm">
                        @foreach (\App\Models\Attendance\AttendanceShiftAssignment::SCOPES as $value => $label)
                            <option value="{{ $value }}" @selected(old('scope_type', 'branch') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 scope-field" data-scope="branch">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 scope-field" data-scope="department">
                    <label class="form-label small mb-1">Department</label>
                    <select name="department_id" class="form-select form-select-sm">
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((string) old('department_id') === (string) $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 scope-field" data-scope="employee">
                    <label class="form-label small mb-1">Employee</label>
                    <input name="employee" list="shift-employee-options" class="form-control form-control-sm" autocomplete="off"
                           placeholder="Type a name or Oracle no…" value="{{ old('employee') }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="effective_from" class="form-control form-control-sm" required
                           value="{{ old('effective_from', now()->toDateString()) }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">To <span class="text-muted">(optional)</span></label>
                    <input type="date" name="effective_to" class="form-control form-control-sm" value="{{ old('effective_to') }}">
                </div>
                <div class="col-md-1">
                    <button class="btn btn-sm btn-primary w-100" @disabled($shifts->where('is_active', true)->isEmpty())>Assign</button>
                </div>
            </form>
        </div>

        <datalist id="shift-employee-options">
            @foreach ($employeeOptions as $e)
                <option value="{{ $e->id }} · {{ $e->name }}{{ $e->oracle_emp_no ? ' · Oracle '.$e->oracle_emp_no : '' }}{{ $e->branch ? ' · '.$e->branch->name : '' }}"></option>
            @endforeach
        </datalist>

        <script>
        // Show only the field that matches "Applies to".
        (function () {
            var select = document.getElementById('scopeType');
            function sync() {
                document.querySelectorAll('.scope-field').forEach(function (el) {
                    el.hidden = el.dataset.scope !== select.value;
                });
            }
            select.addEventListener('change', sync);
            sync();
        })();
        </script>
    @endcan
</div>
@endsection
