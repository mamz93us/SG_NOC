@extends('layouts.admin')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-6 col-xl-5">

        <h4 class="mb-4"><i class="bi bi-shield-lock me-2"></i>Two-Factor Authentication</h4>

        {{-- ── Success / error flash ── --}}
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-1"></i> {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                @foreach($errors->all() as $error)
                    <div><i class="bi bi-exclamation-triangle me-1"></i> {{ $error }}</div>
                @endforeach
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if($enabled)
            {{-- ════════════════════════════════════════════════════
                 2FA IS ENABLED
                 ════════════════════════════════════════════════════ --}}
            <div class="card shadow-sm border-0">
                <div class="card-body text-center py-5">
                    <div class="mb-3">
                        <span class="badge bg-success fs-6 px-3 py-2">
                            <i class="bi bi-shield-fill-check me-1"></i> Two-Factor Authentication is Enabled
                        </span>
                    </div>
                    <p class="text-muted mb-0">
                        Your account is protected with TOTP-based two-factor authentication.
                    </p>
                </div>
            </div>

            {{-- Disable 2FA --}}
            <div class="card shadow-sm border-0 mt-4">
                <div class="card-header bg-danger bg-opacity-10 text-danger fw-semibold">
                    <i class="bi bi-shield-x me-1"></i> Disable Two-Factor Authentication
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Enter your account password to disable two-factor authentication.
                        This will remove the extra layer of security from your account.
                    </p>
                    <form method="POST" action="{{ route('admin.two-factor.disable') }}">
                        @csrf
                        @method('DELETE')
                        <div class="mb-3">
                            <label for="password" class="form-label fw-semibold">Current Password</label>
                            <input type="password"
                                   class="form-control @error('password') is-invalid @enderror"
                                   id="password"
                                   name="password"
                                   required
                                   autocomplete="current-password"
                                   placeholder="Enter your password">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-shield-x me-1"></i> Disable 2FA
                        </button>
                    </form>
                </div>
            </div>

        @else
            {{-- ════════════════════════════════════════════════════
                 2FA SETUP
                 ════════════════════════════════════════════════════ --}}
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-qr-code me-1"></i> Set Up Two-Factor Authentication
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        Scan the QR code below with your authenticator app
                        (Google Authenticator, Microsoft Authenticator, Authy, etc.)
                        then enter the 6-digit code to confirm.
                    </p>

                    {{-- QR Code --}}
                    <div class="text-center my-4">
                        <img src="https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl={{ urlencode($qrUrl) }}"
                             alt="QR Code"
                             class="border rounded p-2"
                             width="200"
                             height="200">
                    </div>

                    {{-- Manual secret key --}}
                    <div class="mb-4">
                        <label class="form-label fw-semibold small text-muted">
                            Or enter this key manually:
                        </label>
                        <div class="input-group">
                            <input type="text"
                                   class="form-control font-monospace text-center bg-light"
                                   value="{{ $secret }}"
                                   readonly
                                   id="secretKey">
                            <button class="btn btn-outline-secondary"
                                    type="button"
                                    onclick="navigator.clipboard.writeText(document.getElementById('secretKey').value); this.innerHTML='<i class=\'bi bi-check\'></i> Copied'; setTimeout(() => this.innerHTML='<i class=\'bi bi-clipboard\'></i> Copy', 2000)">
                                <i class="bi bi-clipboard"></i> Copy
                            </button>
                        </div>
                    </div>

                    <hr>

                    {{-- Confirmation form --}}
                    <form method="POST" action="{{ route('admin.two-factor.confirm') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="code" class="form-label fw-semibold">
                                Verification Code
                            </label>
                            <input type="text"
                                   class="form-control form-control-lg text-center font-monospace @error('code') is-invalid @enderror"
                                   id="code"
                                   name="code"
                                   maxlength="6"
                                   inputmode="numeric"
                                   pattern="[0-9]{6}"
                                   autocomplete="one-time-code"
                                   placeholder="000000"
                                   required
                                   autofocus>
                            @error('code')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                Enter the 6-digit code from your authenticator app.
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-shield-check me-1"></i> Confirm &amp; Enable 2FA
                        </button>
                    </form>
                </div>
            </div>
        @endif

    </div>
</div>
@endsection
