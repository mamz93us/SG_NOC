@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-clipboard-data me-2 text-primary"></i>ISP Report</h4>
        <small class="text-muted">All ISP connections grouped by branch with renewal dates and costs</small>
    </div>
    <a href="{{ route('admin.network.isp-report.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-download me-1"></i>Export CSV
    </a>
</div>

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <select name="branch_id" class="form-select form-select-sm">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="provider" class="form-select form-select-sm">
            <option value="">All Providers</option>
            @foreach($providers as $p)
            <option value="{{ $p }}" {{ request('provider') == $p ? 'selected' : '' }}>{{ $p }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <input type="text" name="account_number" class="form-control form-control-sm" placeholder="Account #" value="{{ request('account_number') }}">
    </div>
    <div class="col-auto">
        <select name="connection_type" class="form-select form-select-sm">
            <option value="">All Connection Types</option>
            @foreach($connectionTypes as $t)
            <option value="{{ $t }}" {{ request('connection_type') == $t ? 'selected' : '' }}>{{ strtoupper($t) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="customer_type" class="form-select form-select-sm">
            <option value="">All Customer Types</option>
            @foreach($customerTypes as $t)
            <option value="{{ $t }}" {{ request('customer_type') == $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.network.isp-report.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

@if($connections->isEmpty())
<div class="card shadow-sm"><div class="card-body text-center py-5 text-muted">
    <i class="bi bi-globe2 display-4 d-block mb-2"></i>No ISP connections match the filters.
</div></div>
@else
@foreach($byBranch as $branchName => $items)
@php($branchTotal = $items->sum('monthly_cost'))
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between">
        <strong><i class="bi bi-building me-1"></i>{{ $branchName }}</strong>
        <span class="text-muted small">{{ $items->count() }} connection(s) — Branch total: <strong>{{ number_format($branchTotal, 2) }}</strong></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 small align-middle">
            <thead class="table-light">
                <tr>
                    <th>Provider</th>
                    <th>Account #</th>
                    <th>Connection</th>
                    <th>Customer</th>
                    <th>Package</th>
                    <th>Payment</th>
                    <th>Billing Day</th>
                    <th>Monthly Cost</th>
                    <th>Renewal</th>
                </tr>
            </thead>
            <tbody>
            @foreach($items as $c)
                <tr>
                    <td class="fw-semibold">{{ $c->provider }}</td>
                    <td class="font-monospace">{{ $c->account_number ?: '—' }}</td>
                    <td>{{ $c->connection_type ? strtoupper($c->connection_type) : '—' }}</td>
                    <td>{{ $c->customer_type ? ucfirst($c->customer_type) : '—' }}</td>
                    <td>{{ $c->package ?: '—' }}</td>
                    <td>{{ $c->payment_type ? ucfirst($c->payment_type) : '—' }}</td>
                    <td>{{ $c->billing_day ?: '—' }}</td>
                    <td>{{ $c->monthly_cost ? number_format($c->monthly_cost, 2) : '—' }}</td>
                    <td>
                        @php($nextRenew = $c->nextRenewalDate())
                        @if($nextRenew)
                        <span class="badge {{ $c->renewalStatusBadge() }}">{{ $c->renewalStatusLabel() }}</span>
                        <div class="text-muted" style="font-size:10px">
                            {{ $nextRenew->format('Y-m-d') }}
                            @if($c->billing_day) <span title="Repeats every {{ $c->billing_day }}th">⟳</span>@endif
                        </div>
                        @else <span class="text-muted">—</span>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endforeach

<div class="alert alert-primary text-end">
    <strong>Total monthly cost (all filtered ISPs):</strong> {{ number_format($totalCost, 2) }}
</div>
@endif

@endsection
