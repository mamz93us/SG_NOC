@extends('layouts.admin')

@section('title', $employee->name.' — Employee profile')

@section('content')
@php
    // Not $days: the controller's $days is the month, day by day, and the sheet below needs it.
    $figure = fn ($value) => \App\Models\Vacation\VacationBalance::days($value === null ? null : (float) $value);
    $monthUrl = fn (string $m) => route('admin.people.show', ['employee' => $employee, 'month' => $m]);
    $oracle = $people->first();
    $total = $yearStats?->total;
    $thisMonth = $today->format('Y-m');

    $tiles = [];
    if ($canVacations) {
        $has = $balance && $balance->hasBalance();
        $tiles[] = [
            'label' => 'Vacation left',
            'value' => $has ? $figure($balance->balance) : '—',
            'note' => $has ? 'Oracle, as of '.$balance->as_of->format('d M Y') : ($balance ? 'No leave plan in Oracle yet' : 'No Oracle balance for '.$year),
            'alert' => $has && $balance->balance < 0 ? 'Negative balance' : null,
        ];
        $tiles[] = [
            'label' => 'Leave used',
            'value' => $has ? $figure($balance->used) : '—',
            'note' => $has ? $figure($balance->accrued).' earned this year so far' : null,
        ];
    }
    if ($total) {
        $rate = $total->attendanceRate();
        $tiles[] = ['label' => 'Attendance rate', 'value' => $rate === null ? '—' : round($rate * 100).'%', 'note' => $total->attendance->presentDays.' present · '.$total->absentDays.' absent'];
        $tiles[] = ['label' => 'Absences', 'value' => $total->absentDays, 'note' => $total->absentOnLeaveDays > 0 ? '+'.$total->absentOnLeaveDays.' more that Oracle has as leave or a trip' : 'nothing on record explains them'];
        $tiles[] = ['label' => 'Late arrivals', 'value' => $total->attendance->lateDays, 'note' => \App\Models\Attendance\AttendanceDay::minutesLabel($total->attendance->lateMinutes).' late in total'];
        $tiles[] = ['label' => 'Hours worked', 'value' => $total->attendance->workedLabel(), 'note' => $total->attendance->overtimeLabel().' overtime'];
    }

    $chart = $yearStats ? [
        'labels' => array_map(fn ($key) => \Carbon\CarbonImmutable::parse($key.'-01')->format('M'), array_keys($yearStats->months)),
        'months' => array_map(fn ($key) => \Carbon\CarbonImmutable::parse($key.'-01')->format('F Y'), array_keys($yearStats->months)),
        'series' => [
            ['label' => 'Present', 'values' => array_values(array_map(fn ($m) => $m->attendance->presentDays, $yearStats->months))],
            ['label' => 'Away — leave, trip or excused', 'values' => array_values(array_map(fn ($m) => $m->awayDays, $yearStats->months))],
            ['label' => 'Absent', 'values' => array_values(array_map(fn ($m) => $m->absentDays, $yearStats->months))],
        ],
    ] : null;

    $byType = $records->groupBy('absence_type')
        ->map(fn ($group, $type) => (object) ['type' => $type, 'trip' => \App\Models\Vacation\VacationAbsence::isTripType($type), 'records' => $group->count(), 'days' => $group->sum(fn ($r) => $r->days())])
        ->sortBy(fn ($row) => [$row->trip ? 1 : 0, -$row->days])
        ->values();
@endphp

<div class="mb-2">
    <a href="{{ route('admin.people.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>All employees</a>
</div>

