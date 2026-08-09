@extends('layouts.admin')
@section('title', $firewall ? 'Edit FortiGate' : 'Add FortiGate')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="bi bi-bricks me-2"></i>{{ $firewall ? 'Edit' : 'Add' }} FortiGate
        </h4>
        <a href="{{ route('admin.network.fortigate.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    @if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ $firewall ? route('admin.network.fortigate.update', $firewall) : route('admin.network.fortigate.store') }}">
                @csrf
                @if($firewall) @method('PUT') @endif

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $firewall?->name) }}" placeholder="JED-FortiGate-60F" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">IP Address <span class="text-danger">*</span></label>
                        <input type="text" name="ip" class="form-control" value="{{ old('ip', $firewall?->ip) }}" placeholder="192.168.100.1" required>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Port</label>
                        <input type="number" name="port" class="form-control" value="{{ old('port', $firewall?->port ?? 443) }}" min="1" max="65535">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">VDOM</label>
                        <input type="text" name="vdom" class="form-control" value="{{ old('vdom', $firewall?->vdom ?? 'root') }}" placeholder="root">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">None</option>
                            @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('branch_id', $firewall?->branch_id) == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Leases pulled from this box are tagged with this branch.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Network / SSID Label</label>
                        <input type="text" name="network_label" class="form-control"
                               value="{{ old('network_label', $firewall?->network_label) }}" placeholder="SG_Open">
                        <div class="form-text">Stamped onto every lease so you can tell which network a client is on.</div>
                        <div class="form-check mt-2">
                            <input type="hidden" name="label_wifi_only" value="0">
                            <input class="form-check-input" type="checkbox" name="label_wifi_only" value="1"
                                   id="labelWifiOnly" {{ old('label_wifi_only', $firewall?->label_wifi_only ?? false) ? 'checked' : '' }}>
                            <label class="form-check-label small" for="labelWifiOnly">
                                Label Wi-Fi clients only
                            </label>
                            <div class="form-text">
                                Leave off when the whole firewall serves this network. Turn on to apply the
                                label only to clients whose MAC matches a known Wi-Fi adapter from the Intune sync.
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Model</label>
                        <input type="text" name="model" class="form-control" value="{{ old('model', $firewall?->model) }}" placeholder="FortiGate-60F">
                        <div class="form-text">Optional — serial and firmware are filled in automatically on sync.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Linked Monitored Host (SNMP)</label>
                        <select name="monitored_host_id" class="form-select">
                            <option value="">None</option>
                            @foreach($monitoredHosts as $h)
                            <option value="{{ $h->id }}" {{ old('monitored_host_id', $firewall?->monitored_host_id) == $h->id ? 'selected' : '' }}>{{ $h->name }} ({{ $h->ip }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12"><hr class="my-1"><h6 class="text-muted">API Access</h6></div>

                    <div class="col-md-8">
                        <label class="form-label">REST API Token {!! $firewall ? '' : '<span class="text-danger">*</span>' !!}</label>
                        <input type="password" name="api_token" class="form-control" autocomplete="new-password"
                               value="{{ old('api_token') }}"
                               placeholder="{{ $firewall ? '(unchanged — ' . $firewall->maskedToken() . ')' : 'z9wrN0hqmt4HQQHq0dzh3xyzsf3NGN' }}"
                               {{ $firewall ? '' : 'required' }}>
                        <div class="form-text">
                            @if($firewall) Leave blank to keep the existing key. @endif
                            Stored encrypted. Create it on the firewall under
                            <strong>System &rarr; Administrators &rarr; Create New &rarr; REST API Admin</strong>,
                            give it a read-only profile, and add the NOC IP to its Trusted Hosts.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="form-check form-switch mt-4">
                            <input type="hidden" name="sync_enabled" value="0">
                            <input class="form-check-input" type="checkbox" name="sync_enabled" value="1" {{ old('sync_enabled', $firewall?->sync_enabled ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label">Enable automatic DHCP lease sync</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> {{ $firewall ? 'Update' : 'Add' }} FortiGate
                    </button>
                    @if($firewall)
                    <button type="button" class="btn btn-outline-info" id="testConnectionBtn">
                        <i class="bi bi-wifi"></i> Test Connection
                    </button>
                    <span id="testResult" class="align-self-center ms-2 small"></span>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>

@if($firewall)
@push('scripts')
<script>
document.getElementById('testConnectionBtn')?.addEventListener('click', function () {
    const btn = this;
    const result = document.getElementById('testResult');
    btn.disabled = true;
    result.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

    fetch("{{ route('admin.network.fortigate.test', $firewall) }}", {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const m = data.meta || {};
            const bits = [m.model, m.version, m.serial].filter(Boolean).join(' · ');
            result.innerHTML = '<span class="text-success"><i class="bi bi-check-circle"></i> Connected'
                + (bits ? ' — ' + bits : '') + '</span>';
        } else {
            result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> '
                + (data.message || 'Failed') + '</span>';
        }
    })
    .catch(() => {
        result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Error</span>';
    })
    .finally(() => btn.disabled = false);
});
</script>
@endpush
@endif
@endsection
