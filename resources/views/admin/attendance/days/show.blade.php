@extends('layouts.admin')

@section('title', 'Attendance — '.$day->work_date->format('d M Y'))

@section('content')
@include('admin.attendance._tabs')

@php
    $canAdjust = $day->employee_id && ! $day->locked && auth()->user()?->can('manage-attendance');
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
                <div class="small text-muted">Check-in (earliest punch)</div>
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
                    Check-out (latest punch)
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
                Raw punches from BioTime
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
                            <th style="width:120px">Used as</th>
                            <th>BioTime state</th>
                            <th>Terminal</th>
                            <th>Area</th>
                            <th class="text-end">BioTime id</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $firstIn = $day->first_in?->format('Y-m-d H:i:s');
                            $lastOut = $day->last_out?->format('Y-m-d H:i:s');
                            // The check-out is the LAST punch at that time, even inside a burst of repeats.
                            $outIndex = $punches->filter(fn ($p) => $p->punch_time->format('Y-m-d H:i:s') === $lastOut)->keys()->last();
                            $previous = null;
                            $inMarked = false;
                        @endphp
                        @forelse ($punches as $index => $punch)
                            @php
                                $time = $punch->punch_time->format('Y-m-d H:i:s');
                                $isDuplicate = $previous !== null
                                    && $punch->punch_time->getTimestamp() - $previous < \App\Services\Attendance\AttendanceDayBuilder::DUPLICATE_WINDOW_SECONDS;
                                if (! $isDuplicate) {
                                    $previous = $punch->punch_time->getTimestamp();
                                }
                                $role = null;
                                if (! $inMarked && $time === $firstIn) {
                                    $role = 'in';
                                    $inMarked = true;
                                } elseif ($index === $outIndex) {
                                    $role = 'out';
                                }
                            @endphp
                            <tr class="{{ $isDuplicate ? 'text-muted' : '' }}">
                                <td class="font-monospace">
                                    {{ $punch->punch_time->format('H:i:s') }}
                                    @unless ($punch->punch_time->isSameDay($day->work_date))
                                        <div class="small text-muted">{{ $punch->punch_time->format('d M') }}</div>
                                    @endunless
                                </td>
                                <td>
                                    @if ($role === 'in')
                                        <span class="badge bg-primary">Check-in</span>
                                    @elseif ($role === 'out')
                                        <span class="badge bg-primary">Check-out</span>
                                    @elseif ($isDuplicate)
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
        </p>
    </div>

    @if ($canAdjust)
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-transparent fw-semibold"><i class="bi bi-pencil-square me-1"></i>Correct this day</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.attendance.days.adjust', $day) }}">
                        @csrf
                        <input type="hidden" name="action" value="times">
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label small">Check-in</label>
                                <input type="datetime-local" name="check_in" class="form-control form-control-sm"
                                       value="{{ old('action') === 'times' ? old('check_in') : $defaultIn }}">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small">Check-out</label>
                                <input type="datetime-local" name="check_out" class="form-control form-control-sm"
                                       value="{{ old('action') === 'times' ? old('check_out') : $defaultOut }}">
                            </div>
                            <div class="col-12">
                                <textarea name="reason" rows="2" class="form-control form-control-sm" required maxlength="1000"
                                          placeholder="Why — e.g. forgot to punch out, confirmed by manager">{{ old('action') === 'times' ? old('reason') : '' }}</textarea>
                            </div>
                        </div>
                        <div class="form-text">Leave a field empty to keep the device's time. The punches themselves are not changed.</div>
                        <button class="btn btn-sm btn-primary mt-2">Save correction</button>
                    </form>

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
                                              onsubmit="return confirm('Revoke this correction? The day goes back to what the punches say.')">
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
