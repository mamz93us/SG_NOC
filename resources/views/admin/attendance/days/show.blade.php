@extends('layouts.admin')

@section('title', 'Attendance — '.$day->work_date->format('d M Y'))

@section('content')
@include('admin.attendance._tabs')

@php
    $canAdjust = $day->employee_id && ! $day->locked && auth()->user()?->can('manage-attendance');
    $inEdited = $day->checkInEdited();
    $outEdited = $day->checkOutEdited();
    $defaultIn = $day->first_in?->format('Y-m-d\TH:i')
        ?? $day->scheduled_start?->format('Y-m-d\TH:i')
        ?? $day->work_date->format('Y-m-d').'T09:00';
    $defaultOut = $day->last_out?->format('Y-m-d\TH:i')
        ?? $day->scheduled_end?->format('Y-m-d\TH:i')
        ?? $day->work_date->format('Y-m-d').'T17:00';
@endphp

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <a href="{{ route('admin.attendance.days.index', ['from' => $day->work_date->toDateString(), 'to' => $day->work_date->toDateString()]) }}"
           class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>All people on {{ $day->work_date->format('d M Y') }}</a>
        <h4 class="mb-0 mt-1 fw-bold">
            @if ($day->employee)
                {{ $day->employee->name }}
            @else
                <span class="text-danger">Unmapped BioTime code {{ $day->emp_codes }}</span>
            @endif
            <span class="badge {{ $day->status === 'absent' ? 'bg-danger' : ($day->status === 'excused' ? 'bg-info text-dark' : 'bg-secondary') }} fs-6 align-middle ms-1">{{ $day->statusLabel() }}</span>
        </h4>
        <small class="text-muted">
            {{ $day->work_date->format('l d F Y') }}
            @if ($day->emp_codes)
                · BioTime code {{ $day->emp_codes }}
            @endif
            @if ($day->employee?->oracle_emp_no)
                · Oracle {{ $day->employee->oracle_emp_no }}
            @endif
            @if ($day->branch)
                · {{ $day->branch->name }}
            @endif
            @if ($day->employee?->department)
                · {{ $day->employee->department->name }}
            @endif
        </small>
    </div>
    @if (! $day->employee && $day->biotimeEmployee)
        <a href="{{ route('admin.attendance.employees.index', ['status' => 'all', 'q' => $day->biotimeEmployee->emp_code]) }}"
           class="btn btn-sm btn-outline-primary"><i class="bi bi-link-45deg me-1"></i>Link this code to an employee</a>
    @endif
</div>

@if ($day->locked)
    <div class="alert alert-primary small d-flex gap-2">
        <i class="bi bi-lock-fill"></i>
        <div>
            This day is in an approved period
            @if ($day->period)
                — <a href="{{ route('admin.attendance.periods.show', $day->period) }}" class="alert-link">{{ $day->period->name }}</a>
            @endif
            — and is locked. Reopen the period to change it.
        </div>
    </div>
@endif

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                @if ($day->shift)
                    <div class="fs-5 fw-bold">{{ $day->shift->name }}</div>
                    <div class="small text-muted font-monospace">
                        Due {{ $day->scheduled_start?->format('H:i') }}–{{ $day->scheduled_end?->format('H:i') }}
                    </div>
                @else
                    <div class="fs-5 fw-bold text-muted">No shift</div>
                    <div class="small text-muted">Late, early leave and absence need one — see Shifts.</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold font-monospace">{{ $day->first_in?->format('H:i:s') ?? '—' }}</div>
                <div class="small text-muted">
                    @if ($inEdited)
                        Check-in <span class="badge bg-warning text-dark ms-1"><i class="bi bi-pencil-fill me-1"></i>Edited by HR</span>
                    @else
                        Check-in (earliest punch)
                    @endif
                </div>
                @if ($day->late_minutes > 0)
                    <div class="small text-danger">{{ \App\Models\Attendance\AttendanceDay::minutesLabel($day->late_minutes) }} late</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold font-monospace">{{ $day->last_out?->format('H:i:s') ?? '—' }}</div>
                <div class="small text-muted">
                    @if ($outEdited)
                        Check-out <span class="badge bg-warning text-dark ms-1"><i class="bi bi-pencil-fill me-1"></i>Edited by HR</span>
                    @else
                        Check-out (latest punch)
                    @endif
                    @if ($day->last_out && ! $day->last_out->isSameDay($day->work_date))
                        · {{ $day->last_out->format('d M') }}
                    @endif
                </div>
                @if ($day->early_leave_minutes > 0)
                    <div class="small text-danger">{{ \App\Models\Attendance\AttendanceDay::minutesLabel($day->early_leave_minutes) }} early</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="fs-4 fw-bold font-monospace">{{ $day->workedLabel() }}</div>
                <div class="small text-muted">Worked · {{ $day->punch_count }} punch(es)</div>
                @if ($day->overtime_minutes > 0)
                    <div class="small text-primary">{{ \App\Models\Attendance\AttendanceDay::minutesLabel($day->overtime_minutes) }} overtime</div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="mb-3">
    @forelse ($day->flagBadges() as $badge)
        <span class="badge {{ $badge['error'] ? 'bg-danger' : 'bg-warning text-dark' }} me-1">{{ $badge['label'] }}</span>
    @empty
        <span class="badge bg-success-subtle text-success border border-success-subtle">No problems found</span>
    @endforelse
    <span class="small text-muted ms-2">Computed {{ $day->computed_at?->diffForHumans() }}</span>
