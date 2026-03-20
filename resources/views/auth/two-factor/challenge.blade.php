<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication - SG NOC</title>
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
        }

        .challenge-container {
            width: 100%;
            max-width: 420px;
            padding: 15px;
        }

        .challenge-card {
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

        .challenge-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px 30px;
            text-align: center;
            color: white;
        }

        .challenge-body {
            padding: 30px;
        }

        .otp-input {
            font-size: 2rem;
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

        .back-link:hover {
            color: white;
        }
    </style>
</head>
<body>
    <div class="challenge-container">
        <div class="challenge-card">
            {{-- Header --}}
            <div class="challenge-header">
                @php
                    $settings = App\Models\Setting::first();
                @endphp

                @if($settings && $settings->company_logo)
                    <img src="{{ asset('storage/' . $settings->company_logo) }}"
                         alt="Logo"
                         style="max-width:100px;max-height:60px;margin-bottom:15px;background:white;padding:8px;border-radius:10px;">
                @else
                    <div style="font-size:40px;margin-bottom:10px;">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                @endif

                <h4 class="mb-1 fw-bold">Two-Factor Authentication</h4>
                <p class="mb-0 small" style="opacity:0.9;">Verify your identity to continue</p>
            </div>

            {{-- Body --}}
            <div class="challenge-body">
                {{-- Errors --}}
                @if($errors->any())
                    <div class="alert alert-danger border-0 rounded-3 mb-3">
                        @foreach($errors->all() as $error)
                            <div><i class="bi bi-exclamation-triangle me-1"></i> {{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <p class="text-muted text-center mb-4">
                    Enter the 6-digit code from your authenticator app.
                </p>

                <form method="POST" action="{{ route('two-factor.verify') }}">
                    @csrf

                    <div class="mb-4">
                        <input type="text"
                               class="form-control otp-input"
                               name="code"
                               maxlength="6"
                               inputmode="numeric"
                               pattern="[0-9]{6}"
                               autocomplete="one-time-code"
                               placeholder="------"
                               required
                               autofocus>
                    </div>

                    <button type="submit" class="btn btn-verify">
                        <i class="bi bi-shield-check me-1"></i> Verify
                    </button>
                </form>
            </div>
        </div>

        {{-- Back to login --}}
        <div class="text-center mt-3">
            <a href="{{ route('login') }}" class="back-link small">
                <i class="bi bi-arrow-left me-1"></i> Back to Login
            </a>
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
