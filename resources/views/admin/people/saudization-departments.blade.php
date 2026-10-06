@extends('layouts.admin')

@section('title', 'Saudization by department')

@section('content')
@php
    $pct = fn (?float $value) => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    $num = fn (float $value) => rtrim(rtrim(number_format($value, 1), '0'), '.');
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-flag me-2 text-primary"></i>Saudization</h4>
        <small class="text-muted">
            Each department, its branches counted together, against the percentage its own professions require.
        </small>
    </div>
    @canroute('admin.people.breakdown')
        <a href="{{ route('admin.people.breakdown', ['by' => 'nationality']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pie-chart me-1"></i>Workforce breakdown</a>
    @endcanroute
</div>

@include('admin.people._saudization-tabs', ['tab' => 'departments'])

{{-- ── At a glance ──────────────────────────────────────────── --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-4">
        <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
            <div class="small text-muted">Departments meeting their target</div>
            <div class="fs-4 fw-bold text-success">{{ $compliant }}</div>
            <div class="small text-muted">of {{ $compliant + $not_compliant }} departments</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
            <div class="small text-muted">Departments below their target</div>
            <div class="fs-4 fw-bold {{ $not_compliant > 0 ? 'text-danger' : 'text-muted' }}">{{ $not_compliant }}</div>
            <div class="small text-muted">{{ number_format($short_by) }} {{ \Illuminate\Support\Str::plural('Saudi', $short_by) }} short, each counted on its own</div>
        </div></div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
            <div class="small text-muted">Saudis in the professional groups</div>
            <div class="fs-4 fw-bold">{{ $pct($people > 0 ? $saudis / $people * 100 : null) }}</div>
            <div class="small text-muted">{{ number_format($saudis) }} of {{ number_format($people) }} people</div>
        </div></div>
    </div>
</div>

<div class="alert alert-light border small py-2 mb-3">
    <i class="bi bi-info-circle me-1"></i>
    The ministry sets a percentage for a <strong>profession</strong>, not for a department. A department's target is what
    its own professions ask of the people in it: 40% of 6 accountants plus 100% of 2 administrative staff is 4.4, so
    5 Saudis. A <strong>head count</strong> from Oracle's nationality and job category, not the ministry's weighted figure.
    Open a department to see its professions and the Oracle departments it brings together.
    @if ($uncounted_people > 0)
        <div class="mt-1">
            <strong>{{ number_format($uncounted_people) }} {{ \Illuminate\Support\Str::plural('person', $uncounted_people) }}</strong>
            in no professional group (drivers, warehouse staff and others) are not counted at all, as Saudi or otherwise.
            @if ($unlisted !== [])
                {{ count($unlisted) }} {{ \Illuminate\Support\Str::plural('department', count($unlisted)) }} with nobody in a group
                {{ count($unlisted) === 1 ? 'is' : 'are' }} not listed: {{ collect($unlisted)->pluck('name')->implode(', ') }}.
            @endif
        </div>
    @endif
    @if ($short_by !== $groupsShortBy)
        <div class="mt-1">
            The departments are short of <strong>{{ number_format($short_by) }}</strong> between them, the professional groups of
            <strong>{{ number_format($groupsShortBy) }}</strong>. The two differ because a department above its target does not
            make up for one below it, and each department is rounded up on its own. The company's figure is the professional groups'.
        </div>
    @endif
</div>

{{-- ── The departments ──────────────────────────────────────── --}}
<div class="d-flex justify-content-between align-items-center mb-2">
    <small class="text-muted">{{ $departments->count() }} departments</small>
    <div class="btn-group btn-group-sm">
        @foreach (\App\Services\People\SaudizationByDepartment::SORTS as $key => $label)
            <a href="{{ route('admin.people.saudization.departments', $key === 'needs' ? [] : ['sort' => $key]) }}"
               class="btn {{ $sort === $key ? 'btn-secondary' : 'btn-outline-secondary' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Department</th>
                    <th class="text-end">People</th>
                    <th class="text-end">Saudis</th>
                    <th style="min-width:170px">Saudi share</th>
                    <th class="text-end">Target</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($departments as $i => $row)
                    <tr @class(['table-warning' => $row['compliant'] === false]) style="cursor:pointer"
                        data-bs-toggle="collapse" data-bs-target="#department-{{ $i }}" aria-expanded="false" aria-controls="department-{{ $i }}">
                        <td>
                            <div class="fw-semibold"><i class="bi bi-chevron-right small text-muted me-1"></i>{{ $row['name'] }}</div>
                            <div class="small text-muted ms-3">
                                @foreach ($row['branches'] as $branch => $count)
                                    <span class="badge bg-light text-dark border fw-normal">{{ $branch }} {{ $count }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="text-end">{{ number_format($row['people']) }}</td>
                        <td class="text-end">{{ number_format($row['saudis']) }}</td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1 position-relative" style="height:8px;overflow:visible" role="progressbar" aria-valuenow="{{ round($row['share']) }}" aria-valuemin="0" aria-valuemax="100">
                                    <div class="progress-bar {{ $row['compliant'] ? 'bg-success' : 'bg-danger' }} rounded" style="width:{{ round($row['share'], 1) }}%"></div>
                                    <span class="position-absolute bg-dark" style="left:{{ min(100, round($row['target_percent'], 1)) }}%;top:-3px;width:2px;height:14px" title="Target: {{ $pct($row['target_percent']) }}"></span>
                                </div>
                                <span class="fw-semibold text-end" style="width:52px">{{ $pct($row['share']) }}</span>
                            </div>
                        </td>
                        <td class="text-end">
                            <span class="fw-semibold">{{ $row['saudis_required'] }} {{ \Illuminate\Support\Str::plural('Saudi', $row['saudis_required']) }}</span>
                            <div class="small text-muted">{{ $pct($row['target_percent']) }} of {{ $row['people'] }}</div>
                        </td>
                        <td>
                            @if ($row['compliant'])
                                <span class="badge bg-success">Meets its target</span>
                            @else
                                <span class="badge bg-danger">Below its target</span>
                                <div class="small text-danger">needs {{ $row['short_by'] }} more {{ \Illuminate\Support\Str::plural('Saudi', $row['short_by']) }}</div>
                            @endif
                        </td>
                    </tr>
                    <tr class="collapse" id="department-{{ $i }}">
                        <td colspan="6" class="bg-light">
                            <div class="row g-3 py-2 px-3">
                                <div class="col-lg-7">
                                    <div class="small fw-semibold mb-1">Professions in this department</div>
                                    <table class="table table-sm bg-white border mb-0">
                                        <thead>
                                            <tr class="small text-muted">
                                                <th>Professional group</th>
                                                <th class="text-end">People</th>
                                                <th class="text-end">Saudis</th>
                                                <th class="text-end">Share</th>
                                                <th class="text-end">Required</th>
                                                <th class="text-end" title="This profession's part of the department's target">Asks for</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($row['groups'] as $slice)
                                                <tr>
                                                    <td>
                                                        <span dir="rtl" lang="ar">{{ $slice['group']->name_ar }}</span>
                                                        <span class="small text-muted">· {{ $slice['group']->name_en ?: $slice['group']->job_category }}</span>
                                                    </td>
                                                    <td class="text-end">{{ $slice['people'] }}</td>
                                                    <td class="text-end">{{ $slice['saudis'] }}</td>
                                                    <td class="text-end {{ $slice['meets'] ? 'text-success' : 'text-danger' }} fw-semibold">{{ $pct($slice['share']) }}</td>
                                                    <td class="text-end">{{ $pct($slice['required']) }}</td>
                                                    <td class="text-end">{{ $num($slice['asks']) }}</td>
                                                </tr>
                                            @endforeach
                                                <tr class="fw-semibold">

                                                    <td>Department target</td>
                                                    <td class="text-end">{{ $row['people'] }}</td>
                                                    <td class="text-end">{{ $row['saudis'] }}</td>
                                                    <td class="text-end">{{ $pct($row['share']) }}</td>
                                                    <td class="text-end">{{ $pct($row['target_percent']) }}</td>
                                                    <td class="text-end">{{ $num($row['groups']->sum('asks')) }} → {{ $row['saudis_required'] }}</td>
                                                </tr>
                                        </tbody>

                                    </table>
                                </div>
                                <div class="col-lg-5">
                                    <div class="small fw-semibold mb-1">Oracle departments counted here</div>
                                    <ul class="list-group list-group-flush border bg-white small">
                                        @foreach ($row['oracle_departments'] as $oracle => $count)
                                            <li class="list-group-item d-flex justify-content-between py-1">
                                                <span>{{ $oracle !== '' ? $oracle : 'No department in Oracle' }}</span>
                                                <span class="text-muted">{{ $count }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-5">Nobody is in a professional group yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
