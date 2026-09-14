@extends('layouts.admin')

@section('title', 'Vacations — Leave records')

@section('content')
@include('admin.vacations._tabs')

@php
    $query = request()->except('page');
    $days = fn ($value) => \App\Models\Vacation\VacationBalance::days((float) $value);
    $todayString = $today->toDateString();
    $ranges = [
        'Today' => [$todayString, $todayString],
        'This month' => [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()],
        'Next 30 days' => [$todayString, $today->addDays(29)->toDateString()],
        'This year' => [$today->startOfYear()->toDateString(), $today->endOfYear()->toDateString()],
    ];
    $sameDay = $filters['from'] === $filters['to'];
@endphp

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-calendar-range me-2 text-primary"></i>Leave records</h4>
        <small class="text-muted">
            Every leave record from Oracle with at least one day
            {{ $sameDay ? 'on '.\Carbon\CarbonImmutable::parse($filters['from'])->format('d M Y') : 'between '.\Carbon\CarbonImmutable::parse($filters['from'])->format('d M Y').' and '.\Carbon\CarbonImmutable::parse($filters['to'])->format('d M Y') }}
            — who is away, on what, and when.
        </small>
    </div>
    <div class="btn-group btn-group-sm">
        @foreach ($ranges as $label => [$from, $to])
            <a href="{{ route('admin.vacations.absences.index', array_merge($query, ['from' => $from, 'to' => $to])) }}"
               class="btn {{ $filters['from'] === $from && $filters['to'] === $to ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>

{{-- ── Today, and the range by type ──────────────────────────── --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3 col-xl-2">
        <a href="{{ route('admin.vacations.absences.index', ['from' => $todayString, 'to' => $todayString, 'type' => 'leave']) }}"
           class="card shadow-sm border-0 h-100 text-decoration-none text-body">
            <div class="card-body py-3">
                <div class="small text-muted"><i class="bi bi-airplane me-1 text-info"></i>On leave today</div>
                <div class="fs-4 fw-bold text-info">{{ $onLeaveToday }}</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
        <a href="{{ route('admin.vacations.absences.index', ['from' => $todayString, 'to' => $todayString, 'type' => 'trips']) }}"
           class="card shadow-sm border-0 h-100 text-decoration-none text-body">
            <div class="card-body py-3">
                <div class="small text-muted"><i class="bi bi-briefcase me-1 text-secondary"></i>On a business trip today</div>
                <div class="fs-4 fw-bold text-secondary">{{ $onTripToday }}</div>
            </div>
        </a>
    </div>
    <div class="col-md-6 col-xl-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3 small">
                <div class="text-muted mb-1">In this range</div>
                @forelse ($typeCounts as $count)
                    <a href="{{ route('admin.vacations.absences.index', array_merge($query, ['type' => $count->absence_type])) }}"
                       class="d-inline-block me-3 mb-1 text-decoration-none text-body">
                        <span class="badge {{ \App\Models\Vacation\VacationAbsence::typeBadgeClass($count->absence_type) }}">{{ $count->absence_type }}</span>
                        {{ $count->records }} · {{ $count->people }} {{ \Illuminate\Support\Str::plural('person', $count->people) }}
                    </a>
                @empty
                    <span class="text-muted">No leave records.</span>
                @endforelse
            </div>
        </div>
    </div>
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
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">From</label>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">To</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-muted mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All types</option>
                    @foreach (\App\Http\Controllers\Admin\Vacation\VacationAbsenceController::GROUPS as $value => $label)
                        <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                    @endforeach
                    <optgroup label="Oracle types">
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ $type }}</option>
                        @endforeach
                    </optgroup>
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
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="Name or Oracle no…">
            </div>
            <div class="col-md-3">
                <div class="form-check mb-1">
                    <input class="form-check-input" type="checkbox" name="withdrawn" value="1" id="withdrawn" @checked($filters['withdrawn'])>
                    <label class="form-check-label small" for="withdrawn">Include records no longer in Oracle</label>
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="{{ route('admin.vacations.absences.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <small class="text-muted">{{ $records->total() }} {{ \Illuminate\Support\Str::plural('record', $records->total()) }}</small>
    <a href="{{ route('admin.vacations.absences.export', $query) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-download me-1"></i>Export CSV
    </a>
</div>

{{-- ── Records ──────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Person</th>
                    <th>Branch / Department</th>
                    <th>Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th class="text-end" title="Days without the weekend">Days</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    @php
                        $person = $record->vacationEmployee;
                        $employee = $person?->employee;
                        $page = $person ? route('admin.vacations.balances.show', ['vacationEmployee' => $person, 'year' => $record->start_date->year]) : null;
                    @endphp
                    <tr class="{{ $record->removed_at ? 'text-muted' : '' }}">
                        <td>
                            <a href="{{ $page }}" class="fw-semibold text-decoration-none {{ $employee ? '' : 'text-warning-emphasis' }}">
                                {{ $employee?->name ?? 'Oracle person '.$person?->oracle_emp_no }}
                            </a>
                            <div class="small text-muted font-monospace">Oracle {{ $person?->oracle_emp_no }}</div>
                        </td>
                        <td class="small">
                            {{ $employee?->branch?->name ?: '—' }}
                            @if ($employee?->department)
                                <div class="text-muted">{{ $employee->department->name }}</div>
                            @endif
                        </td>
                        <td><span class="badge {{ \App\Models\Vacation\VacationAbsence::typeBadgeClass($record->absence_type) }}">{{ $record->absence_type }}</span></td>
                        <td class="text-nowrap small">{{ $record->start_date->format('D d M Y') }}</td>
                        <td class="text-nowrap small">{{ $record->end_date->format('D d M Y') }}</td>
                        <td class="text-end font-monospace">
                            {{ $days($record->days()) }}
                            @if ($record->calendar_days !== $record->work_days)
                                <div class="small text-muted" title="Calendar days">{{ $record->calendar_days }} cal.</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $record->statusBadgeClass($today) }}">{{ $record->statusLabel($today) }}</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-calendar-x fs-3 d-block mb-2"></i>
                            No leave records match.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($records->hasPages())
    <div class="mt-3">{{ $records->links() }}</div>
@endif
@endsection
