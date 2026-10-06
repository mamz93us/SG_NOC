@extends('layouts.admin')

@section('title', 'Saudization')

@section('content')
@php
    $pct = fn (?float $value) => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    $month = fn ($date) => $date ? $date->format('M Y') : null;
    $who = fn (string $category) => route('admin.people.breakdown', ['by' => 'job_category', 'pick' => $category]);
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-flag me-2 text-primary"></i>Saudization</h4>
        <small class="text-muted">
            Each professional group's share of Saudis against the percentage it is required to reach.
        </small>
    </div>
    <div class="d-flex gap-2">
        @canroute('admin.people.breakdown')
            <a href="{{ route('admin.people.breakdown', ['by' => 'nationality']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pie-chart me-1"></i>Workforce breakdown</a>
        @endcanroute
        @if ($canEdit)
            <a href="{{ route('admin.people.saudization.edit') }}" class="btn btn-sm btn-primary"><i class="bi bi-pencil me-1"></i>Edit targets</a>
        @endif
    </div>
</div>

@include('admin.people._saudization-tabs', ['tab' => 'groups'])

{{-- ── At a glance ──────────────────────────────────────────── --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
            <div class="small text-muted">Saudis in Oracle's employee list</div>
            <div class="fs-4 fw-bold">{{ $pct($overall['share']) }}</div>
            <div class="small text-muted">{{ number_format($overall['saudis']) }} of {{ number_format($overall['people']) }} people</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
            <div class="small text-muted">Groups meeting their percentage</div>
            <div class="fs-4 fw-bold text-success">{{ $compliant }}</div>
            <div class="small text-muted">of {{ $groups->count() }} groups</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
            <div class="small text-muted">Groups below their percentage</div>
            <div class="fs-4 fw-bold {{ $not_compliant > 0 ? 'text-danger' : 'text-muted' }}">{{ $not_compliant }}</div>
            <div class="small text-muted">{{ $empty > 0 ? $empty.' with nobody in them' : 'every group has people' }}</div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100"><div class="card-body py-3">
            <div class="small text-muted">Counted by no group</div>
            <div class="fs-4 fw-bold">{{ number_format($outside->sum('people')) }}</div>
            <div class="small text-muted">people, listed below the table</div>
        </div></div>
    </div>
</div>

<div class="alert alert-light border small py-2 mb-3">
    <i class="bi bi-info-circle me-1"></i>
    This is a <strong>head count</strong>: Saudis ÷ everybody in the group, from the nationality and job category Oracle
    holds for each current employee. The ministry's own figure weighs people (part-time, salary, the husband or son of a
    citizen) and may not apply a rule below a number of staff, so a group can read differently on Qiwa.
    <em>Needs N more Saudis</em> is at the group's size today: the Saudis its percentage requires of that many people, less the Saudis it has.
    @if ($overall['unknown'] > 0)
        <strong>{{ $overall['unknown'] }} {{ \Illuminate\Support\Str::plural('person', $overall['unknown']) }}</strong> have no nationality on record and are counted as not Saudi.
    @endif
</div>

{{-- ── The groups ───────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Professional group</th>
                    <th class="text-end">People</th>
                    <th class="text-end">Saudis</th>
                    <th style="min-width:170px">Saudi share</th>
                    <th class="text-end">Required now</th>
                    <th>Upcoming</th>
                    <th>Future</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($groups as $row)
                    @php
                        $group = $row['group'];
                        $steps = collect($row['announced'])->keyBy('key');
                    @endphp
                    <tr @class(['table-warning' => $row['compliant'] === false])>
                        <td>
                            <div class="fw-semibold" dir="rtl" lang="ar" style="text-align:left">{{ $group->name_ar }}</div>
                            <div class="small text-muted">
                                {{ $group->name_en }}
                                @if ($group->job_category)
                                    @if ($group->name_en) · @endif
                                    @canroute('admin.people.breakdown')
                                        <a href="{{ $who($group->job_category) }}" class="text-decoration-none" title="Who is in this group">{{ $group->job_category }}</a>
                                    @else
                                        {{ $group->job_category }}
                                    @endcanroute
                                @else
                                    <span class="text-warning-emphasis">No job category chosen</span>
                                @endif
                            </div>
                        </td>
                        <td class="text-end">{{ number_format($row['people']) }}</td>
                        <td class="text-end">{{ number_format($row['saudis']) }}</td>
                        <td>
                            @if ($row['share'] === null)
                                <span class="text-muted">—</span>
                            @else
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1 position-relative" style="height:8px;overflow:visible" role="progressbar" aria-valuenow="{{ round($row['share']) }}" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar {{ $row['compliant'] ? 'bg-success' : 'bg-danger' }} rounded" style="width:{{ round($row['share'], 1) }}%"></div>
                                        {{-- Where the required percentage sits. --}}
                                        <span class="position-absolute bg-dark" style="left:{{ min(100, $row['required']) }}%;top:-3px;width:2px;height:14px" title="Required: {{ $pct($row['required']) }}"></span>
                                    </div>
                                    <span class="fw-semibold text-end" style="width:52px">{{ $pct($row['share']) }}</span>
                                </div>
                            @endif
                        </td>
                        <td class="text-end fw-semibold">{{ $pct($row['required']) }}</td>
                        @foreach (['next', 'future'] as $key)
                            <td class="small">
                                @if ($step = $steps->get($key))
                                    <span class="fw-semibold">{{ $pct($step['percent']) }}</span>
                                    @if ($step['from'])
                                        <span class="text-muted">from {{ $month($step['from']) }}</span>
                                    @endif
                                    <div>
                                        @if ($step['meets'] === true)
                                            <span class="text-success"><i class="bi bi-check-circle me-1"></i>met today</span>
                                        @elseif ($step['meets'] === false)
                                            <span class="text-danger"><i class="bi bi-x-circle me-1"></i>needs {{ $step['short_by'] }} more</span>
                                        @endif
                                        @if ($step['arrived'])
                                            <span class="badge bg-warning text-dark ms-1" title="This month has arrived. If the percentage now applies, make it the current one on Edit targets.">due</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td>
                            @if ($row['compliant'] === true)
                                <span class="badge bg-success">Compliant</span>
                                <div class="small text-muted" dir="rtl" lang="ar" style="text-align:left">ملتزمة بالتوطين</div>
                            @elseif ($row['compliant'] === false)
                                <span class="badge bg-danger">Not compliant</span>
                                <div class="small text-muted" dir="rtl" lang="ar" style="text-align:left">غير ملتزمة بالتوطين</div>
                                <div class="small text-danger" title="{{ rtrim(rtrim(number_format($row['required'], 2), '0'), '.') }}% of {{ $row['people'] }} people is {{ $row['saudis_required'] }} Saudis; the group has {{ $row['saudis'] }}.">
                                    needs {{ $row['short_by'] }} more {{ \Illuminate\Support\Str::plural('Saudi', $row['short_by']) }}
                                </div>
                            @else
                                <span class="badge bg-light text-muted border">Nobody in this group</span>
                            @endif
                            @if ($row['unknown'] > 0)
                                <div class="small text-muted">{{ $row['unknown'] }} with no nationality</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5">No groups yet. Add them on Edit targets.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ── Everybody no group counts ────────────────────────────── --}}
@if ($outside->isNotEmpty())
    <div class="card shadow-sm border-0">
        <div class="card-header bg-transparent">
            <strong>Counted by no group</strong>
            <small class="text-muted ms-1">People in Oracle's list whose job category no group above counts.</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Oracle job category</th><th class="text-end">People</th><th class="text-end">Saudis</th><th class="text-end">Saudi share</th></tr>
                </thead>
                <tbody>
                    @foreach ($outside as $count)
                        <tr>
                            <td>
                                @if ($count['job_category'] === null)
                                    <span class="text-muted fst-italic">No job category in Oracle</span>
                                @else
                                    {{ $count['job_category'] }}
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($count['people']) }}</td>
                            <td class="text-end">{{ number_format($count['saudis']) }}</td>
                            <td class="text-end">{{ $pct($count['share']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
