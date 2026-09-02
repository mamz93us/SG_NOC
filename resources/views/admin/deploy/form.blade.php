@extends('layouts.admin')

@section('title', $server->exists ? 'Edit Deployment Server' : 'Add Deployment Server')

@section('content')
<div class="container-fluid py-3">
    <h4 class="mb-3">
        {{ $server->exists ? "Edit '{$server->name}'" : 'Add deployment server' }}
    </h4>

    <div class="card">
        <div class="card-body">
            <form action="{{ $server->exists ? route('admin.deploy.servers.update', $server) : route('admin.deploy.servers.store') }}"
                  method="POST" enctype="multipart/form-data">
                @csrf
                @if($server->exists) @method('PUT') @endif

                @if($errors->any())
                    <div class="alert alert-danger py-2">
                        <ul class="mb-0 small">
                            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $server->name) }}"
                               class="form-control" placeholder="NOC2 production" maxlength="100" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Host <span class="text-danger">*</span></label>
                        <input type="text" name="hostname" value="{{ old('hostname', $server->hostname) }}"
                               class="form-control font-monospace" placeholder="172.16.8.11" maxlength="255" required>
                        <small class="text-muted">IP or DNS name reachable from the NOC.</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Port <span class="text-danger">*</span></label>
                        <input type="number" name="port" value="{{ old('port', $server->port ?: 22) }}"
                               class="form-control" min="1" max="65535" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">SSH username <span class="text-danger">*</span></label>
                        <input type="text" name="username" value="{{ old('username', $server->username) }}"
                               class="form-control font-monospace" placeholder="azureuser" maxlength="100" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select">
                            <option value="">— none —</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(old('branch_id', $server->branch_id) == $branch->id)>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <div class="form-check form-switch mt-2">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" id="activeSwitch"
                                   name="is_active" value="1" @if(old('is_active', $server->is_active ?? true)) checked @endif>
                            <label class="form-check-label" for="activeSwitch">Enabled</label>
                        </div>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Default working directory</label>
                        <input type="text" name="working_directory" value="{{ old('working_directory', $server->working_directory) }}"
                               class="form-control font-monospace" placeholder="/home/azureuser/phonebook2" maxlength="255">
                        <small class="text-muted">
                            Prepended as <code>cd &lt;dir&gt; &amp;&amp;</code> to every command that does not set its own.
                        </small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Authentication <span class="text-danger">*</span></label>
                        <select name="auth_type" id="authType" class="form-select" required>
                            <option value="key" @selected(old('auth_type', $server->auth_type) === 'key')>Private key</option>
                            <option value="password" @selected(old('auth_type', $server->auth_type) === 'password')>Password</option>
                        </select>
                    </div>

                    {{-- ── Key auth ── --}}
                    <div class="col-12 auth-key">
                        <hr class="my-1">
                        <strong class="small text-muted">Private key</strong>
                    </div>

                    @if($server->exists && $server->key_filename)
                    <div class="col-12 auth-key">
                        <div class="alert alert-secondary py-2 mb-0 small">
                            <i class="bi bi-key-fill me-1"></i>
                            A key is stored: <code>{{ $server->key_filename }}</code>
                            <span class="badge bg-secondary ms-1">{{ $server->key_format }}</span>
                            @if($server->key_fingerprint)
                                <div class="font-monospace mt-1">{{ $server->key_fingerprint }}</div>
                            @endif
                            <div class="text-muted mt-1">
                                The key itself is never shown again. Upload a new file below to replace it.
                            </div>
                        </div>
                    </div>
                    @endif

                    <div class="col-md-7 auth-key">
                        <label class="form-label">Key file</label>
                        <input type="file" name="private_key_file" class="form-control"
                               accept=".pem,.key,.ppk,.txt,application/x-pem-file,text/plain">
                        <small class="text-muted">
                            OpenSSH, PEM (<code>.pem</code>) or PuTTY (<code>.ppk</code>). Max 16 KB. Stored encrypted.
                        </small>
                    </div>
                    <div class="col-md-5 auth-key">
                        <label class="form-label">Key passphrase</label>
                        <input type="password" name="key_passphrase" class="form-control"
                               autocomplete="new-password"
                               placeholder="{{ $server->exists ? 'unchanged' : 'only if the key is encrypted' }}">
                    </div>

                    {{-- ── Password auth ── --}}
                    <div class="col-md-5 auth-password">
                        <hr class="my-1">
                        <label class="form-label mt-2">SSH password</label>
                        <input type="password" name="password" class="form-control"
                               autocomplete="new-password" placeholder="{{ $server->exists ? 'unchanged' : '' }}">
                        <small class="text-muted">Stored encrypted. Leave blank to keep the current one.</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="description" class="form-control" rows="2" maxlength="1000"
                                  placeholder="What this server is for">{{ old('description', $server->description) }}</textarea>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>{{ $server->exists ? 'Save changes' : 'Create server' }}
                    </button>
                    <a href="{{ $server->exists ? route('admin.deploy.servers.show', $server) : route('admin.deploy.index') }}"
                       class="btn btn-outline-secondary">Cancel</a>

                    @if($server->exists)
                        <button type="button" class="btn btn-outline-danger ms-auto"
                                data-bs-toggle="modal" data-bs-target="#deleteServerModal">
                            <i class="bi bi-trash me-1"></i>Delete
                        </button>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>

@if($server->exists)
<div class="modal fade" id="deleteServerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete this server?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body small">
                This removes <strong>{{ $server->name }}</strong>, the stored key, every
                command defined on it, and its run history.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form action="{{ route('admin.deploy.servers.destroy', $server) }}" method="POST">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-danger">Delete server</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
(function () {
    const select = document.getElementById('authType');

    function sync() {
        const isKey = select.value === 'key';
        document.querySelectorAll('.auth-key').forEach(el => el.classList.toggle('d-none', !isKey));
        document.querySelectorAll('.auth-password').forEach(el => el.classList.toggle('d-none', isKey));
    }

    select.addEventListener('change', sync);
    sync();
})();
</script>
@endpush
