@extends('layouts.admin')

@section('title', 'Vacations — Balances')

@section('content')
@include('admin.vacations._tabs')

@php
    $days = fn ($value) => \App\Models\Vacation\VacationBalance::days($value === null ? null : (float) $value);
    $staleAfter = (int) config('vacations.stale_after_days', 35);
    $stale = $latestAsOf && $latestAsOf->lt(now()->startOfDay()->subDays($staleAfter));
    $query = request()->except('page');
@endphp

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-wallet2 me-2 text-primary"></i>Vacation balances</h4>
        <small class="text-muted">
            Each person's annual leave as Oracle last reported it: last year's balance carried over, this year's leave
            earned so far, the days used and the days left.
        </small>
    </div>
    <div class="text-end">
        @if ($latestAsOf)
            <span class="badge {{ $stale ? 'bg-warning text-dark' : 'bg-light text-dark border' }} fs-6 fw-normal">
                <i class="bi bi-calendar-check me-1"></i>As of {{ $latestAsOf->format('d M Y') }}
            </span>
            @if ($stale)
                <div class="small text-warning-emphasis mt-1">
                    {{ $latestAsOf->diffInDays(now()->startOfDay()) }} days old — Oracle adds leave every month, so
                    <br>this year's figures have grown since. Import a fresh balance sheet.
                </div>
            @endif
        @endif
    </div>
</div>

@if (! $imported)
    <div class="card shadow-sm border-0">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
            Nothing has been imported from Oracle yet.
            @can('manage-vacations')
                <div class="mt-2">
                    <a href="{{ route('admin.vacations.imports.index') }}" class="btn btn-primary btn-sm">
                        <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Oracle's vacation sheets
                    </a>
                </div>
            @endcan
        </div>
    </div>
@else

{{-- ── The year at a glance ───────────────────────────────────── --}}
<div class="row g-3 mb-3">
    @foreach ([
        ['label' => 'People with a balance', 'value' => (int) $totals->people, 'icon' => 'bi-people', 'class' => 'text-primary', 'show' => 'all'],
        ['label' => 'Days left, everyone', 'value' => $days($totals->remaining), 'icon' => 'bi-hourglass-split', 'class' => 'text-success', 'show' => null],
        ['label' => 'Negative balances', 'value' => (int) $totals->negative, 'icon' => 'bi-exclamation-triangle', 'class' => $totals->negative > 0 ? 'text-danger' : 'text-muted', 'show' => 'negative'],
        ['label' => 'No balance in Oracle', 'value' => (int) $totals->no_balance, 'icon' => 'bi-dash-circle', 'class' => 'text-muted', 'show' => 'no_balance'],
        ['label' => 'On leave today', 'value' => (int) $totals->on_leave_today, 'icon' => 'bi-airplane', 'class' => 'text-info', 'show' => null, 'href' => route('admin.vacations.absences.index', ['from' => now()->toDateString(), 'to' => now()->toDateString(), 'type' => 'leave'])],
        ['label' => 'Not linked to an employee', 'value' => (int) $totals->unlinked, 'icon' => 'bi-link-45deg', 'class' => $totals->unlinked > 0 ? 'text-warning' : 'text-muted', 'show' => 'unlinked'],
    ] as $tile)
        @php
            $href = $tile['href'] ?? ($tile['show'] ? route('admin.vacations.balances.index', array_merge($query, ['show' => $tile['show']])) : null);
        @endphp
        <div class="col-6 col-md-4 col-xl-2">
            <a @if ($href) href="{{ $href }}" @endif class="card shadow-sm border-0 h-100 text-decoration-none text-body {{ ($tile['show'] && $filters['show'] === $tile['show'] && $tile['show'] !== 'all') ? 'border border-primary' : '' }}">
                <div class="card-body py-3">
                    <div class="small text-muted"><i class="bi {{ $tile['icon'] }} me-1 {{ $tile['class'] }}"></i>{{ $tile['label'] }}</div>
                    <div class="fs-4 fw-bold {{ $tile['class'] }}">{{ $tile['value'] }}</div>
                </div>
            </a>
        </div>
    @endforeach
</div>

