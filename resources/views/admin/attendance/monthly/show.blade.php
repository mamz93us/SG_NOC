@extends('layouts.admin')

@section('title', 'Attendance — '.$employee->name.' — '.$monthStart->format('F Y'))

@section('content')
@include('admin.attendance._tabs')

@php
    use App\Models\Attendance\AttendanceDay;
    use App\Services\Attendance\MonthlyDay;

    $query = fn (string $m) => ['employee' => $employee, 'month' => $m];
    $previous = $monthStart->subMonth()->format('Y-m');
    $next = $monthStart->addMonth()->format('Y-m');

    $tiles = [
        ['label' => 'Work days', 'value' => $totals->workDays, 'cls' => 'text-body', 'note' => $totals->offDays.' off · '.$totals->holidayDays.' holiday'],
        ['label' => 'Present', 'value' => $totals->presentDays, 'cls' => 'text-success', 'note' => $totals->excusedDays > 0 ? $totals->excusedDays.' excused' : null],
        ['label' => 'Absent', 'value' => $totals->absentDays, 'cls' => $totals->absentDays > 0 ? 'text-danger' : 'text-muted', 'note' => $totals->unrecordedDays > 0 ? $totals->unrecordedDays.' not recorded' : null],
        ['label' => 'Total worked', 'value' => $totals->workedLabel(), 'cls' => 'text-body', 'note' => $totals->punches.' punches'],
        ['label' => 'Overtime', 'value' => $totals->overtimeLabel(), 'cls' => $totals->overtimeMinutes > 0 ? 'text-primary' : 'text-muted', 'note' => $totals->workedOffDays > 0 ? $totals->workedOffDays.' on days off' : null],
        ['label' => 'Late', 'value' => $totals->lateDays, 'cls' => $totals->lateDays > 0 ? 'text-warning-emphasis' : 'text-muted', 'note' => AttendanceDay::minutesLabel($totals->lateMinutes).' in total'],
        ['label' => 'Early leave', 'value' => $totals->earlyLeaveDays, 'cls' => $totals->earlyLeaveDays > 0 ? 'text-warning-emphasis' : 'text-muted', 'note' => AttendanceDay::minutesLabel($totals->earlyLeaveMinutes).' in total'],
        ['label' => 'Missing check-out', 'value' => $totals->missingCheckOuts, 'cls' => $totals->missingCheckOuts > 0 ? 'text-danger' : 'text-muted', 'note' => 'hours not counted'],
    ];
@endphp

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <a href="{{ route('admin.attendance.monthly.index', ['month' => $month]) }}" class="small text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>All people
        </a>
        <h4 class="mb-0 mt-1 fw-bold">{{ $employee->name }}</h4>
        <small class="text-muted">
            {{ $monthStart->format('F Y') }}
            @if ($codes)
                · BioTime code {{ $codes }}
            @else
                · <span class="text-danger">no BioTime code — nothing can be recorded</span>
            @endif
            @if ($employee->oracle_emp_no)
                · Oracle {{ $employee->oracle_emp_no }}
            @endif
            @if ($employee->branch)
                · {{ $employee->branch->name }}
            @endif
            @if ($employee->department)
                · {{ $employee->department->name }}
            @endif
        </small>
    </div>
    <div class="d-flex gap-2">
        <div class="btn-group">
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.attendance.monthly.show', $query($previous)) }}">
                <i class="bi bi-chevron-left"></i>
            </a>
            <span class="btn btn-sm btn-light disabled fw-semibold">{{ $monthStart->format('M Y') }}</span>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.attendance.monthly.show', $query($next)) }}">
                <i class="bi bi-chevron-right"></i>
            </a>
        </div>
        <a href="{{ route('admin.people.show', ['employee' => $employee, 'month' => $month]) }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-person-badge me-1"></i>Profile
        </a>
<a href="{{ route('admin.attendance.monthly.export', $query($month)) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Export CSV
        </a>
    </div>
</div>

{{-- ── The month, added up ──────────────────────────────────── --}}
<div class="row g-3 mb-2">
    @foreach ($tiles as $tile)
        <div class="col-6 col-md-3 col-xl">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold {{ $tile['cls'] }}">{{ $tile['value'] }}</div>
                    <div class="small text-muted">{{ $tile['label'] }}</div>
                    @if ($tile['note'])
                        <div class="small text-muted">{{ $tile['note'] }}</div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>

<p class="small text-muted mb-3">
    Worked hours are check-in to check-out, so a day with a <strong>missing check-out</strong> adds nothing to the total —
    it is a gap to correct, not a day of no work. Correct one on its own day page.
</p>

@include('admin.attendance.monthly._days', ['days' => $days, 'leaveByDate' => $leaveByDate ?? []])

<p class="small text-muted mt-2">
    Check-in is the <strong>earliest</strong> punch of the day and check-out the <strong>latest</strong>; every punch in between is
    shown but moves neither. Click a day to see its raw punches, correct the times or excuse it.
    A punch marked <span class="font-monospace">+1</span> landed after midnight and belongs to that day's overnight shift.
</p>
@endsection