{{-- ── Who ──────────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
        <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold flex-shrink-0"
             style="width:56px;height:56px;font-size:1.25rem">{{ $employee->initials() }}</div>
        <div class="flex-grow-1">
            <h4 class="mb-0 fw-bold">
                {{ $employee->name }}
                <span class="badge {{ $employee->statusBadgeClass() }} fs-6 align-middle">{{ ucfirst(str_replace('_', ' ', (string) $employee->status)) }}</span>
                @foreach ($awayToday as $record)
                    <span class="badge {{ \App\Models\Vacation\VacationAbsence::typeBadgeClass($record->absence_type) }} fs-6 align-middle">
                        <i class="bi {{ $record->isBusinessTrip() ? 'bi-briefcase' : 'bi-airplane' }} me-1"></i>{{ $record->absence_type }} today
                    </span>
                @endforeach
            </h4>
            @if ($employee->name_ar)
                <div class="text-muted" dir="rtl" lang="ar">{{ $employee->name_ar }}</div>
            @endif
            <div class="text-muted">{{ collect([$employee->job_title, $employee->department?->name, $employee->branch?->name])->filter()->implode(' · ') ?: '—' }}</div>
            <div class="small text-muted mt-1">
                {{ collect([
                    $employee->oracle_emp_no ? 'Oracle no. '.$employee->oracle_emp_no : 'No Oracle no.',
                    $employee->oracle_employee_category,
                    $canAttendance ? ($codes ? 'BioTime code '.$codes : 'No BioTime code') : null,
                    $employee->manager ? 'Manager: '.$employee->manager->name : null,
                    $employee->hired_date ? 'Hired '.$employee->hired_date->format('d M Y').' ('.$employee->hired_date->diffForHumans($today, ['parts' => 2, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]).')' : null,
                ])->filter()->implode(' · ') }}
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @can('view-employees')
                <a href="{{ route('admin.employees.show', $employee->id) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-person-vcard me-1"></i>IT profile</a>
            @endcan
            @if ($oracle)
                <a href="{{ route('admin.vacations.balances.show', ['vacationEmployee' => $oracle, 'year' => $year]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-wallet2 me-1"></i>Vacation page</a>
            @endif
        </div>
    </div>
</div>

{{-- ── The year at a glance ─────────────────────────────────── --}}
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h5 class="mb-0 fw-semibold">{{ $year }} at a glance</h5>
    <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary" href="{{ $monthUrl(($year - 1).'-12') }}"><i class="bi bi-chevron-left"></i> {{ $year - 1 }}</a>
        <span class="btn btn-light disabled fw-semibold">{{ $year }}</span>
        @if ($year < $today->year)
            <a class="btn btn-outline-secondary" href="{{ $monthUrl($year + 1 === $today->year ? $thisMonth : ($year + 1).'-12') }}">{{ $year + 1 }} <i class="bi bi-chevron-right"></i></a>
        @endif
    </div>
</div>

<div class="row g-3 mb-3">
    @foreach ($tiles as $tile)
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body py-3">
                    <div class="small text-muted">{{ $tile['label'] }}</div>
                    <div class="fs-3 fw-semibold">{{ $tile['value'] }}</div>
                    @if (! empty($tile['alert']))
                        <div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $tile['alert'] }}</div>
                    @endif
                    @if (! empty($tile['note']))
                        <div class="small text-muted">{{ $tile['note'] }}</div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>

@if ($yearStats && $yearStats->absentOnLeave !== [])
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>{{ count($yearStats->absentOnLeave) }} {{ \Illuminate\Support\Str::plural('day', count($yearStats->absentOnLeave)) }} recorded absent that Oracle has as leave or a business trip.</strong>
        They still count as absences until they are excused on their day page.
        <div class="mt-2 d-flex flex-wrap gap-1">
            @foreach (array_slice($yearStats->absentOnLeave, 0, 40) as $item)
                <a href="{{ $item['day_id'] ? route('admin.attendance.days.show', $item['day_id']) : '#' }}" class="badge bg-light text-dark border text-decoration-none fw-normal">
                    {{ \Carbon\CarbonImmutable::parse($item['date'])->format('D d M') }} · {{ $item['type'] }}
                </a>
            @endforeach
            @if (count($yearStats->absentOnLeave) > 40)
                <span class="small">and {{ count($yearStats->absentOnLeave) - 40 }} more</span>
            @endif
        </div>
    </div>
@endif
@if ($yearStats && $yearStats->presentOnLeave !== [])
    <div class="alert alert-info small">
        <i class="bi bi-info-circle me-1"></i>
        Punched in on {{ count($yearStats->presentOnLeave) }} {{ \Illuminate\Support\Str::plural('day', count($yearStats->presentOnLeave)) }} that Oracle has as leave:
        {{ collect($yearStats->presentOnLeave)->take(20)->map(fn ($item) => \Carbon\CarbonImmutable::parse($item['date'])->format('d M').' ('.$item['type'].')')->implode(', ') }}{{ count($yearStats->presentOnLeave) > 20 ? '…' : '' }}.
        Either the leave was cut short, or the leave in Oracle needs correcting.
    </div>
