@extends('layouts.hr')

@section('title', 'HR Portal')

@section('content')
<style>
    .hr-hero {
        background: linear-gradient(135deg, #4a00e0 0%, #8e2de2 100%);
        color: #fff;
        border-radius: 22px;
        padding: 32px 36px;
        margin-bottom: 28px;
        box-shadow: 0 12px 30px rgba(74, 0, 224, 0.25);
    }
    [data-bs-theme="dark"] .hr-hero { box-shadow: 0 12px 30px rgba(0,0,0,.4); }
    .hr-hero h2 { font-size: 26px; font-weight: 700; margin: 0 0 4px; }
    .hr-hero p  { margin: 0; opacity: .92; }
    .hr-hero .hero-avatar {
        width: 64px; height: 64px; border-radius: 50%;
        background: rgba(255,255,255,.18);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 24px; font-weight: 700;
        border: 3px solid rgba(255,255,255,.3);
    }

    .hr-tile {
        position: relative;
        display: flex; flex-direction: column; gap: 8px;
        padding: 26px 22px 22px;
        border-radius: 18px;
        color: #fff; text-decoration: none;
        min-height: 200px;
        box-shadow: 0 6px 18px rgba(0,0,0,.10);
        transition: transform .2s ease, box-shadow .2s ease;
        overflow: hidden;
    }
    .hr-tile::after {
        content: "";
        position: absolute; inset: -30% -30% auto auto;
        width: 180px; height: 180px; border-radius: 50%;
        background: rgba(255,255,255,.10);
        transition: transform .3s ease;
    }
    .hr-tile:hover { transform: translateY(-5px); box-shadow: 0 16px 34px rgba(0,0,0,.18); color: #fff; }
    .hr-tile:hover::after { transform: scale(1.1); }
    .hr-tile .tile-icon  { font-size: 36px; z-index: 1; }
    .hr-tile .tile-title { font-size: 19px; font-weight: 700; margin: 0; z-index: 1; }
    .hr-tile .tile-desc  { font-size: 13px; opacity: .94; margin: 0; z-index: 1; line-height: 1.4; }
    .hr-tile .tile-badge {
        position: absolute; top: 14px; right: 14px;
        background: rgba(255,255,255,.22);
        border: 1px solid rgba(255,255,255,.35);
        border-radius: 100px;
        padding: 3px 10px; font-size: 11px; font-weight: 600;
        z-index: 2; backdrop-filter: blur(4px);
    }

    .tile-onboard  { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
    .tile-offboard { background: linear-gradient(135deg, #f5576c 0%, #f093fb 100%); }
    .tile-update   { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .tile-requests { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
</style>

@php
    $displayName = auth()->user()->name ?? 'there';
    $firstName   = explode(' ', trim($displayName))[0] ?? $displayName;
    $initials    = strtoupper(substr($displayName, 0, 1));
    $hour        = (int) now()->format('G');
    $greeting    = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
@endphp

<div class="hr-hero d-flex flex-column flex-md-row align-items-md-center gap-3">
    <span class="hero-avatar">{{ $initials }}</span>
    <div>
        <h2>{{ $greeting }}, {{ $firstName }}</h2>
        <p>Raise a request here and IT takes it from there — nothing is changed until they approve it.</p>
    </div>
    <div class="ms-md-auto small text-md-end">
        <div class="opacity-75">{{ now()->format('l, F j') }}</div>
        <div><i class="bi bi-people me-1"></i>{{ number_format($employeeCount) }} active employees</div>
    </div>
</div>

<div class="row g-3">
    @can('submit-hr-onboarding')
    <div class="col-12 col-sm-6 col-lg-3">
        <a href="{{ route('portal.hr.onboarding.index') }}" class="hr-tile tile-onboard">
            @if(($openCounts['create_user'] ?? 0) > 0)
                <span class="tile-badge">{{ $openCounts['create_user'] }} open</span>
            @endif
            <i class="bi bi-person-plus-fill tile-icon"></i>
            <h5 class="tile-title">Onboard a New Hire</h5>
            <p class="tile-desc">Request an account, licenses, extension and equipment for someone joining.</p>
        </a>
    </div>
    @endcan

    @can('submit-hr-offboarding')
    <div class="col-12 col-sm-6 col-lg-3">
        <a href="{{ route('portal.hr.offboarding.index') }}" class="hr-tile tile-offboard">
            @if(($openCounts['employee_offboarding'] ?? 0) > 0)
                <span class="tile-badge">{{ $openCounts['employee_offboarding'] }} open</span>
            @endif
            <i class="bi bi-person-dash-fill tile-icon"></i>
            <h5 class="tile-title">Terminate / Offboard</h5>
            <p class="tile-desc">Start the leaver process — the manager decides on mailbox, laptop and assets.</p>
        </a>
    </div>
    @endcan

    @can('submit-hr-employee-update')
    <div class="col-12 col-sm-6 col-lg-3">
        <a href="{{ route('portal.hr.employee-update.index') }}" class="hr-tile tile-update">
            @if(($openCounts['employee_update'] ?? 0) > 0)
                <span class="tile-badge">{{ $openCounts['employee_update'] }} open</span>
            @endif
            <i class="bi bi-pencil-square tile-icon"></i>
            <h5 class="tile-title">Update Employee Data</h5>
            <p class="tile-desc">Request a change to a job title, department, branch, manager or phone.</p>
        </a>
    </div>
    @endcan

    <div class="col-12 col-sm-6 col-lg-3">
        <a href="{{ route('portal.hr.requests') }}" class="hr-tile tile-requests">
            <i class="bi bi-list-check tile-icon"></i>
            <h5 class="tile-title">All My Requests</h5>
            <p class="tile-desc">Everything you have raised, and where each one currently sits.</p>
        </a>
    </div>
</div>

<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-clock-history me-2"></i>Recent activity</span>
        <a href="{{ route('portal.hr.requests') }}" class="btn btn-sm btn-outline-secondary">View all</a>
    </div>
    @if($recent->isEmpty())
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox display-6 text-muted"></i>
            <p class="text-muted mt-2 mb-0">Nothing raised yet. Pick an action above to get started.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Request</th>
                        <th>Type</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>Raised</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recent as $r)
                    <tr>
                        <td class="text-muted small">#{{ $r->id }}</td>
                        <td class="fw-semibold">{{ $r->title }}</td>
                        <td><span class="badge {{ $r->typeBadgeClass() }}">{{ $r->typeLabel() }}</span></td>
                        <td class="small">{{ $r->branch?->name ?? '—' }}</td>
                        <td><span class="badge {{ $r->statusBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></td>
                        <td class="text-muted small">{{ $r->created_at->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
