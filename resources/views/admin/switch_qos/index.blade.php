@extends('layouts.admin')
@section('title', 'Switch QoS Stats')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-speedometer2 me-2 text-primary"></i>Switch QoS Stats</h4>
        <small class="text-muted">Cisco MLS QoS per-interface queue counters</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.switch-qos.dashboard') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="{{ route('admin.switch-qos.export', request()->query()) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
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
        <input type="text" name="device_name" class="form-control form-control-sm" placeholder="Device name" value="{{ request('device_name') }}">
    </div>
    <div class="col-auto">
        <input type="text" name="device_ip" class="form-control form-control-sm" placeholder="Device IP" value="{{ request('device_ip') }}">
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
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="drops_only" value="1" id="dropsOnly" {{ request('drops_only') ? 'checked' : '' }}>
            <label class="form-check-label small" for="dropsOnly">Drops only</label>
        </div>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.switch-qos.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($stats->isEmpty())
        <div class="text-center py-5 text-muted"><i class="bi bi-speedometer2 display-4 d-block mb-2"></i>No QoS stats found.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Device</th><th>IP</th><th>Interface</th>
                        <th>Q0</th><th>Q1</th><th>Q2</th><th>Q3</th>
                        <th>Policer OoP</th>
                        <th>Total Drops</th><th>Polled At</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($stats as $s)
                    @php
                        $q0 = $s->q0_t1_drop + $s->q0_t2_drop + $s->q0_t3_drop;
                        $q1 = $s->q1_t1_drop + $s->q1_t2_drop + $s->q1_t3_drop;
                        $q2 = $s->q2_t1_drop + $s->q2_t2_drop + $s->q2_t3_drop;
                        $q3 = $s->q3_t1_drop + $s->q3_t2_drop + $s->q3_t3_drop;
                    @endphp
                    <tr>
                        <td class="fw-semibold">{{ $s->device_name }}</td>
                        <td class="font-monospace text-muted small">{{ $s->device_ip }}</td>
                        <td class="font-monospace small text-muted">{{ $s->interface_name }}</td>
                        <td>{{ number_format($q0) }}</td>
                        <td>{{ number_format($q1) }}</td>
                        <td>{{ number_format($q2) }}</td>
                        <td>{{ number_format($q3) }}</td>
                        <td class="text-muted">{{ number_format($s->policer_out_of_profile) }}</td>
                        <td>
                            <span class="badge bg-{{ $s->total_drops >= 1000 ? 'danger' : ($s->total_drops >= 100 ? 'warning text-dark' : ($s->total_drops > 0 ? 'info' : 'secondary')) }}">
                                {{ number_format($s->total_drops) }}
                            </span>
                        </td>
                        <td class="text-muted small">{{ $s->polled_at?->format('d M H:i') ?: '—' }}</td>
                        <td>
                            <a href="{{ route('admin.switch-qos.device', urlencode($s->device_ip)) }}" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a>
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
