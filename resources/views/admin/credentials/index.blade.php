@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-key-fill me-2 text-warning"></i>Credentials</h4>
        <small class="text-muted">Encrypted password vault — all access is logged</small>
    </div>
    @can('manage-credentials')
    <div class="d-flex gap-2">
        <a href="{{ route('admin.credentials.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Add Credential
        </a>
    </div>
    @endcan
</div>


{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Title or Username" value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="category" class="form-select form-select-sm">
            <option value="">All Categories</option>
            @foreach($categories as $c)
            <option value="{{ $c }}" {{ request('category') == $c ? 'selected' : '' }}>{{ ucfirst($c) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="device" class="form-select form-select-sm">
            <option value="">All Devices</option>
            @foreach($devices as $d)
            <option value="{{ $d->id }}" {{ request('device') == $d->id ? 'selected' : '' }}>{{ $d->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.credentials.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($credentials->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-key display-4 d-block mb-2"></i>No credentials found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Title</th><th>Category</th><th>Username</th>
                        <th>Password</th><th>Device</th><th>Branch</th>
                        <th>Added by</th><th>Updated</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($credentials as $cred)
                    <tr>
                        <td class="fw-semibold">
                            {{ $cred->title }}
                            @if($cred->url)
                            <a href="{{ $cred->url }}" target="_blank" class="ms-1 text-muted" title="{{ $cred->url }}">
                                <i class="bi bi-box-arrow-up-right" style="font-size:10px"></i>
                            </a>
                            @endif
                        </td>
                        <td><span class="badge {{ $cred->categoryBadgeClass() }}">{{ $cred->categoryLabel() }}</span></td>
                        <td class="font-monospace text-muted">{{ $cred->username ?: '—' }}</td>
                        <td>
                            @can('manage-credentials')
                            <div class="d-flex align-items-center gap-1">
                                <code class="password-mask" data-id="{{ $cred->id }}" style="cursor:default">••••••••••</code>
                                <button class="btn btn-link btn-sm p-0 text-secondary"
                                        onclick="togglePassword({{ $cred->id }}, this)" title="Reveal">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-link btn-sm p-0 text-secondary"
                                        onclick="copyPassword({{ $cred->id }}, this)" title="Copy">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            @else
                            <code class="text-muted">••••••••</code>
                            @endcan
                        </td>
                        <td>
                            @if($cred->device)
                            <a href="{{ route('admin.devices.show', $cred->device) }}" class="text-decoration-none small">
                                <i class="bi {{ $cred->device->typeIcon() }} me-1"></i>{{ $cred->device->name }}
                            </a>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $cred->device?->branch?->name ?: '—' }}</td>
                        <td class="text-muted">{{ $cred->creator?->name ?: '—' }}</td>
                        <td class="text-muted">{{ $cred->updated_at->diffForHumans() }}</td>
                        <td>
                            @can('manage-credentials')
                            <a href="{{ route('admin.credentials.edit', $cred) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('admin.credentials.destroy', $cred) }}" class="d-inline"
                                  onsubmit="return confirm('Delete credential \'{{ addslashes($cred->title) }}\'?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $credentials->links() }}</div>
        @endif
    </div>
</div>

{{-- Toast --}}
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="copyToast" class="toast align-items-center text-bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body"><i class="bi bi-clipboard-check me-1"></i>Password copied to clipboard.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

@endsection

@push('scripts')
@can('manage-credentials')
<script>
const revealUrl  = '{{ rtrim(url("admin/credentials/__ID__/reveal"), "/") }}';
const copyLogUrl = '{{ rtrim(url("admin/credentials/__ID__/log-copy"), "/") }}';
const csrfToken  = '{{ csrf_token() }}';

async function togglePassword(id, btn) {
    const mask = document.querySelector(`.password-mask[data-id="${id}"]`);
    if (mask.dataset.revealed === '1') {
        mask.textContent = '••••••••••';
        mask.dataset.revealed = '0';
        btn.innerHTML = '<i class="bi bi-eye"></i>';
        return;
    }
    const res  = await fetch(revealUrl.replace('__ID__', id), { headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
    const data = await res.json();
    mask.textContent      = data.password;
    mask.dataset.revealed = '1';
    btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
}

async function copyPassword(id, btn) {
    const mask = document.querySelector(`.password-mask[data-id="${id}"]`);
    let pw = mask.dataset.revealed === '1' ? mask.textContent : null;
    if (!pw) {
        const res = await fetch(revealUrl.replace('__ID__', id), { headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
        pw = (await res.json()).password;
    }
    await navigator.clipboard.writeText(pw);
    fetch(copyLogUrl.replace('__ID__', id), { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken } });
    new bootstrap.Toast(document.getElementById('copyToast')).show();
}
</script>
@endcan
@endpush
