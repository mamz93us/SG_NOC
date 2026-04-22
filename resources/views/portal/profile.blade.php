@extends('layouts.portal')

@section('title', 'My Profile')

@section('content')
<style>
    .profile-hero {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: #fff;
        border-radius: 22px;
        padding: 26px 30px;
        margin-bottom: 24px;
        box-shadow: 0 10px 26px rgba(245, 87, 108, 0.25);
    }
    .profile-hero .avatar-lg {
        width: 84px; height: 84px; border-radius: 50%;
        background: rgba(255,255,255,.2);
        border: 3px solid rgba(255,255,255,.4);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 32px; font-weight: 700;
    }
    .profile-hero h3 { margin: 0; font-weight: 700; }
    .profile-hero .source-chip {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(255,255,255,.2);
        padding: 4px 12px; border-radius: 100px;
        font-size: 12px; font-weight: 600;
    }

    .info-list { list-style: none; padding: 0; margin: 0; }
    .info-list li {
        display: flex; justify-content: space-between; align-items: center;
        padding: 12px 0;
        border-bottom: 1px solid var(--bs-border-color-translucent);
    }
    .info-list li:last-child { border-bottom: none; }
    .info-list .label { color: var(--bs-secondary-color); font-size: 13px; font-weight: 500; }
    .info-list .value { font-weight: 600; text-align: right; max-width: 60%; word-break: break-word; }
    .info-list .value.empty { color: var(--bs-secondary-color); font-weight: 400; font-style: italic; }
    .info-list .locked  { color: var(--bs-secondary-color); font-size: 11px; margin-left: 6px; }

    .diff-row { font-size: 13px; }
    .diff-row .from { text-decoration: line-through; color: var(--bs-danger); }
    .diff-row .arrow { color: var(--bs-secondary-color); margin: 0 6px; }
    .diff-row .to { color: var(--bs-success); font-weight: 600; }
</style>

@php
    $displayName = $user->name ?? '?';
    $initials    = strtoupper(substr($displayName, 0, 1));
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="{{ route('portal.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Portal
    </a>
</div>

<div class="profile-hero d-flex flex-column flex-md-row align-items-md-center gap-3">
    <span class="avatar-lg">{{ $initials }}</span>
    <div class="flex-grow-1">
        <h3>{{ $employee->name ?? $user->name }}</h3>
        <div class="opacity-90 mt-1">
            @if($employee?->job_title) {{ $employee->job_title }} @endif
            @if($employee?->department) <span class="opacity-75">&middot; {{ $employee->department->name ?? '' }}</span> @endif
        </div>
        <div class="mt-2">
            <span class="source-chip">
                <i class="bi bi-microsoft"></i> Synced from Microsoft Entra ID
            </span>
            @if($employee)
                <span class="badge {{ $employee->statusBadgeClass() }} ms-2">{{ ucfirst($employee->status ?? 'unknown') }}</span>
            @endif
        </div>
    </div>
</div>

@if(!$employee)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Your account (<strong>{{ $user->email }}</strong>) is not linked to an employee record yet.
        Ask IT to create your employee profile — once linked, your Azure details will appear here.
    </div>
