@extends('layouts.admin')
@section('title', 'HR API Keys')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-key-fill me-2 text-primary"></i>HR API Keys</h4>
        <small class="text-muted">Manage database-backed API keys for HR integrations</small>
    </div>
</div>

@if(session('new_api_key'))
<div class="alert alert-success border-success shadow-sm mb-4" id="newKeyAlert">
    <div class="d-flex align-items-start gap-3">
        <i class="bi bi-key-fill fs-4 text-success mt-1"></i>
        <div class="flex-grow-1">
            <strong>New API Key created: {{ session('new_api_key_name') }}</strong>
            <p class="mb-2 text-muted small">Copy this key now — it will <strong>NOT</strong> be shown again.</p>
            <div class="input-group">
                <input type="text" class="form-control font-monospace" id="rawKeyDisplay"
                       value="{{ session('new_api_key') }}" readonly>
                <button class="btn btn-outline-secondary" type="button" onclick="copyKey()">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>
        </div>
    </div>
</div>
@endif

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2"><i class="bi bi-exclamation-circle me-1"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($keys->isEmpty())
        <div class="text-center py-5 text-muted"><i class="bi bi-key display-4 d-block mb-2"></i>No API keys found. Generate one below.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Prefix</th>
                        <th>Status</th>
                        <th>Last Used</th>
                        <th>Last IP</th>
                        <th>Created By</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($keys as $key)
                    <tr>
                        <td class="fw-semibold">{{ $key->name }}</td>
                        <td class="text-muted">{{ $key->description ?: '—' }}</td>
                        <td><code class="badge bg-secondary font-monospace">{{ $key->key_prefix }}</code></td>
                        <td>
                            @if($key->is_active)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-danger">Revoked {{ $key->revoked_at?->format('Y-m-d') }}</span>
                            @endif
                        </td>
                        <td>{{ $key->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                        <td class="font-monospace text-muted">{{ $key->last_used_ip ?? '—' }}</td>
                        <td>{{ $key->creator?->name ?? '—' }}</td>
                        <td>{{ $key->created_at->format('Y-m-d H:i') }}</td>
                        <td>
                            @if($key->is_active)
                            <form method="POST" action="/admin/hr-api-keys/{{ $key->id }}/revoke" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-warning btn-revoke">
                                    <i class="bi bi-slash-circle me-1"></i>Revoke
                                </button>
                            </form>
                            @endif
                            <form method="POST" action="/admin/hr-api-keys/{{ $key->id }}" class="d-inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger btn-delete-key">
                                    <i class="bi bi-trash me-1"></i>Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Generate New API Key</h6></div>
    <div class="card-body">
        <form method="POST" action="/admin/hr-api-keys">
            @csrf
            <div class="mb-3">
                <label class="form-label">Key Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Oracle HR System" required maxlength="100">
            </div>
            <div class="mb-3">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control" placeholder="Optional — what uses this key?" maxlength="500">
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i>Generate Key</button>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
function copyKey() {
    const el = document.getElementById('rawKeyDisplay');
    navigator.clipboard.writeText(el.value).then(() => {
        alert('API key copied to clipboard!');
    }).catch(() => {
        el.select();
        document.execCommand('copy');
        alert('Key copied!');
    });
}
// Confirm dialogs
document.querySelectorAll('.btn-revoke').forEach(btn => {
    btn.addEventListener('click', function(e) {
        if (!confirm('Revoke this key? It will stop working immediately.')) e.preventDefault();
    });
});
document.querySelectorAll('.btn-delete-key').forEach(btn => {
    btn.addEventListener('click', function(e) {
        if (!confirm('Permanently delete this key? This cannot be undone.')) e.preventDefault();
    });
});
</script>
@endpush
