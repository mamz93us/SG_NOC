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
    {{-- Device controls --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-sliders me-1"></i>Device Controls</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.phones.reboot', $mac) }}" onsubmit="return confirm('Reboot this phone now?')">
                    @csrf<button class="btn btn-sm btn-outline-warning w-100"><i class="bi bi-arrow-clockwise me-1"></i>Reboot</button>
                </form>
            </div>
        </div>
    </div>

    {{-- GDMS-console-managed actions (not exposed by the GDMS API) --}}
    <div class="col-lg-6">
        <div class="card h-100 border-info">
            <div class="card-header fw-semibold bg-info bg-opacity-10"><i class="bi bi-cloud me-1"></i>Managed in the GDMS console</div>
            <div class="card-body small text-muted">
                The GDMS API doesn't expose the following, so do them in the GDMS web console:
                <ul class="mb-0 ps-3 mt-1">
                    <li><strong>Assign / change the SIP account</strong> (account → device slot)</li>
                    <li><strong>Push config / apply a template</strong></li>
                    <li><strong>Factory reset</strong></li>
                </ul>
            </div>
        </div>
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
