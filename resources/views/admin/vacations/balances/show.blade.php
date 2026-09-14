@extends('layouts.admin')

@section('title', 'Vacations — '.($person->employee?->name ?? 'Oracle person '.$person->oracle_emp_no))

@section('content')
@include('admin.vacations._tabs')

@php
    $days = fn ($value) => \App\Models\Vacation\VacationBalance::days($value === null ? null : (float) $value);
    $employee = $person->employee;
    $adjustment = $balance?->otherAdjustments();
@endphp

<div class="mb-2">
    <a href="{{ route('admin.vacations.balances.index', ['year' => $year]) }}" class="small text-decoration-none">
        <i class="bi bi-arrow-left me-1"></i>All balances
    </a>
</div>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-badge me-2 text-primary"></i>{{ $employee?->name ?? 'Oracle person '.$person->oracle_emp_no }}
            @if ($employee?->status === 'terminated')
                <span class="badge bg-danger fs-6 align-middle">Left</span>
            @endif
        </h4>
        <small class="text-muted">
            Oracle no. <span class="font-monospace">{{ $person->oracle_emp_no }}</span>
            @if ($person->oracle_person_id)
                · person id <span class="font-monospace">{{ $person->oracle_person_id }}</span>
            @endif
            · {{ \App\Models\Vacation\VacationEmployee::bookLabel($person->book) }}
            @if ($employee && ($employee->branch || $employee->department))
                · {{ collect([$employee->branch?->name, $employee->department?->name])->filter()->implode(' · ') }}
            @endif
        </small>
    </div>
    @if ($years->count() > 1)
        <div class="btn-group btn-group-sm">
            @foreach ($years as $y)
                <a href="{{ route('admin.vacations.balances.show', ['vacationEmployee' => $person, 'year' => $y]) }}"
                   class="btn {{ $y === $year ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $y }}</a>
            @endforeach
        </div>
    @endif
</div>

