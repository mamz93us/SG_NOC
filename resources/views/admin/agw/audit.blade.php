@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-eye me-2 text-primary"></i>Access Gateway — Audit</h4>
        <small class="text-muted">Every request decision made by the gateway fronting the legacy app</small>
    </div>
    <a href="{{ route('admin.access-gateway.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Gateway
    </a>
</div>

{{-- ── Filters ──────────────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('admin.access-gateway.audit') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Client IP</label>
                <input type="text" name="ip" class="form-control form-control-sm" value="{{ request('ip') }}" placeholder="197.1.2.3">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Decision</label>
                <select name="decision" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="allow" @selected(request('decision')==='allow')>allow</option>
                    <option value="deny_ip" @selected(request('decision')==='deny_ip')>deny_ip</option>
                    <option value="deny_auth" @selected(request('decision')==='deny_auth')>deny_auth</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="{{ request('from') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="{{ request('to') }}">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="{{ route('admin.access-gateway.audit') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

{{-- ── Results ──────────────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($events->isEmpty())
            <div class="text-center text-muted py-4">No audit events match.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Time</th>
                        <th>Client IP</th>
                        <th>User</th>
                        <th>Method</th>
                        <th>Path</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Decision</th>
                        <th class="text-end pe-3">Latency</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($events as $e)
                    <tr>
                        <td class="ps-3 text-nowrap">{{ $e->ts?->format('Y-m-d H:i:s') }}</td>
                        <td><code>{{ $e->client_ip }}</code></td>
                        <td class="text-muted">{{ $e->user_email ?: '—' }}</td>
                        <td>{{ $e->method }}</td>
                        <td class="text-truncate" style="max-width: 320px;" title="{{ $e->path }}">{{ $e->path }}</td>
                        <td class="text-center">{{ $e->status ?: '—' }}</td>
                        <td class="text-center">
                            @if($e->decision === 'allow')
                                <span class="badge bg-success">allow</span>
                            @elseif($e->decision === 'deny_ip')
                                <span class="badge bg-danger">deny_ip</span>
                            @else
                                <span class="badge bg-warning text-dark">{{ $e->decision }}</span>
                            @endif
                        </td>
                        <td class="text-end pe-3">{{ $e->latency_ms !== null ? $e->latency_ms.' ms' : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @if($events->hasPages())
    <div class="card-footer bg-transparent">
        {{ $events->links() }}
    </div>
    @endif
</div>

@endsection
