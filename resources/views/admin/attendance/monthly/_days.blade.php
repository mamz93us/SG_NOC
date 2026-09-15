{{--
    Every date of a month, one row each: shift, check-in, check-out, worked, late,
    early leave, overtime, punches and status. Shared by the Monthly sheet and the
    Employee profile, so the two can never show a day differently.

    $days         list<\App\Services\Attendance\MonthlyDay>
    $leaveByDate  optional: Y-m-d => Oracle leave records covering that date
--}}
@php
    $leaveByDate = $leaveByDate ?? [];
@endphp
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:90px">Date</th>
                    <th style="width:120px">Shift</th>
                    <th class="text-center" style="width:90px">Check-in</th>
                    <th class="text-center" style="width:90px">Check-out</th>
                    <th class="text-center" style="width:70px">Worked</th>
                    <th class="text-center" style="width:70px">Late</th>
                    <th class="text-center" style="width:70px">Early</th>
                    <th class="text-center" style="width:70px">Overtime</th>
                    <th>Punches</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($days as $day)
                    @php
                        $row = $day->day;
                        $date = \Carbon\CarbonImmutable::parse($day->date);
                        $rest = in_array($day->kind, [\App\Services\Attendance\MonthlyDay::KIND_OFF, \App\Services\Attendance\MonthlyDay::KIND_HOLIDAY], true);
                        $outside = in_array($day->kind, [\App\Services\Attendance\MonthlyDay::KIND_BEFORE_HIRE, \App\Services\Attendance\MonthlyDay::KIND_AFTER_TERMINATION], true);
                        $link = $row ? route('admin.attendance.days.show', $row) : null;
                        $leave = $leaveByDate[$day->date] ?? [];
                    @endphp
                    <tr class="{{ $rest || $outside || $day->future ? 'table-light' : '' }}"
                        @if ($link) style="cursor:pointer" onclick="window.location='{{ $link }}'" @endif>
                        <td class="small text-nowrap">
                            <span class="{{ $rest ? 'text-muted' : 'fw-semibold' }}">{{ $date->format('D d') }}</span>
                            @if ($rest || $outside)
                                <div class="text-muted">{{ $day->kindLabel() }}</div>
                            @endif
                        </td>
                        <td class="small">
                            @if ($day->shift)
                                {{ $day->shift->name }}
                                <div class="text-muted font-monospace">{{ $day->shift->start }}–{{ $day->shift->end }}</div>
                            @else
                                <span class="text-muted">No shift</span>
                            @endif
                        </td>

                        @if (! $row)
                            <td colspan="6" class="text-center small text-muted">{{ $day->emptyLabel() }}</td>
                        @else
                            <td class="text-center">
                                <span class="font-monospace">{{ $row->first_in?->format('H:i') ?? '—' }}</span>
                                @if ($row->checkInEdited())
                                    <i class="bi bi-pencil-fill small text-warning-emphasis" title="Check-in edited by HR"></i>
                                @endif
                                @if ($row->late_minutes > 0)
                                    <div class="small text-danger">{{ \App\Models\Attendance\AttendanceDay::minutesLabel($row->late_minutes) }} late</div>
                                @endif
                            </td>
                            <td class="text-center">
                                @if ($row->last_out)
                                    <span class="font-monospace">{{ $row->last_out->format('H:i') }}</span>
                                    @if ($row->checkOutEdited())
                                        <i class="bi bi-pencil-fill small text-warning-emphasis" title="Check-out edited by HR"></i>
                                    @endif
                                    @unless ($row->last_out->isSameDay($row->work_date))
                                        <div class="small text-muted">next day</div>
                                    @endunless
                                @elseif ($row->first_in)
                                    <span class="text-danger">missing</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center font-monospace small">{{ $row->workedLabel() }}</td>
                            <td class="text-center font-monospace small {{ $row->late_minutes > 0 ? 'text-danger' : 'text-muted' }}">
                                {{ $row->late_minutes > 0 ? $row->late_minutes.'m' : '—' }}
                            </td>
                            <td class="text-center font-monospace small {{ $row->early_leave_minutes > 0 ? 'text-danger' : 'text-muted' }}">
                                {{ $row->early_leave_minutes > 0 ? $row->early_leave_minutes.'m' : '—' }}
                            </td>
                            <td class="text-center font-monospace small {{ $row->overtime_minutes > 0 ? 'text-primary' : 'text-muted' }}">
                                {{ $row->overtime_minutes > 0 ? \App\Models\Attendance\AttendanceDay::hoursLabel($row->overtime_minutes) : '—' }}
                            </td>
                        @endif

                        <td class="small font-monospace">
                            @forelse ($day->punches as $punch)
                                <span class="badge bg-light text-body border me-1 fw-normal"
                                      title="{{ $punch->punch_time->format('D d M H:i:s') }} · {{ $punch->terminal_alias ?: 'unknown terminal' }} · {{ $punch->area_alias ?: 'no area' }}">
                                    {{ $punch->punch_time->format('H:i') }}@unless ($punch->punch_time->isSameDay($date))<span class="text-muted">+1</span>@endunless
                                </span>
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                        </td>
                        <td>
                            @foreach ($leave as $record)
                                <span class="badge {{ \App\Models\Vacation\VacationAbsence::typeBadgeClass($record->absence_type) }}"
                                      title="Oracle: {{ $record->absence_type }}, {{ $record->start_date->format('d M') }} – {{ $record->end_date->format('d M Y') }}">
                                    <i class="bi {{ $record->isBusinessTrip() ? 'bi-briefcase' : 'bi-airplane' }} me-1"></i>{{ $record->absence_type }}
                                </span>
                            @endforeach
                            @if ($row)
                                @forelse ($row->flagBadges() as $badge)
                                    <span class="badge {{ $badge['error'] ? 'bg-danger' : 'bg-warning text-dark' }}">{{ $badge['label'] }}</span>
                                @empty
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">{{ $row->statusLabel() }}</span>
                                @endforelse
                            @elseif ($leave === [])
                                <span class="small text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