@else

    @if($pendingRequest)
        @php $pReq = $pendingRequest->payload ?? []; @endphp
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-hourglass-split fs-5 mt-1"></i>
            <div class="flex-grow-1">
                <strong>Phone update request pending IT approval</strong>
                <div class="small text-muted mb-2">
                    Submitted {{ $pendingRequest->created_at->diffForHumans() }}
                    &middot; Step {{ $pendingRequest->current_step }}/{{ $pendingRequest->total_steps }}
                </div>
                <div class="small diff-row">
                    <strong>Phone:</strong>
                    <span class="from">{{ $pReq['old_value'] ?? '—' }}</span>
                    <span class="arrow">&rarr;</span>
                    <span class="to">{{ $pReq['new_value'] ?? '' }}</span>
                </div>
                @if(!empty($pReq['user_note']))
                    <div class="small mt-1"><em>“{{ $pReq['user_note'] }}”</em></div>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- ─── Left: Current (Azure) Info ─── --}}
        <div class="col-12 col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2 text-primary"></i>Employee Information</h5>
                    <small class="text-muted">Synced from Microsoft Entra ID. Managed by IT — not editable here.</small>
                </div>
                <div class="card-body">
                    <ul class="info-list">
                        <li>
                            <span class="label">Full name <i class="bi bi-lock-fill locked"></i></span>
                            <span class="value {{ !$employee->name ? 'empty' : '' }}">{{ $employee->name ?: '—' }}</span>
                        </li>
                        <li>
                            <span class="label">Email <i class="bi bi-lock-fill locked"></i></span>
                            <span class="value">{{ $employee->email }}</span>
                        </li>
                        <li>
                            <span class="label">Job title <i class="bi bi-lock-fill locked"></i></span>
                            <span class="value {{ !$employee->job_title ? 'empty' : '' }}">{{ $employee->job_title ?: 'Not set' }}</span>
                        </li>
                        <li>
                            <span class="label">Department <i class="bi bi-lock-fill locked"></i></span>
                            <span class="value {{ !$employee->department ? 'empty' : '' }}">{{ $employee->department->name ?? 'Not set' }}</span>
                        </li>
                        <li>
                            <span class="label">Branch <i class="bi bi-lock-fill locked"></i></span>
                            <span class="value {{ !$employee->branch ? 'empty' : '' }}">{{ $employee->branch->name ?? 'Not set' }}</span>
                        </li>
                        <li>
                            <span class="label">Manager <i class="bi bi-lock-fill locked"></i></span>
                            <span class="value {{ !$employee->manager ? 'empty' : '' }}">{{ $employee->manager->name ?? 'Not set' }}</span>
                        </li>
                        <li>
                            <span class="label">Extension <i class="bi bi-lock-fill locked"></i></span>
                            <span class="value {{ !$employee->extension_number ? 'empty' : '' }}">{{ $employee->extension_number ?: 'Not set' }}</span>
                        </li>
                        <li>
                            <span class="label">Phone <i class="bi bi-pencil-square text-primary ms-1" title="Editable — with IT approval"></i></span>
                            <span class="value {{ !$employee->contact?->phone ? 'empty' : '' }}">{{ $employee->contact?->phone ?: 'Not set' }}</span>
                        </li>
                        <li>
                            <span class="label">Hired date <i class="bi bi-lock-fill locked"></i></span>
                            <span class="value {{ !$employee->hired_date ? 'empty' : '' }}">
                                {{ $employee->hired_date?->format('M j, Y') ?: 'Not set' }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- ─── Right: Phone change request ─── --}}
        <div class="col-12 col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pt-3 pb-0">
                    <h5 class="mb-0"><i class="bi bi-telephone-outbound me-2 text-primary"></i>Request Phone Update</h5>
                    <small class="text-muted">Only your phone number can be changed — and only after IT approval.</small>
                </div>
                <div class="card-body">
                    @if($pendingRequest)
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-hourglass-split display-6 d-block mb-2 text-warning"></i>
                            You already have a pending request.<br>
                            Wait for IT to review it before submitting another.
                        </div>
                    @else
                        <form method="POST" action="{{ route('portal.profile.edit-request') }}">
                            @csrf

                            @if($errors->any())
                                <div class="alert alert-danger py-2 small mb-3">{{ $errors->first() }}</div>
                            @endif

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Current phone</label>
                                <input type="text" class="form-control" value="{{ $employee->contact?->phone ?: '—' }}" disabled>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">New phone <span class="text-danger">*</span></label>
                                <input type="text" name="phone" class="form-control"
                                       value="{{ old('phone') }}"
                                       placeholder="+966 …" required>
                                <div class="form-text">Include country code if possible.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-semibold">Note to IT <span class="text-muted fw-normal">(optional)</span></label>
                                <textarea name="note" class="form-control" rows="3"
                                          placeholder="Why are you requesting this change? e.g. got a new work phone, transferred to another branch.">{{ old('note') }}</textarea>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-send me-1"></i>Submit to IT
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if($recentRequests->isNotEmpty())
        <div class="card shadow-sm mt-3">
            <div class="card-header bg-transparent border-0 pt-3 pb-0">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>My Previous Requests</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Submitted</th>
                                <th>Change</th>
                                <th>Status</th>
                                <th class="pe-3">Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentRequests as $r)
                                @php $p = $r->payload ?? []; @endphp
                                <tr>
                                    <td class="ps-3 small text-muted">{{ $r->created_at->diffForHumans() }}</td>
                                    <td class="small diff-row">
                                        <strong>Phone:</strong>
                                        <span class="from">{{ $p['old_value'] ?? '—' }}</span>
                                        <span class="arrow">&rarr;</span>
                                        <span class="to">{{ $p['new_value'] ?? '' }}</span>
                                    </td>
                                    <td><span class="badge {{ $r->statusBadgeClass() }}">{{ ucfirst($r->status) }}</span></td>
                                    <td class="pe-3 small text-muted">Step {{ $r->current_step }}/{{ $r->total_steps }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

@endif
@endsection
