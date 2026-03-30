@extends('layouts.admin')
@section('title', 'SSH — ' . $device->name)

@section('content')
<div class="container py-5" style="max-width:480px">

    <div class="mb-4">
        <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
            <i class="bi bi-shield-lock-fill text-info"></i>
            <span class="fw-semibold">SSH — {{ $device->name }}</span>
        </div>
        <div class="card-body">

            @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            <div class="d-flex align-items-center gap-2 mb-3 p-2 bg-body-secondary rounded">
                <i class="bi bi-hdd-network text-muted"></i>
                <code class="small">{{ $device->ip_address }}:{{ $device->ssh_port ?? 22 }}</code>
                <span class="badge bg-info ms-auto">SSH</span>
            </div>

            <form method="POST" action="{{ route('admin.devices.ssh.terminal', $device) }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control @error('username') is-invalid @enderror"
                           value="{{ old('username', $device->ssh_username ?? '') }}"
                           placeholder="admin" required autocomplete="off" autofocus>
                    @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                           placeholder="••••••••" required autocomplete="new-password">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">
                        <i class="bi bi-shield-check me-1 text-success"></i>
                        Credentials are used for this session only and never stored.
                    </div>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-info text-white">
                        <i class="bi bi-terminal-fill me-2"></i>Open SSH Terminal
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