</div>

<div class="row g-3">
    <div class="{{ $canAdjust ? 'col-lg-7' : 'col-12' }}">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent fw-semibold">
                Day log
                <span class="small text-muted fw-normal">· punches from BioTime and edits by HR</span>
                @if ($day->window_start && ! $day->window_start->isSameDay($day->window_end->copy()->subSecond()))
                    <span class="small text-muted fw-normal">
                        · {{ $day->window_start->format('d M H:i') }} to {{ $day->window_end->format('d M H:i') }} (overnight shift)
                    </span>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:110px">Time</th>
                            <th style="width:160px">Used as</th>
                            <th>Source</th>
                            <th>Terminal / reason</th>
                            <th>Area / by</th>
                            <th class="text-end">BioTime id</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($log as $entry)
                            @php $time = $entry['time']; @endphp
                            @if ($entry['edit'])
                                @php $edit = $entry['edit']; @endphp
                                <tr class="table-warning">
                                    <td class="font-monospace">
                                        {{ $time->format('H:i:s') }}
                                        @unless ($time->isSameDay($day->work_date))
                                            <div class="small text-muted">{{ $time->format('d M') }}</div>
                                        @endunless
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">{{ $entry['role'] === 'in' ? 'Check-in' : 'Check-out' }}</span>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-pencil-fill me-1"></i>Edited</span>
                                    </td>
                                    <td class="small">
                                        <span class="fw-semibold">Manual edit by HR</span>
                                        <div class="text-muted">{{ $entry['replaces'] ? 'Was '.$entry['replaces'].' from the punches' : 'Was missing' }}</div>
                                    </td>
                                    <td class="small">{{ $edit->reason }}</td>
                                    <td class="small">
                                        {{ $edit->createdBy?->name ?? 'Unknown' }}
                                        <div class="text-muted">{{ $edit->created_at?->format('d M Y H:i') }}</div>
                                    </td>
                                    <td class="text-end small text-muted">—</td>
                                </tr>
                            @else
                                @php $punch = $entry['punch']; @endphp
                                <tr class="{{ $entry['duplicate'] ? 'text-muted' : '' }}">
                                    <td class="font-monospace">
                                        {{ $time->format('H:i:s') }}
                                        @unless ($time->isSameDay($day->work_date))
                                            <div class="small text-muted">{{ $time->format('d M') }}</div>
                                        @endunless
                                    </td>
                                    <td>
                                        @if ($entry['role'] === 'in')
                                            <span class="badge bg-primary">Check-in</span>
                                        @elseif ($entry['role'] === 'out')
                                            <span class="badge bg-primary">Check-out</span>
                                        @elseif ($entry['duplicate'])
                                            <span class="badge bg-secondary">Duplicate</span>
                                        @else
                                            <span class="small text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        {{ $punch->stateLabel() }}
                                        @if ($punch->punch_state !== null)
                                            <span class="text-muted font-monospace">({{ $punch->punch_state }})</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        {{ $punch->terminal_alias ?: '—' }}
                                        @if ($punch->terminal_sn)
                                            <div class="text-muted font-monospace">{{ $punch->terminal_sn }}</div>
                                        @endif
                                    </td>
                                    <td class="small">
                                        {{ $punch->area_alias ?: '—' }}
                                        <div class="text-muted">{{ $punch->source?->name }}</div>
                                    </td>
                                    <td class="text-end small font-monospace text-muted">{{ $punch->biotime_id }}</td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No punches on this day.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <p class="small text-muted mt-2 mb-0">
            BioTime's state column is shown for reference only — staff rarely press the in/out key, so it does not decide check-in or check-out.
            An HR edit replaces that one time on the day; the punches themselves are never changed.
        </p>
    </div>

    @if ($canAdjust)
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent fw-semibold"><i class="bi bi-pencil-square me-1"></i>Correct this day</div>
                <div class="card-body">
                    @foreach ([
                        'check_in' => ['label' => 'check-in', 'default' => $defaultIn, 'edited' => $inEdited, 'example' => 'forgot to punch in, manager confirmed 08:30'],
                        'check_out' => ['label' => 'check-out', 'default' => $defaultOut, 'edited' => $outEdited, 'example' => 'forgot to punch out, left at 17:30'],
                    ] as $kind => $side)
                        <form method="POST" action="{{ route('admin.attendance.days.adjust', $day) }}" class="{{ $loop->first ? '' : 'mt-3 pt-3 border-top' }}">
                            @csrf
                            <input type="hidden" name="action" value="{{ $kind }}">
                            <label class="form-label small fw-semibold mb-1" for="edit-{{ $kind }}">
                                Edit {{ $side['label'] }}
                                @if ($side['edited'])
                                    <span class="badge bg-warning text-dark ms-1">edited</span>
                                @endif
                            </label>
                            <input type="datetime-local" id="edit-{{ $kind }}" name="time" class="form-control form-control-sm" required
                                   value="{{ old('action') === $kind ? old('time') : $side['default'] }}">
                            <textarea name="reason" rows="2" class="form-control form-control-sm mt-2" required maxlength="1000"
                                      placeholder="Why the {{ $side['label'] }} changes — e.g. {{ $side['example'] }}">{{ old('action') === $kind ? old('reason') : '' }}</textarea>
                            <button class="btn btn-sm btn-primary mt-2">Save {{ $side['label'] }}</button>
                        </form>
                    @endforeach
                    <div class="form-text">Each edit changes only its own time and keeps its own reason. The punches are not changed.</div>

                    <hr>

                    <form method="POST" action="{{ route('admin.attendance.days.adjust', $day) }}">
                        @csrf
                        <input type="hidden" name="action" value="excuse">
                        <div class="row g-2">
                            <div class="col-sm-5">
                                <select name="excuse" class="form-select form-select-sm" required>
                                    @foreach ($excuses as $key => $label)
                                        <option value="{{ $key }}" @selected(old('excuse') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-7">
                                <input name="reason" class="form-control form-control-sm" required maxlength="1000"
                                       placeholder="Reason / reference" value="{{ old('action') === 'excuse' ? old('reason') : '' }}">
                            </div>
                        </div>
                        <div class="form-text">Excuses the whole day: absence, lateness, early leave and a missing check-out stop counting.</div>
                        <button class="btn btn-sm btn-outline-primary mt-2">Excuse the day</button>
                    </form>
                </div>
            </div>

            @if ($adjustments->isNotEmpty())
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-transparent fw-semibold">History</div>
                    <ul class="list-group list-group-flush">
                        @foreach ($adjustments as $adjustment)
                            <li class="list-group-item small {{ $adjustment->isActive() ? '' : 'text-muted' }}">
                                <div class="d-flex justify-content-between gap-2">
                                    <div>
                                        <span class="fw-semibold">{{ $adjustment->summary() }}</span>
                                        @if ($adjustment->isActive())
                                            <span class="badge bg-success ms-1">active</span>
                                        @else
                                            <span class="badge bg-secondary ms-1">revoked</span>
                                        @endif
                                        <div>{{ $adjustment->reason }}</div>
                                        <div class="text-muted">
                                            {{ $adjustment->createdBy?->name ?? 'Unknown' }}, {{ $adjustment->created_at?->format('d M Y H:i') }}
                                            @if ($adjustment->revoked_at)
                                                · revoked by {{ $adjustment->revokedBy?->name ?? 'Unknown' }}, {{ $adjustment->revoked_at->format('d M Y H:i') }}
                                            @endif
                                        </div>
                                    </div>
                                    @if ($adjustment->isActive())
                                        <form method="POST" action="{{ route('admin.attendance.adjustments.revoke', $adjustment) }}"
                                              onsubmit="return confirm('Revoke this edit? Only this change is undone.')">
                                            @csrf
                                            <button class="btn btn-sm btn-link text-danger p-0">Revoke</button>
                                        </form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
