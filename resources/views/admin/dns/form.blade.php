@extends('layouts.admin')
@section('title', $account ? 'Edit DNS Account' : 'Add DNS Account')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="bi bi-globe2 me-2"></i>{{ $account ? 'Edit' : 'Add' }} DNS Account
        </h4>
        <a href="{{ route('admin.network.dns.index') }}" class="btn btn-outline-secondary btn-sm">
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
            <form method="POST" action="{{ $account ? route('admin.network.dns.update', $account) : route('admin.network.dns.store') }}">
                @csrf
                @if($account) @method('PUT') @endif

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Label <span class="text-danger">*</span></label>
                        <input type="text" name="label" class="form-control" value="{{ old('label', $account?->label) }}" placeholder="e.g. GoDaddy Main" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Environment <span class="text-danger">*</span></label>
                        <select name="environment" class="form-select" required>
                            <option value="production" {{ old('environment', $account?->environment) === 'production' ? 'selected' : '' }}>Production</option>
                            <option value="ote" {{ old('environment', $account?->environment) === 'ote' ? 'selected' : '' }}>OTE (Test)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Shopper ID</label>
                        <input type="text" name="shopper_id" class="form-control" value="{{ old('shopper_id', $account?->shopper_id) }}" placeholder="Optional">
                    </div>

                    <div class="col-12"><hr class="my-1"><h6 class="text-muted">API Credentials</h6></div>

                    <div class="col-md-6">
                        <label class="form-label">API Key {!! $account ? '' : '<span class="text-danger">*</span>' !!}</label>
                        <div class="input-group">
                            <input type="password" name="api_key" class="form-control" id="apiKeyField" value="{{ old('api_key') }}" placeholder="{{ $account ? '(unchanged)' : '' }}" {{ $account ? '' : 'required' }}>
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleField('apiKeyField', this)"><i class="bi bi-eye"></i></button>
                        </div>
                        @if($account)
                        <div class="form-text">Leave blank to keep existing key. Current: <code>{{ $account->maskedApiKey() }}</code></div>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">API Secret {!! $account ? '' : '<span class="text-danger">*</span>' !!}</label>
                        <div class="input-group">
                            <input type="password" name="api_secret" class="form-control" id="apiSecretField" value="{{ old('api_secret') }}" placeholder="{{ $account ? '(unchanged)' : '' }}" {{ $account ? '' : 'required' }}>
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleField('apiSecretField', this)"><i class="bi bi-eye"></i></button>
                        </div>
                        @if($account)
                        <div class="form-text">Leave blank to keep existing secret.</div>
                        @endif
                    </div>

                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes about this account">{{ old('notes', $account?->notes) }}</textarea>
                    </div>

                    <div class="col-md-6">
                        <div class="form-check form-switch mt-2">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ old('is_active', $account?->is_active ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label">Account is active</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> {{ $account ? 'Update' : 'Create' }} Account
                    </button>
                    @if($account)
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

@push('scripts')
<script>
function toggleField(id, btn) {
    const input = document.getElementById(id);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

@if($account)
document.getElementById('testConnectionBtn')?.addEventListener('click', function() {
    const btn = this;
    const result = document.getElementById('testResult');
    btn.disabled = true;
    result.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';

    fetch("{{ route('admin.network.dns.test', $account) }}", {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        result.innerHTML = data.success
            ? '<span class="text-success"><i class="bi bi-check-circle"></i> Connected</span>'
            : '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + (data.message || 'Failed') + '</span>';
    })
    .catch(() => {
        result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> Error</span>';
    })
    .finally(() => btn.disabled = false);
});
@endif
</script>
@endpush
@endsection