{{-- ── Filters ──────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            @if (count($books) > 1)
                <div class="col-6 col-md-2">
                    <label class="form-label small text-muted mb-1">Company</label>
                    <select name="book" class="form-select form-select-sm">
                        @foreach ($books as $key => $book)
                            <option value="{{ $key }}" @selected($filters['book'] === $key)>{{ $book['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-6 col-md-1">
                <label class="form-label small text-muted mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    @forelse ($years as $year)
                        <option value="{{ $year }}" @selected($filters['year'] === $year)>{{ $year }}</option>
                    @empty
                        <option value="{{ $filters['year'] }}">{{ $filters['year'] }}</option>
                    @endforelse
                </select>
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
                <label class="form-label small text-muted mb-1">Show</label>
                <select name="show" class="form-select form-select-sm">
                    @foreach (\App\Http\Controllers\Admin\Vacation\VacationBalanceController::SHOW as $value => $label)
                        <option value="{{ $value }}" @selected($filters['show'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="Name or Oracle no…">
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button class="btn btn-sm btn-outline-primary flex-grow-1" title="Filter"><i class="bi bi-funnel"></i></button>
                <a href="{{ route('admin.vacations.balances.index') }}" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <small class="text-muted">{{ $people->total() }} {{ \Illuminate\Support\Str::plural('person', $people->total()) }}</small>
    <a href="{{ route('admin.vacations.balances.export', $query) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-download me-1"></i>Export CSV
    </a>
</div>

{{-- ── People ───────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Person</th>
                    <th>Branch / Department</th>
                    <th class="text-end" title="Last year's balance brought into {{ $filters['year'] }} (Oracle: CARRYOVER)">Last year</th>
                    <th class="text-end" title="Leave earned in {{ $filters['year'] }} up to the as-of date (Oracle: ACCRUALS)">This year so far</th>
                    <th class="text-end" title="Days taken in {{ $filters['year'] }} (Oracle: ABSENCES)">Used</th>
                    <th class="text-end" title="What Oracle's balance holds beyond last year + this year − used">Other adj.</th>
                    <th class="text-end" title="Days left (Oracle: TOTAL_BALANCE)">Remaining</th>
                    <th style="width:60px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($people as $person)
                    @php
                        $balance = $person->balanceFor($filters['year']);
                        $employee = $person->employee;
                        $page = route('admin.vacations.balances.show', ['vacationEmployee' => $person, 'year' => $filters['year']]);
                        $adjustment = $balance?->otherAdjustments();
                    @endphp
                    <tr style="cursor:pointer" onclick="window.location='{{ $page }}'">
                        <td>
                            @if ($employee)
                                <a href="{{ $page }}" class="fw-semibold text-decoration-none">{{ $employee->name }}</a>
                                @if ($employee->status === 'terminated')
                                    <span class="badge bg-danger ms-1">Left</span>
                                @endif
                            @else
                                <a href="{{ $page }}" class="fw-semibold text-decoration-none text-warning-emphasis">Oracle person {{ $person->oracle_emp_no }}</a>
                                <span class="badge bg-warning text-dark ms-1">{{ $person->isConfirmedNotEmployee() ? 'Not an employee' : 'Not linked' }}</span>
                            @endif
                            <div class="small text-muted font-monospace">Oracle {{ $person->oracle_emp_no }}</div>
                        </td>
                        <td class="small">
                            {{ $employee?->branch?->name ?: '—' }}
                            @if ($employee?->department)
                                <div class="text-muted">{{ $employee->department->name }}</div>
                            @endif
                        </td>
                        @if ($balance && $balance->hasBalance())
                            <td class="text-end font-monospace {{ $balance->carryover < 0 ? 'text-danger' : '' }}">{{ $days($balance->carryover) }}</td>
                            <td class="text-end font-monospace">{{ $days($balance->accrued) }}</td>
                            <td class="text-end font-monospace">{{ $days($balance->used) }}</td>
                            <td class="text-end font-monospace small">
                                @if ($adjustment)
                                    <span class="badge bg-info-subtle text-info-emphasis border" title="Oracle's balance holds {{ $days($adjustment) }} days its sheet has no column for">
                                        {{ $adjustment > 0 ? '+' : '' }}{{ $days($adjustment) }}
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <span class="fw-bold font-monospace {{ $balance->balance < 0 ? 'text-danger' : 'text-success' }}">{{ $days($balance->balance) }}</span>
                                @if ($latestAsOf && $balance->as_of->lt($latestAsOf))
                                    <div class="small text-warning-emphasis" title="This person was not in the latest balance sheet">as of {{ $balance->as_of->format('d M') }}</div>
                                @endif
                            </td>
                        @elseif ($balance)
                            <td colspan="5" class="text-center text-muted small">No leave plan in Oracle yet — every figure is empty</td>
                        @else
                            <td colspan="5" class="text-center text-muted small">Not in Oracle's balance sheet for {{ $filters['year'] }} — leave records only</td>
                        @endif
                        <td class="text-end">
                            @if ($employee)
                                <a href="{{ route('admin.people.show', $employee) }}" class="btn btn-sm btn-outline-secondary"
                                   title="Attendance and vacation profile" onclick="event.stopPropagation()"><i class="bi bi-person-badge"></i></a>
                            @endif
<a href="{{ $page }}" class="btn btn-sm btn-outline-primary">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-person-x fs-3 d-block mb-2"></i>
                            Nobody matches these filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="small text-muted mt-2">
    Remaining is Oracle's own figure. Where it is not last year + this year so far − used, the difference is under
    Other adj.: Oracle holds an adjustment the balance sheet has no column for. Leave earned grows every month, so
    every figure is as true as the as-of date and no more.
</p>

@if ($people->hasPages())
    <div class="mt-3">{{ $people->links() }}</div>
@endif
@endif
@endsection
