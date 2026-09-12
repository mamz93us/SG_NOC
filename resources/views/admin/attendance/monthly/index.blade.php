@extends('layouts.admin')

@section('title', 'Attendance — Monthly sheet')

@section('content')
@include('admin.attendance._tabs')

@php
    $monthLabel = \Carbon\CarbonImmutable::parse($filters['month'].'-01')->format('F Y');
    $previous = \Carbon\CarbonImmutable::parse($filters['month'].'-01')->subMonth()->format('Y-m');
    $next = \Carbon\CarbonImmutable::parse($filters['month'].'-01')->addMonth()->format('Y-m');
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-calendar3 me-2 text-primary"></i>Monthly sheet</h4>
        <small class="text-muted">
            One person's whole month — every day's check-in, check-out and punches, with the month added up.
            Pick someone to open their sheet.
        </small>
    </div>
    <div class="btn-group">
        <a class="btn btn-outline-secondary btn-sm"
           href="{{ route('admin.attendance.monthly.index', array_merge(request()->except('page'), ['month' => $previous])) }}">
            <i class="bi bi-chevron-left"></i>
        </a>
        <span class="btn btn-sm btn-light disabled fw-semibold">{{ $monthLabel }}</span>
        <a class="btn btn-outline-secondary btn-sm"
           href="{{ route('admin.attendance.monthly.index', array_merge(request()->except('page'), ['month' => $next])) }}">
            <i class="bi bi-chevron-right"></i>
        </a>
    </div>
</div>

{{-- ── Filters ──────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Month</label>
                <input type="month" name="month" value="{{ $filters['month'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">Branch</label>
                <select name="branch" class="form-select form-select-sm">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($filters['branch'] === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">Department</label>
                <select name="department" class="form-select form-select-sm">
                    <option value="">All departments</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected($filters['department'] === $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm"
                       placeholder="Name, BioTime code or Oracle no…">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="{{ route('admin.attendance.monthly.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

{{-- ── People ───────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Person</th>
                    <th>Branch / Department</th>
                    <th class="text-center" style="width:70px">Present</th>
                    <th class="text-center" style="width:70px">Absent</th>
                    <th class="text-center" style="width:80px">Worked</th>
                    <th class="text-center" style="width:80px">Overtime</th>
                    <th class="text-center" style="width:70px">Late</th>
                    <th class="text-center" style="width:90px">Early leave</th>
                    <th class="text-center" style="width:90px">No check-out</th>
                    <th style="width:60px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $employee)
                    @php
                        $t = $totals->get($employee->id);
                        $sheet = route('admin.attendance.monthly.show', ['employee' => $employee, 'month' => $filters['month']]);
                    @endphp
                    <tr style="cursor:pointer" onclick="window.location='{{ $sheet }}'">
                        <td>
                            <a href="{{ $sheet }}" class="fw-semibold text-decoration-none">{{ $employee->name }}</a>
                            @if ($employee->oracle_emp_no)
                                <div class="small text-muted font-monospace">Oracle {{ $employee->oracle_emp_no }}</div>
                            @endif
                        </td>
                        <td class="small">
                            {{ $employee->branch?->name ?: '—' }}
                            @if ($employee->department)
                                <div class="text-muted">{{ $employee->department->name }}</div>
                            @endif
                        </td>
                        @if ($t)
                            <td class="text-center">
                                {{ (int) $t->present_days }}
                                @if ($t->excused_days > 0)
                                    <div class="small text-info-emphasis">+{{ (int) $t->excused_days }} excused</div>
                                @endif
                            </td>
                            <td class="text-center {{ $t->absent_days > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">
                                {{ (int) $t->absent_days }}
                            </td>
                            <td class="text-center font-monospace small">
                                {{ \App\Models\Attendance\AttendanceDay::hoursLabel((int) $t->worked_minutes) }}
                            </td>
                            <td class="text-center font-monospace small {{ $t->overtime_minutes > 0 ? 'text-primary' : 'text-muted' }}">
                                {{ $t->overtime_minutes > 0 ? \App\Models\Attendance\AttendanceDay::hoursLabel((int) $t->overtime_minutes) : '—' }}
                            </td>
                            <td class="text-center">
                                @if ($t->late_days > 0)
                                    <span class="text-warning-emphasis fw-semibold">{{ (int) $t->late_days }}</span>
                                    <div class="small text-muted">{{ \App\Models\Attendance\AttendanceDay::minutesLabel((int) $t->late_minutes) }}</div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if ($t->early_leave_days > 0)
                                    <span class="text-warning-emphasis fw-semibold">{{ (int) $t->early_leave_days }}</span>
                                    <div class="small text-muted">{{ \App\Models\Attendance\AttendanceDay::minutesLabel((int) $t->early_leave_minutes) }}</div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center {{ $t->missing_check_outs > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">
                                {{ $t->missing_check_outs > 0 ? $t->missing_check_outs : '—' }}
                            </td>
                        @else
                            <td colspan="7" class="text-center text-muted small">Nothing recorded this month</td>
                        @endif
                        <td class="text-end">
                            <a href="{{ $sheet }}" class="btn btn-sm btn-outline-primary">Sheet</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-5">
                            <i class="bi bi-person-x fs-3 d-block mb-2"></i>
                            Nobody matches. Only people with a BioTime code appear here —
                            link codes under <a href="{{ route('admin.attendance.employees.index') }}">Employee Mapping</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="small text-muted mt-2">
    Days off and holidays are not counted here — they are calendar facts with no attendance row. Open a sheet to see them.
    Unmapped BioTime codes belong to nobody yet, so they only appear on
    <a href="{{ route('admin.attendance.days.index', ['status' => 'unmapped']) }}">Check-in / Check-out</a>.
</p>

@if ($employees->hasPages())
    <div class="mt-3">{{ $employees->links() }}</div>
@endif
@endsection
