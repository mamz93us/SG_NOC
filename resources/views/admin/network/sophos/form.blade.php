@extends('layouts.admin')
@section('title', $firewall ? 'Edit Sophos Firewall' : 'Add Sophos Firewall')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="bi bi-shield-fill me-2"></i>{{ $firewall ? 'Edit' : 'Add' }} Sophos Firewall
        </h4>
        <a href="{{ route('admin.network.sophos.index') }}" class="btn btn-outline-secondary btn-sm">
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
            <form method="POST" action="{{ $firewall ? route('admin.network.sophos.update', $firewall) : route('admin.network.sophos.store') }}">
                @csrf
                @if($firewall) @method('PUT') @endif

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $firewall?->name) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">IP Address <span class="text-danger">*</span></label>
                        <input type="text" name="ip" class="form-control" value="{{ old('ip', $firewall?->ip) }}" placeholder="192.168.1.1" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Port</label>
                        <input type="number" name="port" class="form-control" value="{{ old('port', $firewall?->port ?? 4444) }}" min="1" max="65535">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">None</option>
                            @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('branch_id', $firewall?->branch_id) == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                            @endforeach
                        </select>
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

                    <div class="col-12"><hr class="my-1"><h6 class="text-muted">API Credentials</h6></div>

                    <div class="col-md-6">
                        <label class="form-label">API Username {{ $firewall ? '' : '<span class="text-danger">*</span>' }}</label>
                        <input type="text" name="api_username" class="form-control" value="{{ old('api_username') }}" placeholder="{{ $firewall ? '(unchanged)' : '' }}" {{ $firewall ? '' : 'required' }}>
                        @if($firewall)
                        <div class="form-text">Leave blank to keep existing username.</div>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">API Password {{ $firewall ? '' : '<span class="text-danger">*</span>' }}</label>
                        <input type="password" name="api_password" class="form-control" value="{{ old('api_password') }}" placeholder="{{ $firewall ? '(unchanged)' : '' }}" {{ $firewall ? '' : 'required' }}>
                        @if($firewall)
                        <div class="form-text">Leave blank to keep existing password.</div>
                        @endif
                    </div>

                    <div class="col-md-6">
                        <div class="form-check form-switch mt-3">
                            <input type="hidden" name="sync_enabled" value="0">
                            <input class="form-check-input" type="checkbox" name="sync_enabled" value="1" {{ old('sync_enabled', $firewall?->sync_enabled ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label">Enable automatic sync</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> {{ $firewall ? 'Update' : 'Create' }} Firewall
                    </button>
                    @if($firewall)
                    <button type="button" class="btn btn-outline-info" id="testConnectionBtn">
                        <i class="bi bi-wifi"></i> Test Connection
                    </button>
                    <span id="testResult" class="align-self-center ms-2"></span>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>

@if($firewall)
@push('scripts')
<script>
document.getElementById('testConnectionBtn')?.addEventListener('click', function() {
    const btn = this;
    const result = document.getElementById('testResult');
    btn.disabled = true;
    result.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

    fetch("{{ route('admin.network.sophos.test', $firewall) }}", {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        result.innerHTML = data.success
            ? '<span class="text-success"><i class="bi bi-check-circle"></i> Connected</span>'
            : '<span class="text-danger"><i class="bi bi-x-circle"></i> Failed</span>';
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
