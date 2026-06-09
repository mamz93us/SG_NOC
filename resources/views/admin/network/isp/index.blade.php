@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-globe2 me-2 text-primary"></i>ISP Connections</h4>
        <small class="text-muted">Internet circuits and WAN links across branches</small>
    </div>
    @can('manage-network-settings')
    <a href="{{ route('admin.network.isp.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Add ISP
    </a>
    @endcan
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Provider / Circuit / IP" value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="branch" class="form-select form-select-sm">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b->id }}" {{ request('branch') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.network.isp.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

{{-- Consolidated billing accounts: one payer covering several services --}}
@if(!empty($billingGroups) && $billingGroups->count())
<div class="card shadow-sm mb-3">
    <div class="card-header bg-light py-2">
        <i class="bi bi-receipt me-1 text-primary"></i><strong>Billing accounts</strong>
        <small class="text-muted">— accounts that pay for multiple services</small>
    </div>
    <div class="card-body py-2">
        <div class="row g-2">
            @foreach($billingGroups as $g)
            <div class="col-md-4">
                <a href="{{ route('admin.network.isp.index', ['search' => $g->billing_account_number]) }}"
                   class="d-block border rounded p-2 text-decoration-none text-dark h-100">
                    <div class="font-monospace small text-primary">{{ $g->billing_account_number }}</div>
                    <div class="d-flex justify-content-between mt-1">
                        <span class="badge bg-secondary-subtle text-secondary">{{ $g->services }} service(s)</span>
                        <span class="fw-bold">{{ number_format((float) $g->total, 2) }} {{ $g->currency ?: 'SAR' }}/mo</span>
                    </div>
                </a>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($connections->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-globe2 display-4 d-block mb-2"></i>No ISP connections found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Branch</th>
                        <th>Provider</th>
                        <th>Account / Use</th>
                        <th>Speed</th>
                        <th>Static IP</th>
                        <th>Gateway</th>
                        <th>Router</th>
                        <th>Contract</th>
                        <th>Renewal</th>
                        <th>Cost</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($connections as $c)
                    <tr>
                        <td class="fw-semibold">{{ $c->branch?->name ?: '—' }}</td>
                        <td>{{ $c->provider }}</td>
                        <td>
                            <div class="font-monospace">{{ $c->account_number ?: '—' }}</div>
                            @if($c->purpose)<div class="text-muted" style="font-size:11px">{{ $c->purpose }}</div>@endif
                            @if($c->billing_account_number)
                            <div style="font-size:10px" title="Billed under {{ $c->billing_account_number }}">
                                <i class="bi bi-receipt text-primary"></i>
                                <span class="text-muted font-monospace">→ {{ $c->billing_account_number }}</span>
                            </div>
                            @endif
                        </td>
                        <td>{{ $c->speedLabel() }}</td>
                        <td class="font-monospace">{{ $c->static_ip ?: '—' }}</td>
                        <td class="font-monospace text-muted">{{ $c->gateway ?: '—' }}</td>
                        <td>{{ $c->routerDevice?->name ?: '—' }}</td>
                        <td>
                            <span class="badge {{ $c->contractStatusBadge() }}">{{ $c->contractStatusLabel() }}</span>
                            @if($c->contract_end)
                            <div class="text-muted" style="font-size:10px">{{ $c->contract_end->format('M d, Y') }}</div>
                            @endif
                        </td>
                        <td>
                            @php($nextRenew = $c->nextRenewalDate())
                            @if($nextRenew)
                            <span class="badge {{ $c->renewalStatusBadge() }}">{{ $c->renewalStatusLabel() }}</span>
                            <div class="text-muted" style="font-size:10px">
                                {{ $nextRenew->format('M d, Y') }}
                                @if($c->billing_day) <span title="Repeats monthly">⟳</span>@endif
                            </div>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $c->costLabel() }}</td>
                        <td class="text-nowrap">
                            @can('manage-network-settings')
                            <a href="{{ route('admin.network.isp.edit', $c) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('admin.network.isp.destroy', $c) }}" class="d-inline" onsubmit="return confirm('Delete this ISP connection?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $connections->links() }}</div>
        @endif
    </div>
</div>

@endsection
