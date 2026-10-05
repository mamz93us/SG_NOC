@extends('layouts.admin')

@section('title', 'Workforce breakdown')

@section('content')
@php
    $dimension = \App\Services\People\WorkforceBreakdown::DIMENSIONS[$by];
    $named = $groups->where('named', true)->count();

    // Every link on the page keeps the filters it does not change.
    $state = ['by' => $by, 'branch' => $filters['branch'], 'status' => $filters['status'] === 'active' ? null : $filters['status'],
        'pick' => $picked['key'] ?? null, 'q' => $search];
    $url = fn (array $change = []) => route('admin.people.breakdown',
        array_filter(array_merge($state, $change), fn ($value) => $value !== null && $value !== ''));
    $percent = fn (float $share) => $share > 0 && $share < 0.001 ? '<0.1%' : rtrim(rtrim(number_format($share * 100, 1), '0'), '.').'%';
@endphp

<div class="mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-pie-chart me-2 text-primary"></i>Workforce breakdown</h4>
    <small class="text-muted">
        Everybody, counted by the job category and the profession Oracle holds for them. Pick a row to see who is in it.
    </small>
</div>

{{-- ── Filters ──────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('admin.people.breakdown') }}" class="row g-2 align-items-end">
            <input type="hidden" name="by" value="{{ $by }}">
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">Branch</label>
                <select name="branch" class="form-select form-select-sm">
                    <option value="">All branches</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected($filters['branch'] === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-muted mb-1">People</label>
                <select name="status" class="form-select form-select-sm">
                    @foreach (\App\Services\People\WorkforceBreakdown::STATUS as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button class="btn btn-sm btn-outline-primary flex-grow-1" title="Filter"><i class="bi bi-funnel"></i></button>
                <a href="{{ route('admin.people.breakdown', ['by' => $by]) }}" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

{{-- ── What to count by ─────────────────────────────────────── --}}
<ul class="nav nav-pills mb-3">
    @foreach (\App\Services\People\WorkforceBreakdown::DIMENSIONS as $key => $option)
        <li class="nav-item">
            <a class="nav-link py-1 {{ $key === $by ? 'active' : '' }}" href="{{ $url(['by' => $key, 'pick' => null, 'q' => null, 'page' => null]) }}">{{ $option['label'] }}</a>
        </li>
    @endforeach
</ul>

<div class="row g-3">
    {{-- ── The counts ───────────────────────────────────────── --}}
    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong>By {{ mb_strtolower($dimension['label']) }}</strong>
                <small class="text-muted">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('person', $total) }} · {{ $named }} {{ $named === 1 ? mb_strtolower($dimension['label']) : $dimension['plural'] }}</small>
            </div>
            <div class="table-responsive" style="max-height:70vh;overflow-y:auto">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="table-light" style="position:sticky;top:0;z-index:1">
                        <tr>
                            <th>{{ $dimension['label'] }}</th>
                            <th class="text-end" style="width:70px">People</th>
                            <th style="width:130px">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="{{ $picked ? '' : 'table-active' }}" style="cursor:pointer" onclick="window.location='{{ $url(['pick' => null, 'page' => null]) }}'">
                            <td><a href="{{ $url(['pick' => null, 'page' => null]) }}" class="fw-semibold text-decoration-none">Everyone</a></td>
                            <td class="text-end fw-semibold">{{ number_format($total) }}</td>
                            <td></td>
                        </tr>
                        @foreach ($groups as $group)
                            @php $link = $url(['pick' => $group['key'], 'page' => null]); @endphp
                            <tr class="{{ ($picked['key'] ?? null) === $group['key'] ? 'table-active' : '' }}" style="cursor:pointer" onclick="window.location='{{ $link }}'">
                                <td dir="auto" class="text-start">
                                    <a href="{{ $link }}" class="text-decoration-none {{ $group['named'] ? '' : 'text-muted fst-italic' }}">{{ $group['label'] }}</a>
                                </td>
                                <td class="text-end">{{ number_format($group['count']) }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height:6px" role="progressbar" aria-valuenow="{{ round($group['share'] * 100) }}" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar {{ $group['named'] ? '' : 'bg-secondary' }}" style="width:{{ round($group['share'] * 100, 1) }}%"></div>
                                        </div>
                                        <span class="small text-muted text-end" style="width:44px">{{ $percent($group['share']) }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        @if ($total === 0)
                            <tr><td colspan="3" class="text-center text-muted py-4">Nobody matches these filters.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ── The people ───────────────────────────────────────── --}}
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong dir="auto">{{ $picked['label'] ?? 'Everyone' }}</strong>
                    <small class="text-muted ms-1">{{ number_format($employees->total()) }} {{ \Illuminate\Support\Str::plural('person', $employees->total()) }}</small>
                </div>
                <form method="GET" action="{{ route('admin.people.breakdown') }}" class="d-flex gap-1">
                    @foreach (array_filter(array_diff_key($state, ['q' => true]), fn ($value) => $value !== null && $value !== '') as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <input type="search" name="q" value="{{ $search }}" class="form-control form-control-sm" style="width:220px" placeholder="Name, email, job or Oracle no…">
                    <button class="btn btn-sm btn-outline-primary" title="Search"><i class="bi bi-search"></i></button>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Person</th>
                            <th>Oracle no.</th>
                            <th>Job category</th>
                            <th>Profession</th>
                            <th>Branch / Department</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($employees as $employee)
                            @php $page = route('admin.people.show', $employee); @endphp
                            <tr style="cursor:pointer" onclick="window.location='{{ $page }}'">
                                <td>
                                    <a href="{{ $page }}" class="fw-semibold text-decoration-none">{{ $employee->name }}</a>
                                    @if ($employee->status === 'terminated')
                                        <span class="badge bg-danger ms-1">Left</span>
                                    @endif
                                    @if ($employee->name_ar)
                                        <div class="small text-muted text-start" dir="rtl" lang="ar">{{ $employee->name_ar }}</div>
                                    @endif
                                    @if ($employee->job_title)
                                        <div class="small text-muted">{{ $employee->job_title }}</div>
                                    @endif
                                </td>
                                <td class="small font-monospace">{{ $employee->oracle_emp_no ?: '—' }}</td>
                                <td class="small">{{ $employee->oracle_job_category ?: '—' }}</td>
                                <td class="small text-start" dir="auto">{{ $employee->oracle_profession ?: '—' }}</td>
                                <td class="small">
                                    {{ $employee->branch?->name ?: '—' }}
                                    @if ($employee->department)
                                        <div class="text-muted">{{ $employee->department->name }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">
                                    <i class="bi bi-person-x fs-3 d-block mb-2"></i>
                                    Nobody matches{{ $search !== '' ? ' "'.$search.'"' : '' }}.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($employees->hasPages())
            <div class="mt-3">{{ $employees->links() }}</div>
        @endif
    </div>
</div>
@endsection
