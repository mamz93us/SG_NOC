@extends('layouts.admin')

@section('title', 'Attendance — Check-in / Check-out')

@section('content')
@include('admin.attendance._tabs')

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-calendar-check me-2 text-primary"></i>Check-in / Check-out</h4>
        <small class="text-muted">
            Check-in is each person's <strong>earliest</strong> punch of the day and check-out their <strong>latest</strong>,
            across every BioTime source, measured against their shift. Raw punches are never changed.
        </small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.attendance.days.export', request()->query()) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Export CSV
        </a>
        @can('manage-attendance')
            <form method="POST" action="{{ route('admin.attendance.days.reprocess') }}">
                @csrf
                <input type="hidden" name="from" value="{{ $filters['from'] }}">
                <input type="hidden" name="to" value="{{ $filters['to'] }}">
                <button class="btn btn-outline-primary btn-sm" title="Recalculate these days from the stored punches, shifts and holidays">
                    <i class="bi bi-arrow-clockwise me-1"></i>Rebuild days
                </button>
            </form>
        @endcan
    </div>
</div>

{{-- ── Summary ──────────────────────────────────────────────── --}}
<div class="row g-3 mb-4">
    @php
        $tiles = [
            ['label' => 'People', 'value' => $stats['people'], 'cls' => 'text-body', 'status' => null],
            ['label' => 'Person-days', 'value' => $stats['days'], 'cls' => 'text-body', 'status' => null],
            ['label' => 'With errors', 'value' => $stats['errors'], 'cls' => 'text-danger', 'status' => 'error'],
            ['label' => 'Absent', 'value' => $stats['absent'], 'cls' => 'text-danger', 'status' => 'absent'],
            ['label' => 'Missing check-out', 'value' => $stats['missing_out'], 'cls' => 'text-danger', 'status' => 'missing_out'],
            ['label' => 'Late', 'value' => $stats['late'], 'cls' => 'text-warning-emphasis', 'status' => 'late'],
            ['label' => 'Unmapped', 'value' => $stats['unmapped'], 'cls' => 'text-warning-emphasis', 'status' => 'unmapped'],
        ];
        $statusOptions = [
            'error' => 'With errors',
            'ok' => 'No errors',
            'absent' => 'Absent',
            'missing_out' => 'Missing check-out',
            'late' => 'Late',
            'early_leave' => 'Early leave',
            'overtime' => 'Overtime',
            'over_max' => 'Too many hours',
            'excused' => 'Excused',
            'adjusted' => 'Corrected by HR',
            'unmapped' => 'Unmapped',
            'duplicates' => 'Duplicate punches',
        ];
    @endphp
    @foreach ($tiles as $tile)
        <div class="col-6 col-md">
            <a class="card shadow-sm border-0 h-100 text-decoration-none"
               href="{{ route('admin.attendance.days.index', array_merge(request()->except('page', 'status'), ['status' => $tile['status']])) }}">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold {{ $tile['cls'] }}">{{ number_format($tile['value']) }}</div>
                    <div class="small text-muted">{{ $tile['label'] }}</div>
                </div>
            </a>
        </div>
    @endforeach
</div>

{{-- ── Filters ──────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">From</label>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">To</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm">
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
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Everything</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($sources->count() > 1)
                <div class="col-6 col-md-2">
                    <label class="form-label small text-muted mb-1">Source</label>
                    <select name="source" class="form-select form-select-sm">
                        <option value="">All sources</option>
                        @foreach ($sources as $src)
                            <option value="{{ $src->id }}" @selected($filters['source'] === $src->id)>{{ $src->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-md-4">
                <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm"
                       placeholder="Name, BioTime code or Oracle no…">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="{{ route('admin.attendance.days.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

{{-- ── Days ─────────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:100px">Date</th>
                    <th>Person</th>
                    <th>Branch / Department</th>
                    <th>Shift</th>
                    <th class="text-center" style="width:90px">Check-in</th>
                    <th class="text-center" style="width:90px">Check-out</th>
                    <th class="text-center" style="width:70px">Worked</th>
                    <th class="text-center" style="width:70px">Overtime</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($days as $d)
                    <tr style="cursor:pointer" onclick="window.location='{{ route('admin.attendance.days.show', $d) }}'">
                        <td class="small text-nowrap">
                            {{ $d->work_date->format('D d M') }}
                            @if ($d->locked)
                                <i class="bi bi-lock-fill text-muted ms-1" title="In an approved period — locked"></i>
                            @endif
                        </td>
                        <td>
                            @if ($d->employee)
                                <a href="{{ route('admin.attendance.days.show', $d) }}" class="fw-semibold text-decoration-none">{{ $d->employee->name }}</a>
                            @else
                                <a href="{{ route('admin.attendance.days.show', $d) }}" class="fw-semibold text-decoration-none text-danger">Unmapped code</a>
                            @endif
                            <div class="small text-muted font-monospace">
                                {{ $d->emp_codes }}
                                @if ($d->employee?->oracle_emp_no && $d->employee->oracle_emp_no !== $d->emp_codes)
                                    · Oracle {{ $d->employee->oracle_emp_no }}
                                @endif
                            </div>
                        </td>
                        <td class="small">
                            {{ $d->branch?->name ?: '—' }}
                            @if ($d->employee?->department)
                                <div class="text-muted">{{ $d->employee->department->name }}</div>
                            @endif
                        </td>
                        <td class="small">
                            @if ($d->shift)
                                {{ $d->shift->name }}
                                <div class="text-muted font-monospace">{{ $d->shift->timesLabel() }}</div>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <span class="font-monospace">{{ $d->first_in?->format('H:i') ?? '—' }}</span>
                            @if ($d->late_minutes > 0)
                                <div class="small text-danger">{{ \App\Models\Attendance\AttendanceDay::minutesLabel($d->late_minutes) }} late</div>
                            @endif
                        </td>
                        <td class="text-center">
                            @if ($d->last_out)
                                <span class="font-monospace">{{ $d->last_out->format('H:i') }}</span>
                                @unless ($d->last_out->isSameDay($d->work_date))
                                    <div class="small text-muted">next day</div>
                                @endunless
                                @if ($d->early_leave_minutes > 0)
                                    <div class="small text-danger">{{ \App\Models\Attendance\AttendanceDay::minutesLabel($d->early_leave_minutes) }} early</div>
                                @endif
                            @elseif ($d->first_in)
                                <span class="text-danger">—</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-center font-monospace small">{{ $d->workedLabel() }}</td>
                        <td class="text-center font-monospace small">
                            {{ $d->overtime_minutes > 0 ? \App\Models\Attendance\AttendanceDay::hoursLabel($d->overtime_minutes) : '—' }}
                        </td>
                        <td>
                            @forelse ($d->flagBadges() as $badge)
                                <span class="badge {{ $badge['error'] ? 'bg-danger' : 'bg-warning text-dark' }}">{{ $badge['label'] }}</span>
                            @empty
                                <span class="badge bg-success-subtle text-success border border-success-subtle">OK</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-5">
                            <i class="bi bi-calendar-x fs-3 d-block mb-2"></i>
                            No attendance in this range.
                            @can('manage-attendance')
                                If no BioTime source is set up yet, add one under
                                <a href="{{ route('admin.attendance.sources.index') }}">BioTime Sources</a>.
                            @endcan
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($days->hasPages())
    <div class="mt-3">{{ $days->links() }}</div>
@endif
@endsection
