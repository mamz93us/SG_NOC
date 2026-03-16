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
                        <th>Circuit ID</th>
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
                        <td class="text-muted font-monospace">{{ $c->circuit_id ?: '—' }}</td>
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
                            @if($c->renewal_date)
                            <span class="badge {{ $c->renewalStatusBadge() }}">{{ $c->renewalStatusLabel() }}</span>
                            <div class="text-muted" style="font-size:10px">{{ $c->renewal_date->format('M d, Y') }}</div>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $c->monthly_cost ? number_format($c->monthly_cost, 2) : '—' }}</td>
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
