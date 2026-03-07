@extends('layouts.admin')
@section('content')

@php $editing = isset($credential); @endphp

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="{{ route('admin.credentials.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0 fw-bold"><i class="bi bi-key-fill me-2 text-warning"></i>{{ $editing ? 'Edit Credential' : 'Add Credential' }}</h4>
</div>

<div class="card shadow-sm" style="max-width:650px">
    <div class="card-body">
        <form method="POST" action="{{ $editing ? route('admin.credentials.update', $credential) : route('admin.credentials.store') }}">
            @csrf
            @if($editing) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control @error('title') is-invalid @enderror"
                           value="{{ old('title', $credential->title ?? '') }}" required maxlength="255">
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                    <select name="category" class="form-select @error('category') is-invalid @enderror" required>
                        @foreach($categories as $c)
                        <option value="{{ $c }}" {{ old('category', $credential->category ?? 'other') == $c ? 'selected' : '' }}>
                            {{ ucfirst($c) }}
                        </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Linked Device</label>
                    <select name="device_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($devices as $d)
                        <option value="{{ $d->id }}" {{ old('device_id', $credential->device_id ?? request('device_id')) == $d->id ? 'selected' : '' }}>
                            {{ $d->name }} ({{ $d->type }})
                        </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Username</label>
                    <input type="text" name="username" class="form-control font-monospace"
                           value="{{ old('username', $credential->username ?? '') }}" maxlength="255" autocomplete="off">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">
                        Password {{ $editing ? '' : '*' }}
                        @if($editing)<small class="text-muted fw-normal">(leave blank to keep current)</small>@endif
                    </label>
                    <div class="input-group">
                        <input type="password" name="password" id="passwordField"
                               class="form-control font-monospace @error('password') is-invalid @enderror"
                               {{ $editing ? '' : 'required' }} autocomplete="new-password" placeholder="{{ $editing ? '••••••••••' : '' }}">
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePwVisible()">
                            <i class="bi bi-eye" id="pwToggleIcon"></i>
                        </button>
                        <button type="button" class="btn btn-outline-primary" onclick="generatePassword()">
                            <i class="bi bi-shuffle"></i> Generate
                        </button>
                    </div>
                    <div class="form-text">Minimum 8 characters recommended.</div>
                    @error('password')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label">URL</label>
                    <input type="url" name="url" class="form-control" value="{{ old('url', $credential->url ?? '') }}" maxlength="500">
                </div>

                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3">{{ old('notes', $credential->notes ?? '') }}</textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ $editing ? 'Save Changes' : 'Create Credential' }}</button>
                <a href="{{ route('admin.credentials.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
function togglePwVisible() {
    const field = document.getElementById('passwordField');
    const icon  = document.getElementById('pwToggleIcon');
    if (field.type === 'password') {
        field.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        field.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

async function generatePassword() {
    const res  = await fetch('{{ route("admin.credentials.generate") }}');
    const data = await res.json();
    const field = document.getElementById('passwordField');
    field.value = data.password;
    field.type  = 'text';
    document.getElementById('pwToggleIcon').className = 'bi bi-eye-slash';
}
</script>
@endpush
