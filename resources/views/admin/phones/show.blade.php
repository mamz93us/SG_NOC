@extends('layouts.admin')
@section('content')

@php $macFmt = strtoupper(implode(':', str_split($mac, 2))); @endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-telephone me-2 text-primary"></i>{{ $device?->model ?? data_get($detail, 'productName', 'Phone') }}</h4>
        <small class="text-muted font-monospace">{{ $macFmt }}</small>
    </div>
    <a href="{{ route('admin.phones.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2">{{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2">{{ session('error') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button></div>
@endif
@if($detailError)
<div class="alert alert-warning py-2"><i class="bi bi-cloud-slash me-1"></i>Live device detail unavailable: {{ $detailError }}</div>
@endif

<div class="row g-3">
    {{-- Identity --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Device</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">MAC</dt><dd class="col-7 font-monospace">{{ $macFmt }}</dd>
                    <dt class="col-5">Model</dt><dd class="col-7">{{ $device?->model ?? data_get($detail, 'productName', '—') }}</dd>
                    <dt class="col-5">Serial</dt><dd class="col-7">{{ $device?->serial_number ?? data_get($detail, 'sn', '—') }}</dd>
                    <dt class="col-5">IP</dt><dd class="col-7">{{ $device?->ip_address ?? data_get($detail, 'deviceIp', '—') }}</dd>
                    <dt class="col-5">Firmware</dt><dd class="col-7">{{ $device?->firmware_version ?? data_get($detail, 'firmwareVersion', '—') }}</dd>
                    <dt class="col-5">Asset</dt>
                    <dd class="col-7">
                        @if($device)<a href="{{ route('admin.devices.show', $device->id) }}">{{ $device->asset_code }}</a>
                        @else<span class="text-muted">not in ITAM</span>@endif
                    </dd>
                    <dt class="col-5">Assigned to</dt><dd class="col-7">{{ $device?->currentAssignment?->employee?->name ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    {{-- SIP accounts --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-telephone-inbound me-1"></i>SIP Accounts <small class="text-muted fw-normal">(live from GDMS)</small></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>#</th><th>SIP User</th><th>Server</th><th>Status</th></tr></thead>
                    <tbody>
                    @forelse($accounts as $i => $a)
                        <tr>
                            <td>{{ data_get($a, 'account', data_get($a, 'accountIndex', $i + 1)) }}</td>
                            <td>{{ data_get($a, 'sipUserId', data_get($a, 'userId', '—')) }}</td>
                            <td class="small">{{ data_get($a, 'sipServer', data_get($a, 'sipServerAddr', '—')) }}</td>
                            <td>{{ data_get($a, 'accountStatus', data_get($a, 'status', '—')) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No SIP accounts reported.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@can('manage-phones')
<div class="row g-3 mt-1">
    {{-- Assign account --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-link-45deg me-1"></i>Assign / Change SIP Account</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.phones.assign-account', $mac) }}" class="row g-2">
                    @csrf
                    <div class="col-md-5">
                        <label class="form-label small mb-1">UCM Server</label>
                        <select name="ucm_server_id" class="form-select form-select-sm" required>
                            @foreach($ucmServers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Extension</label>
                        <input type="text" name="extension" class="form-control form-control-sm" placeholder="1401" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Slot</label>
                        <input type="number" name="account_index" class="form-control form-control-sm" value="1" min="1" max="16" required>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-sm btn-primary"><i class="bi bi-link me-1"></i>Assign</button>
                        <small class="text-muted ms-2">Reads the extension's secret from the UCM and binds it on the phone.</small>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Device controls --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-sliders me-1"></i>Device Controls</div>
            <div class="card-body d-flex flex-column gap-2">
                <form method="POST" action="{{ route('admin.phones.reboot', $mac) }}" onsubmit="return confirm('Reboot this phone now?')">
                    @csrf<button class="btn btn-sm btn-outline-warning w-100"><i class="bi bi-arrow-clockwise me-1"></i>Reboot</button>
                </form>
                @can('reset-phones')
                <form method="POST" action="{{ route('admin.phones.factory-reset', $mac) }}" onsubmit="return confirm('FACTORY RESET erases the phone config. It re-syncs from GDMS when it next comes online. Continue?')">
                    @csrf<button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-exclamation-octagon me-1"></i>Factory Reset</button>
                </form>
                <div class="form-text text-warning mb-0"><i class="bi bi-info-circle me-1"></i>Factory-reset task type is pending confirmation via <code>gdms:probe</code>.</div>
                @endcan
            </div>
        </div>
    </div>
</div>

{{-- Push config --}}
<div class="card mt-3">
    <div class="card-header fw-semibold"><i class="bi bi-file-earmark-code me-1"></i>Push Configuration (P-values)</div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.phones.push-config', $mac) }}">
            @csrf
            <textarea name="params" class="form-control font-monospace small" rows="4" placeholder="P271=1&#10;P47=ucm.example.com"></textarea>
            <div class="d-flex justify-content-between mt-2">
                <small class="text-muted">One <code>KEY=VALUE</code> per line. Lines starting with <code>#</code> are ignored. Endpoint pending <code>gdms:probe</code> confirmation.</small>
                <button class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i>Push</button>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- Recent tasks --}}
<div class="card mt-3">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i>Recent GDMS Tasks</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>When</th><th>Type</th><th>Status</th><th>By</th></tr></thead>
            <tbody>
            @forelse($recentTasks as $t)
                @php $sc = ['success' => 'success', 'sent' => 'info', 'queued' => 'secondary', 'failed' => 'danger'][$t->status] ?? 'secondary'; @endphp
                <tr>
                    <td class="small">{{ $t->created_at?->diffForHumans() }}</td>
                    <td>{{ str_replace('_', ' ', $t->task_type) }}</td>
                    <td><span class="badge bg-{{ $sc }}">{{ $t->status }}</span></td>
                    <td class="small">{{ $t->requestedBy?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-3">No tasks yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
