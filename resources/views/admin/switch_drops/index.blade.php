@extends('layouts.admin')
@section('title', 'Switch Drop Stats')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3-fill me-2 text-danger"></i>Switch Drop Stats</h4>
        <small class="text-muted">All SNMP-polled interface drop and error counters</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.switch-drops.dashboard') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="{{ route('admin.switch-drops.export', request()->query()) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
</div>

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <select name="branch" class="form-select form-select-sm">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b }}" {{ request('branch') == $b ? 'selected' : '' }}>{{ $b }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <input type="text" name="device_name" class="form-control form-control-sm" placeholder="Device name" value="{{ request('device_name') }}">
    </div>
    <div class="col-auto">
        <input type="text" name="interface" class="form-control form-control-sm" placeholder="Interface" value="{{ request('interface') }}">
    </div>
    <div class="col-auto">
        <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
    </div>
    <div class="col-auto">
        <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.switch-drops.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($stats->isEmpty())
        <div class="text-center py-5 text-muted"><i class="bi bi-diagram-3 display-4 d-block mb-2"></i>No switch drop stats found.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Device</th><th>IP</th><th>Branch</th><th>Interface</th>
                        <th>In Disc</th><th>Out Disc</th><th>In Err</th><th>Out Err</th>
                        <th>CRC</th><th>Total Drops</th><th>Polled At</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($stats as $s)
                    @php $total = $s->in_discards + $s->out_discards + $s->in_errors + $s->out_errors; @endphp
                    <tr>
                        <td class="fw-semibold">{{ $s->device_name }}</td>
                        <td class="font-monospace text-muted small">{{ $s->device_ip }}</td>
                        <td>{{ $s->branch ?: '—' }}</td>
                        <td class="font-monospace small text-muted">{{ $s->interface_name ?: '—' }}</td>
                        <td class="text-muted">{{ number_format($s->in_discards) }}</td>
                        <td class="text-muted">{{ number_format($s->out_discards) }}</td>
                        <td class="text-muted">{{ number_format($s->in_errors) }}</td>
                        <td class="text-muted">{{ number_format($s->out_errors) }}</td>
                        <td class="text-muted">{{ number_format($s->crc_errors) }}</td>
                        <td>
                            <span class="badge bg-{{ $total >= 500 ? 'danger' : ($total >= 100 ? 'warning text-dark' : 'secondary') }}">
                                {{ number_format($total) }}
                            </span>
                        </td>
                        <td class="text-muted small">{{ $s->polled_at?->format('d M H:i') ?: '—' }}</td>
                        <td>
                            <a href="{{ route('admin.switch-drops.device', urlencode($s->device_ip)) }}" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $stats->links() }}</div>
        @endif
    </div>
</div>
@endsection
