@extends('layouts.admin')

@section('title', 'Attendance — Employee Mapping')

@section('content')
@include('admin.attendance._tabs')

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Employee Mapping</h4>
        <small class="text-muted">
            Which NOC employee each BioTime code belongs to. Codes are matched to the employee's <strong>Oracle number</strong>;
            when a number belongs to two people (SSS Egypt and SamirGroup), the branch of the area they punched in decides.
            Anything else waits here for you. Manual links are never changed by the automatic rule.
        </small>
    </div>
    @can('manage-attendance')
        <form method="POST" action="{{ route('admin.attendance.employees.automatch') }}">
            @csrf
            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-magic me-1"></i>Re-run auto-match</button>
        </form>
    @endcan
</div>

<ul class="nav nav-pills small mb-3">
    @foreach (['unlinked' => 'Needs a decision', 'ambiguous' => 'Ambiguous', 'none' => 'No Oracle match', 'auto' => 'Auto-linked', 'manual' => 'Manual', 'all' => 'All'] as $key => $label)
        <li class="nav-item">
            <a class="nav-link py-1 {{ $status === $key ? 'active' : '' }}"
               href="{{ route('admin.attendance.employees.index', array_filter(['status' => $key, 'source' => $source, 'q' => $q])) }}">
                {{ $label }} <span class="badge {{ $status === $key ? 'bg-light text-dark' : 'bg-secondary' }} ms-1">{{ $counts[$key] }}</span>
            </a>
        </li>
    @endforeach
</ul>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="col-md-5">
                <input type="search" name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="BioTime code or employee name…">
            </div>
            @if ($sources->count() > 1)
                <div class="col-md-3">
                    <select name="source" class="form-select form-select-sm">
                        <option value="">All sources</option>
                        @foreach ($sources as $src)
                            <option value="{{ $src->id }}" @selected($source === $src->id)>{{ $src->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-md-2">
                <button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>BioTime code</th>
                    <th>Areas</th>
                    <th>Last punch</th>
                    <th>Employee</th>
                    @can('manage-attendance')
                        <th style="min-width:340px">Link</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            <div class="fw-semibold font-monospace">{{ $row->emp_code }}</div>
                            <div class="small text-muted">{{ $row->source?->name }}</div>
                        </td>
                        <td class="small">{{ implode(', ', $row->areas ?? []) ?: '—' }}</td>
                        <td class="small text-nowrap">{{ $row->last_punch_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td>
                            @if ($row->employee)
                                <div class="fw-semibold">{{ $row->employee->name }}</div>
                                <div class="small text-muted">
                                    Oracle {{ $row->employee->oracle_emp_no ?: '—' }}
                                    @if ($row->employee->branch)
                                        · {{ $row->employee->branch->name }}
                                    @endif
                                    @if ($row->employee->status !== 'active')
                                        · <span class="text-warning-emphasis">{{ $row->employee->status }}</span>
                                    @endif
                                </div>
                            @endif
                            @php
                                $badge = match (true) {
                                    $row->isConfirmedNotEmployee() => 'bg-secondary',
                                    $row->match_method === 'manual' => 'bg-primary',
                                    in_array($row->match_method, ['auto_empno', 'auto_empno_branch'], true) => 'bg-success',
                                    default => 'bg-danger',
                                };
                            @endphp
                            <span class="badge {{ $badge }}">{{ $row->methodLabel() }}</span>
                            @if ($row->confirmedBy)
                                <span class="small text-muted">by {{ $row->confirmedBy->name }}</span>
                            @endif
                        </td>
                        @can('manage-attendance')
                            <td>
                                @if ($row->match_method === 'ambiguous' && ! empty($row->candidate_ids))
                                    <div class="small text-muted mb-1">This Oracle number belongs to:</div>
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        @foreach ($row->candidate_ids as $candidateId)
                                            @if ($candidate = $candidates->get($candidateId))
                                                <form method="POST" action="{{ route('admin.attendance.employees.link', $row) }}">
                                                    @csrf
                                                    <input type="hidden" name="employee" value="{{ $candidate->id }}">
                                                    <button class="btn btn-sm btn-outline-success">
                                                        {{ $candidate->name }}{{ $candidate->branch ? ' · '.$candidate->branch->name : '' }}
                                                    </button>
                                                </form>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                                <form method="POST" action="{{ route('admin.attendance.employees.link', $row) }}" class="d-flex gap-1">
                                    @csrf
                                    <input name="employee" list="employee-options" class="form-control form-control-sm"
                                           placeholder="Type a name or Oracle no…" required autocomplete="off">
                                    <button class="btn btn-sm btn-outline-primary">Link</button>
                                </form>
                                <div class="d-flex gap-2 mt-1">
                                    @unless ($row->isConfirmedNotEmployee())
                                        <form method="POST" action="{{ route('admin.attendance.employees.no-employee', $row) }}">
                                            @csrf
                                            <button class="btn btn-link btn-sm p-0 text-muted" title="A visitor, contractor or test code — its days stop counting as errors">Not an employee</button>
                                        </form>
                                    @endunless
                                    @if ($row->isManual())
                                        <form method="POST" action="{{ route('admin.attendance.employees.reset', $row) }}">
                                            @csrf
                                            <button class="btn btn-link btn-sm p-0 text-muted">Reset to automatic</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <i class="bi bi-person-check fs-3 d-block mb-2"></i>
                            @if ($status === 'unlinked')
                                Every BioTime code is linked or decided.
                            @else
                                Nothing here.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($rows->hasPages())
    <div class="mt-3">{{ $rows->links() }}</div>
@endif

@can('manage-attendance')
    <datalist id="employee-options">
        @foreach ($employeeOptions as $e)
            <option value="{{ $e->id }} · {{ $e->name }}{{ $e->oracle_emp_no ? ' · Oracle '.$e->oracle_emp_no : '' }}{{ $e->branch ? ' · '.$e->branch->name : '' }}{{ $e->status !== 'active' ? ' · '.$e->status : '' }}"></option>
        @endforeach
    </datalist>
@endcan
@endsection
