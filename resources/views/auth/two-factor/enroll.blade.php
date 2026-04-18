<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Set Up Two-Factor Authentication - SG NOC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 30px 0;
        }

        .enroll-container {
            width: 100%;
            max-width: 480px;
            padding: 15px;
        }

        .enroll-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .enroll-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 32px 30px 24px;
            text-align: center;
            color: white;
        }

        .enroll-body {
            padding: 28px 30px 30px;
        }

        .qr-wrap {
            background: #f8f9fc;
            border: 1px solid #eceef5;
            border-radius: 14px;
            padding: 16px;
            display: inline-block;
        }

        .secret-input {
            font-family: 'Courier New', monospace;
            letter-spacing: 0.08em;
            text-align: center;
            background: #f8f9fc;
        }

        .otp-input {
            font-size: 1.75rem;
            text-align: center;
            letter-spacing: 0.5em;
            font-family: 'Courier New', monospace;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 12px 15px;
            transition: all 0.3s;
        }

        .otp-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .btn-verify {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            padding: 12px;
            font-size: 16px;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-verify:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .back-link {
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: color 0.3s;
        }

        .back-link:hover { color: white; }
    </style>
</head>
<body>
    <div class="enroll-container">
        <div class="enroll-card">
            <div class="enroll-header">
                @php($settings = \App\Models\Setting::first())

                @if($settings && $settings->company_logo)
                    <img src="{{ asset('storage/' . $settings->company_logo) }}"
                         alt="Logo"
                         style="max-width:100px;max-height:60px;margin-bottom:15px;background:white;padding:8px;border-radius:10px;">
                @else
                    <div style="font-size:40px;margin-bottom:10px;">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                @endif

                <h4 class="mb-1 fw-bold">Set Up Two-Factor Authentication</h4>
                <p class="mb-0 small" style="opacity:0.9;">
                    Two-factor authentication is required to continue.
                </p>
            </div>

            <div class="enroll-body">
                @if($errors->any())
                    <div class="alert alert-danger border-0 rounded-3 mb-3">
                        @foreach($errors->all() as $error)
                            <div><i class="bi bi-exclamation-triangle me-1"></i> {{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <p class="text-muted small mb-3">
                    Scan the QR code with an authenticator app
                    (Google Authenticator, Microsoft Authenticator, Authy, 1Password, etc.)
                    then enter the 6-digit code to confirm.
                </p>

                <div class="text-center my-3">
                    <div class="qr-wrap">
                        <img src="https://quickchart.io/qr?size=200&text={{ urlencode($qrUrl) }}"
                             alt="QR Code"
                             width="200"
                             height="200">
                    </div>
                </div>

                <label class="form-label fw-semibold small text-muted">
                    Or enter this key manually:
                </label>
                <div class="input-group mb-4">
                    <input type="text"
                           class="form-control secret-input"
                           value="{{ $secret }}"
                           readonly
                           id="secretKey">
                    <button class="btn btn-outline-secondary"
                            type="button"
                            onclick="navigator.clipboard.writeText(document.getElementById('secretKey').value); this.innerHTML='<i class=\'bi bi-check\'></i> Copied'; setTimeout(() => this.innerHTML='<i class=\'bi bi-clipboard\'></i> Copy', 2000)">
                        <i class="bi bi-clipboard"></i> Copy
                    </button>
                </div>

                <form method="POST" action="{{ route('admin.two-factor.confirm') }}">
                    @csrf
                    <label for="code" class="form-label fw-semibold">Verification Code</label>
                    <input type="text"
                           class="form-control otp-input mb-2 @error('code') is-invalid @enderror"
                           id="code"
                           name="code"
                           maxlength="6"
                           inputmode="numeric"
                           pattern="[0-9]{6}"
                           autocomplete="one-time-code"
                           placeholder="------"
                           required
                           autofocus>
                    @error('code')
                        <div class="invalid-feedback d-block mb-2">{{ $message }}</div>
                    @enderror
                    <div class="form-text mb-3">
                        Enter the 6-digit code from your authenticator app.
                    </div>

                    <button type="submit" class="btn btn-verify">
                        <i class="bi bi-shield-check me-1"></i> Confirm &amp; Enable 2FA
                    </button>
                </form>
            </div>
        </div>

        <div class="text-center mt-3">
            <form method="POST" action="{{ route('logout') }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-link back-link small p-0">
                    <i class="bi bi-arrow-left me-1"></i> Sign out
                </button>
            </form>
        </div>

        <div class="text-center mt-2">
            <small style="color: rgba(255,255,255,0.7);">
                &copy; {{ date('Y') }} Samir Group. All rights reserved.
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
