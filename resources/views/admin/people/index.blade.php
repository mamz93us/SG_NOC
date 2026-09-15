@extends('layouts.admin')

@section('title', 'Employee profiles')

@section('content')
@php
    $days = fn ($value) => \App\Models\Vacation\VacationBalance::days($value === null ? null : (float) $value);
    $columns = 3 + ($canAttendance ? 3 : 0) + ($canVacations ? 2 : 0);
@endphp

<div class="mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-person-badge me-2 text-primary"></i>Employee profiles</h4>
    <small class="text-muted">
        One page per person with their attendance and their Oracle vacation together: the year month by month, the
        month day by day, the leave balance and every leave record. Pick someone to open their profile.
    </small>
</div>

{{-- ── Filters ──────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="Name, email or Oracle no…">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Branch</label>
                <select name="branch" class="form-select form-select-sm">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($filters['branch'] === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Department</label>
                <select name="department" class="form-select form-select-sm">
                    <option value="">All departments</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected($filters['department'] === $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">People</label>
                <select name="status" class="form-select form-select-sm">
                    @foreach (\App\Http\Controllers\Admin\EmployeeProfileController::STATUS as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">With</label>
                <select name="data" class="form-select form-select-sm">
                    @foreach (\App\Http\Controllers\Admin\EmployeeProfileController::DATA as $value => $label)
                        @if ($value === 'all' || ($value === 'attendance' && $canAttendance) || ($value === 'vacation' && $canVacations))
                            <option value="{{ $value }}" @selected($filters['data'] === $value)>{{ $label }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button class="btn btn-sm btn-outline-primary flex-grow-1" title="Filter"><i class="bi bi-funnel"></i></button>
                <a href="{{ route('admin.people.index') }}" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<small class="text-muted d-block mb-2">{{ $employees->total() }} {{ \Illuminate\Support\Str::plural('person', $employees->total()) }}</small>

{{-- ── People ───────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Person</th>
                    <th>Branch / Department</th>
                    @if ($canAttendance)
                        <th class="text-center" title="Present days in {{ $today->format('F') }}">Present<div class="small fw-normal text-muted">{{ $today->format('M') }}</div></th>
                        <th class="text-center" title="Absent days in {{ $today->format('F') }}">Absent<div class="small fw-normal text-muted">{{ $today->format('M') }}</div></th>
                        <th class="text-center" title="Late arrivals in {{ $today->format('F') }}">Late<div class="small fw-normal text-muted">{{ $today->format('M') }}</div></th>
                    @endif
                    @if ($canVacations)
                        <th class="text-end" title="Oracle's remaining annual leave for {{ $today->year }}">Vacation left</th>
                        <th>Today</th>
                    @endif
                    <th style="width:60px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $employee)
                    @php
                        $page = route('admin.people.show', $employee);
                        $month = $attendance->get($employee->id);
                        $balance = $balances->get($employee->id);
                        $away = $awayToday->get($employee->id, collect());
                    @endphp
                    <tr style="cursor:pointer" onclick="window.location='{{ $page }}'">
                        <td>
                            <a href="{{ $page }}" class="fw-semibold text-decoration-none">{{ $employee->name }}</a>
                            @if ($employee->status === 'terminated')
                                <span class="badge bg-danger ms-1">Left</span>
                            @endif
                            <div class="small text-muted">{{ collect([$employee->job_title, $employee->oracle_emp_no ? 'Oracle '.$employee->oracle_emp_no : null])->filter()->implode(' · ') }}</div>
                        </td>
                        <td class="small">
                            {{ $employee->branch?->name ?: '—' }}
                            @if ($employee->department)
                                <div class="text-muted">{{ $employee->department->name }}</div>
                            @endif
                        </td>
                        @if ($canAttendance)
                            @if ($month)
                                <td class="text-center">{{ (int) $month->present_days }}</td>
                                <td class="text-center {{ $month->absent_days > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">{{ (int) $month->absent_days }}</td>
                                <td class="text-center {{ $month->late_days > 0 ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">{{ (int) $month->late_days }}</td>
                            @else
                                <td colspan="3" class="text-center small text-muted">{{ $punching->has($employee->id) ? 'Nothing recorded this month' : 'Not on BioTime' }}</td>
                            @endif
                        @endif
                        @if ($canVacations)
                            <td class="text-end">
                                @if ($balance && $balance->hasBalance())
                                    <span class="fw-semibold {{ $balance->balance < 0 ? 'text-danger' : '' }}">{{ $days($balance->balance) }}</span>
                                    <div class="small text-muted">as of {{ $balance->as_of->format('d M') }}</div>
                                @elseif ($balance)
                                    <span class="small text-muted">No leave plan yet</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="small">
                                @foreach ($away as $record)
                                    <span class="badge {{ \App\Models\Vacation\VacationAbsence::typeBadgeClass($record->absence_type) }}">
                                        <i class="bi {{ $record->isBusinessTrip() ? 'bi-briefcase' : 'bi-airplane' }} me-1"></i>{{ $record->absence_type }}
                                    </span>
                                @endforeach
                            </td>
                        @endif
                        <td class="text-end">
                            <a href="{{ $page }}" class="btn btn-sm btn-outline-primary">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $columns }}" class="text-center text-muted py-5">
                            <i class="bi bi-person-x fs-3 d-block mb-2"></i>
                            Nobody matches these filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($employees->hasPages())
    <div class="mt-3">{{ $employees->links() }}</div>
@endif
@endsection