@endif

<div class="row g-3 mb-4">
    @if ($yearStats)
        <div class="{{ $canVacations ? 'col-xl-8' : 'col-12' }}">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-transparent">
                    <strong><i class="bi bi-bar-chart me-1"></i>{{ $year }} month by month</strong>
                    <div class="small text-muted">
                        Work days that have ended: present, away (on Oracle leave, on a business trip, or excused) or absent with
                        nothing to explain it. The number above a month is its absences.
                    </div>
                </div>
                <div class="card-body pb-0">
                    <div style="position:relative;height:260px">
                        <canvas id="profile-year-chart" role="img"
                                aria-label="Work days per month in {{ $year }}: present, away and absent. The table below lists every value."></canvas>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small" style="font-variant-numeric:tabular-nums">
                        <thead class="table-light">
                            <tr>
                                <th>Month</th>
                                <th class="text-end">Work days</th>
                                <th class="text-end">Present</th>
                                <th class="text-end" title="On Oracle leave, on a business trip, or excused">Away</th>
                                <th class="text-end" title="Absent with nothing to explain it; +N were absent on Oracle leave or a trip">Absent</th>
                                <th class="text-end">Late</th>
                                <th class="text-end">Early leave</th>
                                <th class="text-end">Worked</th>
                                <th class="text-end">Overtime</th>
                                <th class="text-end">No check-out</th>
                                @if ($canVacations)
                                    <th class="text-end" title="Work days covered by Oracle leave, booked days ahead included">Leave days</th>
                                    <th class="text-end" title="Work days covered by a business trip">Trip days</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_merge($yearStats->months, ['total' => $yearStats->total]) as $key => $m)
                                @php
                                    $a = $m->attendance;
                                    $isTotal = $key === 'total';
                                    $ahead = ! $isTotal && $key > $thisMonth;
                                @endphp
                                <tr class="{{ $isTotal ? 'table-light fw-semibold' : ($key === $month ? 'table-active' : '') }} {{ $ahead ? 'text-muted' : '' }}">
                                    <td>
                                        @if ($isTotal)
                                            {{ $year }}
                                        @else
                                            <a href="{{ $monthUrl($key) }}#month" class="text-decoration-none">{{ \Carbon\CarbonImmutable::parse($key.'-01')->format('F') }}</a>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $a->workDays }}</td>
                                    <td class="text-end">{{ $a->presentDays }}</td>
                                    <td class="text-end">{{ $m->awayDays }}</td>
                                    <td class="text-end">
                                        <span class="{{ $m->absentDays > 0 ? 'text-danger' : '' }}">{{ $m->absentDays }}</span>
                                        @if ($m->absentOnLeaveDays > 0)
                                            <span class="text-warning-emphasis" title="Recorded absent, but Oracle has leave or a trip">+{{ $m->absentOnLeaveDays }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $a->lateDays }}</td>
                                    <td class="text-end">{{ $a->earlyLeaveDays }}</td>
                                    <td class="text-end">{{ $a->workedLabel() }}</td>
                                    <td class="text-end">{{ $a->overtimeLabel() }}</td>
                                    <td class="text-end {{ $a->missingCheckOuts > 0 ? 'text-danger' : '' }}">{{ $a->missingCheckOuts }}</td>
                                    @if ($canVacations)
                                        <td class="text-end">{{ $m->leaveDays }}</td>
                                        <td class="text-end">{{ $m->tripDays }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-transparent small text-muted">
                    Leave and trip days are counted on this person's work days, so a holiday or day off inside a leave is not
                    a leave day. Worked hours are check-in to check-out: a day with no check-out adds nothing.
                </div>
            </div>
        </div>
    @endif

    @if ($canVacations)
        <div class="{{ $yearStats ? 'col-xl-4' : 'col-12' }}">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-wallet2 me-1"></i>Vacation {{ $year }}</strong>
                    @if ($balance)
                        <span class="badge {{ $balance->isStale() ? 'bg-warning text-dark' : 'bg-light text-dark border' }}">Oracle, as of {{ $balance->as_of->format('d M Y') }}</span>
                    @endif
                </div>
                <div class="card-body">
                    @if ($people->isEmpty())
                        <div class="text-muted">
                            No Oracle vacation data is linked to this person.
                            @can('manage-vacations')
                                People Oracle lists but the NOC could not match wait under
                                <a href="{{ route('admin.vacations.balances.index', ['show' => 'unlinked']) }}">Vacations ▸ Not linked</a>.
                            @endcan
                        </div>
                    @else
                        @include('admin.vacations.balances._balance', ['balance' => $balance, 'year' => $year, 'compact' => true])

                        <div class="small fw-semibold mt-4 mb-2">Leave records in {{ $year }}, by type</div>
                        <ul class="list-group list-group-flush small">
                            @forelse ($byType as $row)
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-center {{ $row->trip ? 'text-muted' : '' }}">
                                    <span>
                                        <span class="badge {{ \App\Models\Vacation\VacationAbsence::typeBadgeClass($row->type) }} me-1">{{ $row->type }}</span>
                                        {{ $row->records }} {{ \Illuminate\Support\Str::plural('record', $row->records) }}
                                    </span>
                                    <span class="fw-semibold">{{ $figure($row->days) }} days</span>
                                </li>
                            @empty
                                <li class="list-group-item px-0 text-muted">No leave records in {{ $year }}.</li>
                            @endforelse
                        </ul>

                        @if ($nextLeave)
                            <div class="small mt-3">
                                <i class="bi bi-calendar-event me-1"></i>Next leave:
                                <strong>{{ $nextLeave->absence_type }}</strong>,
                                {{ $nextLeave->start_date->format('D d M') }} – {{ $nextLeave->end_date->format('D d M Y') }}
                                ({{ $figure($nextLeave->days()) }} days)
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>

{{-- ── The month, day by day ────────────────────────────────── --}}
@if ($canAttendance && $monthTotals)
    <div id="month" class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h5 class="mb-0 fw-semibold">Attendance, {{ $monthStart->format('F Y') }}</h5>
        <div class="d-flex gap-2">
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary" href="{{ $monthUrl($monthStart->subMonth()->format('Y-m')) }}#month"><i class="bi bi-chevron-left"></i></a>
                <span class="btn btn-light disabled fw-semibold">{{ $monthStart->format('M Y') }}</span>
                <a class="btn btn-outline-secondary" href="{{ $monthUrl($monthStart->addMonth()->format('Y-m')) }}#month"><i class="bi bi-chevron-right"></i></a>
            </div>
            <a href="{{ route('admin.attendance.monthly.export', ['employee' => $employee, 'month' => $month]) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
    </div>

    <div class="row g-2 mb-3">
        @foreach ([
            ['Work days', $monthTotals->workDays, $monthTotals->offDays.' off · '.$monthTotals->holidayDays.' holiday'],
            ['Present', $monthTotals->presentDays, $monthTotals->excusedDays > 0 ? $monthTotals->excusedDays.' excused' : null],
            ['Absent', $monthTotals->absentDays, $monthTotals->unrecordedDays > 0 ? $monthTotals->unrecordedDays.' not recorded' : null],
            ['Worked', $monthTotals->workedLabel(), $monthTotals->overtimeLabel().' overtime'],
            ['Late', $monthTotals->lateDays, \App\Models\Attendance\AttendanceDay::minutesLabel($monthTotals->lateMinutes).' in total'],
            ['Early leave', $monthTotals->earlyLeaveDays, \App\Models\Attendance\AttendanceDay::minutesLabel($monthTotals->earlyLeaveMinutes).' in total'],
            ['Missing check-out', $monthTotals->missingCheckOuts, 'hours not counted'],
        ] as [$label, $value, $note])
            <div class="col-6 col-md-3 col-xl">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body py-2">
                        <div class="small text-muted">{{ $label }}</div>
                        <div class="fs-5 fw-semibold">{{ $value }}</div>
                        @if ($note)
                            <div class="small text-muted">{{ $note }}</div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @include('admin.attendance.monthly._days', ['days' => $days, 'leaveByDate' => $canVacations ? $leaveByDate : []])
@endif

{{-- ── Leave records ────────────────────────────────────────── --}}
@if ($canVacations && $people->isNotEmpty())
    <div class="card shadow-sm border-0 mt-4">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <strong><i class="bi bi-calendar-range me-1"></i>Leave records in {{ $year }}</strong>
            @if ($withdrawnCount > 0 && $oracle)
                <a class="small text-decoration-none" href="{{ route('admin.vacations.balances.show', ['vacationEmployee' => $oracle, 'year' => $year, 'withdrawn' => 1]) }}">
                    {{ $withdrawnCount }} no longer in Oracle
                </a>
            @endif
        </div>
        @include('admin.vacations.balances._records', ['records' => $records, 'today' => $today, 'year' => $year])
    </div>
@endif

@if ($chart)
    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
        <script>
        (function () {
            var canvas = document.getElementById('profile-year-chart');
            if (!canvas || typeof Chart === 'undefined') {
                return;
            }

            var data = @json($chart);

            // Categorical slots 1-3, validated all-pairs in both modes against this layout's card surfaces.
            var themes = {
                light: { surface: '#ffffff', series: ['#2a78d6', '#1baf7a', '#eb6834'], text: '#52514e', muted: '#898781', grid: '#e1e0d9', axis: '#c3c2b7' },
                dark: { surface: '#212529', series: ['#3987e5', '#199e70', '#d95926'], text: '#c3c2b7', muted: '#898781', grid: '#373b3e', axis: '#495057' }
            };
            var theme = function () {
                return themes[document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light'];
            };

            // Only the top non-empty segment of a month gets the rounded end.
            var isTop = function (ctx) {
                var sets = ctx.chart.data.datasets;
                for (var i = sets.length - 1; i >= 0; i--) {
                    if ((sets[i].data[ctx.dataIndex] || 0) > 0) {
                        return i === ctx.datasetIndex;
                    }
                }
                return false;
            };

            // The absences are the story: their count sits above each month that has any.
            var absentLabels = {
                id: 'absentLabels',
                afterDatasetsDraw: function (chart) {
                    var meta = chart.getDatasetMeta(2);
                    if (!meta || meta.hidden) {
                        return;
                    }
                    var ctx = chart.ctx;
                    ctx.save();
                    ctx.fillStyle = theme().text;
                    ctx.font = '600 11px system-ui, -apple-system, "Segoe UI", sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    meta.data.forEach(function (bar, i) {
                        var value = chart.data.datasets[2].data[i];
                        if (value > 0) {
                            ctx.fillText(String(value), bar.x, bar.y - 3);
                        }
                    });
                    ctx.restore();
                }
            };

            var chart = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: data.series.map(function (series, i) {
                        return {
                            label: series.label,
                            data: series.values,
                            stack: 'days',
                            maxBarThickness: 24,
                            borderSkipped: false,
                            borderRadius: function (ctx) {
                                return isTop(ctx) ? { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 } : 0;
                            },
                            // A 2px surface gap where a segment sits on another.
                            borderWidth: { top: 0, right: 0, left: 0, bottom: i > 0 ? 2 : 0 }
                        };
                    })
                },
                plugins: [absentLabels],
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    interaction: { mode: 'index', intersect: false },
                    layout: { padding: { top: 18 } },
                    plugins: {
                        legend: { position: 'top', align: 'start', labels: { boxWidth: 12, boxHeight: 12, useBorderRadius: true, borderRadius: 2 } },
                        tooltip: {
                            boxWidth: 12,
                            boxHeight: 2,
                            callbacks: {
                                title: function (items) { return data.months[items[0].dataIndex]; },
                                label: function (item) { return ' ' + item.parsed.y + (item.parsed.y === 1 ? ' day  ' : ' days  ') + item.dataset.label; }
                            }
                        }
                    },
                    scales: {
                        x: { stacked: true, grid: { display: false }, border: {}, ticks: {} },
                        y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: {}, border: { display: false } }
                    }
                }
            });

            var paint = function () {
                var t = theme();
                chart.data.datasets.forEach(function (set, i) {
                    set.backgroundColor = t.series[i];
                    set.hoverBackgroundColor = t.series[i];
                    set.borderColor = t.surface;
                });
                chart.options.plugins.legend.labels.color = t.text;
                chart.options.scales.x.ticks.color = t.muted;
                chart.options.scales.x.border.color = t.axis;
                chart.options.scales.y.ticks.color = t.muted;
                chart.options.scales.y.grid.color = t.grid;
                chart.update();
            };

            paint();
            new MutationObserver(paint).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
        })();
        </script>
    @endpush
@endif
@endsection