<div class="row g-4">
    <div class="col-lg-8">

        {{-- ── Balance ─────────────────────────────────────────── --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-wallet2 me-1"></i>Annual leave balance {{ $year }}</strong>
                @if ($balance)
                    <span class="badge {{ $balance->isStale() ? 'bg-warning text-dark' : 'bg-light text-dark border' }}">
                        Oracle, as of {{ $balance->as_of->format('d M Y') }}
                    </span>
                @endif
            </div>
            <div class="card-body">
                @if ($balance && $balance->hasBalance())
                    <div class="row g-3 text-center">
                        @foreach ([
                            ['Last year', 'carried over into '.$year, $balance->carryover, $balance->carryover < 0 ? 'text-danger' : ''],
                            ['This year so far', 'earned up to '.$balance->as_of->format('d M'), $balance->accrued, ''],
                            ['Used', 'days taken in '.$year, $balance->used, ''],
                            ['Remaining', 'days left', $balance->balance, $balance->balance < 0 ? 'text-danger' : 'text-success'],
                        ] as [$label, $hint, $value, $class])
                            <div class="col-6 col-md-3">
                                <div class="border rounded-3 py-3 h-100">
                                    <div class="small text-muted">{{ $label }}</div>
                                    <div class="fs-3 fw-bold font-monospace {{ $class }}">{{ $days($value) }}</div>
                                    <div class="small text-muted">{{ $hint }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="small text-muted mt-3">
                        <span class="font-monospace">
                            {{ $days($balance->carryover ?? 0) }} + {{ $days($balance->accrued ?? 0) }} − {{ $days($balance->used ?? 0) }}
                            @if ($adjustment)
                                {{ $adjustment > 0 ? '+' : '−' }} {{ $days(abs($adjustment)) }}
                            @endif
                            = {{ $days($balance->balance) }}
                        </span>
                        (last year + this year so far − used{{ $adjustment ? ' + other adjustments' : '' }} = remaining)
                    </div>

                    @if ($adjustment)
                        <div class="alert alert-info small mt-3 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Oracle's remaining balance includes {{ $adjustment > 0 ? '+' : '' }}{{ $days($adjustment) }} days that are
                            not in its carried-over, earned or used columns — an adjustment recorded in Oracle. Check it there
                            before relying on it.
                        </div>
                    @endif
                    @if ($balance->isStale())
                        <div class="alert alert-warning small mt-3 mb-0">
                            <i class="bi bi-hourglass-bottom me-1"></i>
                            This balance is {{ $balance->as_of->diffInDays(now()->startOfDay()) }} days old. Oracle adds leave every
                            month, so what this person has earned — and what is left — has grown since.
                        </div>
                    @endif
                @elseif ($balance)
                    <div class="text-muted">
                        Oracle has no leave plan for this person yet: every figure in its balance sheet is empty.
                    </div>
                @else
                    <div class="text-muted">
                        This person is not in Oracle's balance sheet for {{ $year }} — only their leave records came through.
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Leave records ───────────────────────────────────── --}}
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-calendar-range me-1"></i>Leave records in {{ $year }}</strong>
                @if ($withdrawnCount > 0)
                    <a class="small text-decoration-none"
                       href="{{ route('admin.vacations.balances.show', ['vacationEmployee' => $person, 'year' => $year, 'withdrawn' => $showWithdrawn ? 0 : 1]) }}">
                        {{ $showWithdrawn ? 'Hide' : 'Show' }} {{ $withdrawnCount }} no longer in Oracle
                    </a>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Type</th>
                            <th>From</th>
                            <th>To</th>
                            <th class="text-end" title="Days without the weekend">Days</th>
                            <th class="text-end">Calendar days</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($records as $record)
                            <tr class="{{ $record->removed_at ? 'text-muted' : '' }}">
                                <td><span class="badge {{ \App\Models\Vacation\VacationAbsence::typeBadgeClass($record->absence_type) }}">{{ $record->absence_type }}</span></td>
                                <td class="text-nowrap">{{ $record->start_date->format('D d M Y') }}</td>
                                <td class="text-nowrap">{{ $record->end_date->format('D d M Y') }}</td>
                                <td class="text-end font-monospace">{{ $days($record->days()) }}</td>
                                <td class="text-end font-monospace text-muted">{{ $record->calendar_days }}</td>
                                <td>
                                    <span class="badge {{ $record->statusBadgeClass($today) }}">{{ $record->statusLabel($today) }}</span>
                                    @if ($record->removed_at)
                                        <div class="small">since {{ $record->removed_at->format('d M Y') }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No leave records in {{ $year }}.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-transparent small text-muted">
                Days leave out the weekend but not public holidays, so a record spanning a holiday can show more days than
                Oracle deducted. The balance above is Oracle's own count.
            </div>
        </div>
    </div>

    <div class="col-lg-4">

        {{-- ── The year by type ────────────────────────────────── --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent"><strong><i class="bi bi-bar-chart me-1"></i>{{ $year }} by type</strong></div>
            <ul class="list-group list-group-flush small">
                @forelse ($byType as $row)
                    <li class="list-group-item d-flex justify-content-between align-items-center {{ $row->trip ? 'text-muted' : '' }}">
                        <span>
                            <span class="badge {{ \App\Models\Vacation\VacationAbsence::typeBadgeClass($row->type) }} me-1">{{ $row->type }}</span>
                            {{ $row->records }} {{ \Illuminate\Support\Str::plural('record', $row->records) }}
                        </span>
                        <span class="font-monospace fw-semibold" title="{{ $row->calendar_days }} calendar days">{{ $days($row->days) }} days</span>
                    </li>
                @empty
                    <li class="list-group-item text-muted">No leave records in {{ $year }}.</li>
                @endforelse
            </ul>
        </div>

        {{-- ── Which employee ──────────────────────────────────── --}}
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent"><strong><i class="bi bi-link-45deg me-1"></i>Employee</strong></div>
            <div class="card-body small">
                @if ($employee)
                    <div class="fw-semibold">
                        @can('view-employees')
                            <a href="{{ route('admin.employees.show', $employee->id) }}" class="text-decoration-none">{{ $employee->name }}</a>
                        @else
                            {{ $employee->name }}
                        @endcan
                    </div>
                    <div class="text-muted">Oracle no. on their record: {{ $employee->oracle_emp_no ?: '—' }}</div>
                @elseif ($person->isConfirmedNotEmployee())
                    <div class="text-muted">Marked as nobody in the NOC.</div>
                @else
                    <div class="text-warning-emphasis fw-semibold">Not linked to an employee.</div>
                @endif

                <div class="mt-2">
                    <span class="badge bg-light text-dark border">{{ $person->methodLabel() }}</span>
                    @if ($person->isManual() && $person->confirmedBy)
                        <span class="text-muted">by {{ $person->confirmedBy->name }}, {{ $person->confirmed_at?->format('d M Y') }}</span>
                    @endif
                </div>

                @if ($person->match_method === \App\Models\Vacation\VacationEmployee::METHOD_AMBIGUOUS)
                    <div class="mt-2">More than one employee in this company's branches holds Oracle no. {{ $person->oracle_emp_no }}. Choose the right one.</div>
                @elseif ($person->match_method === \App\Models\Vacation\VacationEmployee::METHOD_NONE && $candidates->isNotEmpty())
                    <div class="mt-2">The only employees holding Oracle no. {{ $person->oracle_emp_no }} are outside this company's branches, so they were not linked.</div>
                @elseif ($person->match_method === \App\Models\Vacation\VacationEmployee::METHOD_NONE)
                    <div class="mt-2">No employee holds Oracle no. {{ $person->oracle_emp_no }}.</div>
                @endif

                @if ($candidates->isNotEmpty())
                    <div class="mt-2 text-muted">Employees holding this Oracle no.:</div>
                    <ul class="mb-0 ps-3">
                        @foreach ($candidates as $candidate)
                            <li>{{ $candidate->name }} · {{ $candidate->branch?->name ?? 'no branch' }}{{ $candidate->status === 'terminated' ? ' · left' : '' }}</li>
                        @endforeach
                    </ul>
                @endif

                @can('manage-vacations')
                    <hr>
                    <form method="POST" action="{{ route('admin.vacations.people.link', $person) }}">
                        @csrf
                        <label class="form-label small mb-1">Link to employee</label>
                        <div class="input-group input-group-sm">
                            <input name="employee" list="vacation-employee-options" class="form-control" autocomplete="off" required
                                   placeholder="Type a name or Oracle no…">
                            <button class="btn btn-primary">Link</button>
                        </div>
                    </form>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        @unless ($person->isConfirmedNotEmployee())
                            <form method="POST" action="{{ route('admin.vacations.people.no-employee', $person) }}"
                                  onsubmit="return confirm('Mark this Oracle number as nobody in the NOC?')">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary">Not an employee</button>
                            </form>
                        @endunless
                        @if ($person->isManual())
                            <form method="POST" action="{{ route('admin.vacations.people.reset', $person) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary">Back to automatic</button>
                            </form>
                        @endif
                    </div>
                    <div class="form-text">An import never changes a link chosen here.</div>

                    <datalist id="vacation-employee-options">
                        @foreach ($employeeOptions as $option)
                            <option value="{{ $option->id }} · {{ $option->name }}{{ $option->oracle_emp_no ? ' · Oracle '.$option->oracle_emp_no : '' }}{{ $option->branch ? ' · '.$option->branch->name : '' }}">{{ $option->email }}</option>
                        @endforeach
                    </datalist>
                @endcan
            </div>
        </div>
    </div>
</div>
@endsection
