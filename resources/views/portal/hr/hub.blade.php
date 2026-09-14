@extends('layouts.hr')

@section('title', 'HR Portal')

@push('head')
<style>
    /* Greeting and tiles, as on the employee home portal. */
    .hr-greeting{ margin:0 0 26px 2px; }
    .hr-greeting h2{ font-size:clamp(22px, 3vw, 30px); font-weight:700; letter-spacing:-.2px; color:var(--ink); margin:0; }
    .hr-greeting h2 .name{ color:var(--red-600); }
    .hr-greeting .line{ margin:6px 0 0; font-size:14.5px; color:var(--ink-soft); }
    .hr-greeting .meta{
        margin-top:10px; font-size:12.5px; color:var(--gray-500);
        display:flex; align-items:center; gap:14px; flex-wrap:wrap;
    }
    .hr-greeting .meta span{ display:inline-flex; align-items:center; gap:6px; }

    .hr-tiles{ display:grid; grid-template-columns:repeat(4, 1fr); gap:20px; }
    .hr-tile{
        position:relative;
        display:flex; flex-direction:column; align-items:flex-start; gap:14px;
        background:var(--card);
        border:1px solid var(--line);
        border-radius:18px;
        box-shadow:var(--shadow);
        padding:24px 22px;
        min-height:176px;
        overflow:hidden;
        text-decoration:none; color:inherit;
        transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease;
    }
    .hr-tile::after{
        content:"";
        position:absolute; left:0; right:0; bottom:0;
        height:3px;
        background:linear-gradient(90deg, var(--red-600), var(--red-500));
        transform:scaleX(0); transform-origin:left;
        transition:transform .3s ease;
    }
    .hr-tile:hover{ transform:translateY(-4px); box-shadow:var(--shadow-hover); border-color:transparent; color:inherit; }
    .hr-tile:hover::after{ transform:scaleX(1); }
    .hr-tile:focus-visible{ outline:2px solid var(--red-500); outline-offset:3px; }
    .hr-tile .icon-wrap{
        width:54px; height:54px; border-radius:14px;
        background:var(--red-100); color:var(--red-600);
        display:flex; align-items:center; justify-content:center;
        font-size:26px; flex-shrink:0;
    }
    .hr-tile h3{ font-size:16.5px; font-weight:600; color:var(--ink); letter-spacing:.1px; margin:0; }
    .hr-tile p{ font-size:13px; line-height:1.5; color:var(--ink-soft); margin:-6px 0 0; }
    .hr-tile .count{
        position:absolute; top:18px; right:18px;
        font-size:10.5px; font-weight:700; letter-spacing:.4px;
        color:#fff; background:var(--red-600);
        border-radius:20px; padding:3px 9px;
    }

    @media (max-width:1180px){ .hr-tiles{ grid-template-columns:repeat(2, 1fr); } }
    @media (max-width:640px){ .hr-tiles{ grid-template-columns:1fr; } }
    @media (prefers-reduced-motion:reduce){ .hr-tile, .hr-tile::after{ transition:none; } }
</style>
@endpush

@section('content')
@php
    $displayName = trim(auth()->user()->name ?? '');
    $firstName   = explode(' ', $displayName)[0] ?: 'there';
    $hour        = (int) now()->format('G');
    $greeting    = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $openTotal   = (int) collect($openCounts)->sum();
@endphp

<div class="hr-greeting">
    <h2>{{ $greeting }}, <span class="name">{{ $firstName }}</span></h2>
    <p class="line">Raise a request here and IT takes it from there — nothing changes until they approve it.</p>
    <div class="meta">
        <span><i class="bi bi-calendar3"></i>{{ now()->format('l, j F Y') }}</span>
        <span><i class="bi bi-people"></i>{{ number_format($employeeCount) }} active employees</span>
        @if($openTotal > 0)
            <span><i class="bi bi-hourglass-split"></i>{{ $openTotal }} open {{ \Illuminate\Support\Str::plural('request', $openTotal) }}</span>
        @endif
    </div>
</div>

<p class="section-label">Requests</p>
<div class="hr-tiles">
    @can('submit-hr-onboarding')
        <a href="{{ route('portal.hr.onboarding.index') }}" class="hr-tile">
            @if(($openCounts['create_user'] ?? 0) > 0)
                <span class="count">{{ $openCounts['create_user'] }} open</span>
            @endif
            <span class="icon-wrap"><i class="bi bi-person-plus"></i></span>
            <h3>Onboard a New Hire</h3>
            <p>Request an account, licences, extension and equipment for someone joining.</p>
        </a>
    @endcan

    @can('submit-hr-offboarding')
        <a href="{{ route('portal.hr.offboarding.index') }}" class="hr-tile">
            @if(($openCounts['employee_offboarding'] ?? 0) > 0)
                <span class="count">{{ $openCounts['employee_offboarding'] }} open</span>
            @endif
            <span class="icon-wrap"><i class="bi bi-person-dash"></i></span>
            <h3>Terminate / Offboard</h3>
            <p>Start the leaver process — the manager decides on mailbox, laptop and assets.</p>
        </a>
    @endcan

    @can('submit-hr-employee-update')
        <a href="{{ route('portal.hr.employee-update.index') }}" class="hr-tile">
            @if(($openCounts['employee_update'] ?? 0) > 0)
                <span class="count">{{ $openCounts['employee_update'] }} open</span>
            @endif
            <span class="icon-wrap"><i class="bi bi-pencil-square"></i></span>
            <h3>Update Employee Data</h3>
            <p>Request a change to a job title, department, branch, manager or phone.</p>
        </a>
    @endcan

    <a href="{{ route('portal.hr.requests') }}" class="hr-tile">
        <span class="icon-wrap"><i class="bi bi-list-check"></i></span>
        <h3>All My Requests</h3>
        <p>Everything you have raised, and where each one currently sits.</p>
    </a>
</div>

<p class="section-label" style="margin-top:34px;">Recent activity</p>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Your latest requests</span>
        <a href="{{ route('portal.hr.requests') }}" class="btn btn-sm btn-outline-secondary">View all</a>
    </div>
    @if($recent->isEmpty())
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox display-6 text-muted"></i>
            <p class="text-muted mt-2 mb-0">Nothing raised yet. Pick a request above to get started.</p>
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
                        <td>
                            <a href="{{ route('portal.hr.requests.show', $r->id) }}" class="fw-semibold text-decoration-none">
                                {{ $r->title }}
                            </a>
                        </td>
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
