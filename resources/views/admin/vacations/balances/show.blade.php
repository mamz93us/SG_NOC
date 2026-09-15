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
                @include('admin.vacations.balances._balance', ['balance' => $balance, 'year' => $year])
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
            @include('admin.vacations.balances._records', ['records' => $records, 'today' => $today, 'year' => $year])
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
                    <a href="{{ route('admin.people.show', $employee) }}" class="btn btn-sm btn-outline-primary mt-2">
                        <i class="bi bi-person-badge me-1"></i>Attendance &amp; vacation profile
                    </a>
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
