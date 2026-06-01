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

        .otp-input.shake {
            animation: shake 0.45s cubic-bezier(.36,.07,.19,.97) both;
            border-color: #ef4444;
        }

        @keyframes shake {
            10%, 90% { transform: translateX(-2px); }
            20%, 80% { transform: translateX(4px); }
            30%, 50%, 70% { transform: translateX(-7px); }
            40%, 60% { transform: translateX(7px); }
        }

        /* ── Verifying → success/error status animation ───────────────── */
        .status-overlay {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 10px 0 6px;
            animation: fadeIn 0.25s ease-out;
        }

        .status-overlay.is-loading,
        .status-overlay.is-success,
        .status-overlay.is-error {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        .status-svg {
            width: 96px;
            height: 96px;
        }

        .status-overlay.is-success .status-svg {
            animation: pop 0.45s ease-out;
        }

        @keyframes pop {
            0%   { transform: scale(0.85); }
            55%  { transform: scale(1.06); }
            100% { transform: scale(1); }
        }

        /* shared circle geometry: r=40 → circumference ≈ 251.3 */
        .status-svg circle,
        .status-svg path,
        .status-svg line {
            fill: none;
            stroke-width: 6;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* Loading: a sweeping arc that spins like a clock hand */
        .spinner-ring {
            stroke: #667eea;
            stroke-dasharray: 175 251;
            stroke-dashoffset: 0;
            transform-origin: 50% 50%;
            opacity: 0;
        }

        .status-overlay.is-loading .spinner-ring {
            opacity: 1;
            animation: spin 0.9s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Success: green ring draws, then checkmark draws — the "merge" */
        .success-circle {
            stroke: #22c55e;
            stroke-dasharray: 251;
            stroke-dashoffset: 251;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }

        .success-check {
            stroke: #22c55e;
            stroke-width: 7;
            stroke-dasharray: 80;
            stroke-dashoffset: 80;
        }

        .status-overlay.is-success .success-circle {
            animation: draw 0.5s ease-out forwards;
        }

        .status-overlay.is-success .success-check {
            animation: draw 0.35s ease-out 0.42s forwards;
        }

        /* Error: red ring + X, same draw treatment */
        .error-circle {
            stroke: #ef4444;
            stroke-dasharray: 251;
            stroke-dashoffset: 251;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }

        .error-x {
            stroke: #ef4444;
            stroke-width: 7;
            stroke-dasharray: 40;
            stroke-dashoffset: 40;
        }

        .status-overlay.is-error .error-circle {
            animation: draw 0.5s ease-out forwards;
        }

        .status-overlay.is-error .error-x1 {
            animation: draw 0.25s ease-out 0.4s forwards;
        }

        .status-overlay.is-error .error-x2 {
            animation: draw 0.25s ease-out 0.6s forwards;
        }

        @keyframes draw {
            to { stroke-dashoffset: 0; }
        }

        .status-text {
            margin: 14px 0 0;
            font-weight: 600;
            color: #4b5563;
        }

        .status-overlay.is-success .status-text { color: #16a34a; }
        .status-overlay.is-error   .status-text { color: #dc2626; }

        @media (prefers-reduced-motion: reduce) {
            .status-svg, .otp-input.shake,
            .spinner-ring, .success-circle, .success-check,
            .error-circle, .error-x { animation-duration: 0.001s !important; }
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

                <div id="formArea">
                    <p class="text-muted text-center mb-4">
                        Enter the 6-digit code from your authenticator app.
                    </p>

                    <form method="POST" action="{{ route('two-factor.verify') }}" id="otpForm">
                        @csrf

                        <div class="mb-4">
                            <input type="text"
                                   id="otpInput"
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

                {{-- Verifying → success/error animation (driven by JS) --}}
                <div class="status-overlay" id="statusOverlay" aria-live="polite">
                    <svg class="status-svg" viewBox="0 0 100 100" aria-hidden="true">
                        <circle class="spinner-ring"   cx="50" cy="50" r="40"></circle>
                        <circle class="success-circle" cx="50" cy="50" r="40"></circle>
                        <path   class="success-check"  d="M30 51 L44 65 L71 35"></path>
                        <circle class="error-circle"   cx="50" cy="50" r="40"></circle>
                        <line   class="error-x error-x1" x1="35" y1="35" x2="65" y2="65"></line>
                        <line   class="error-x error-x2" x1="65" y1="35" x2="35" y2="65"></line>
                    </svg>
                    <p class="status-text" id="statusText">Verifying…</p>
                </div>
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
    <script>
        (function () {
            const form     = document.getElementById('otpForm');
            const input    = document.getElementById('otpInput');
            const formArea = document.getElementById('formArea');
            const overlay  = document.getElementById('statusOverlay');
            const status   = document.getElementById('statusText');
            const token    = form.querySelector('input[name="_token"]').value;

            let submitting = false;

            // Keep only digits, and auto-verify the moment the 6th lands.
            input.addEventListener('input', function () {
                const cleaned = input.value.replace(/\D/g, '').slice(0, 6);
                if (cleaned !== input.value) {
                    input.value = cleaned;
                }
                if (input.value.length === 6) {
                    verify();
                }
            });

            // Manual button / Enter still routes through the animation when JS is on.
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (input.value.length === 6) {
                    verify();
                } else {
                    nudgeError();
                }
            });

            function verify() {
                if (submitting) {
                    return;
                }
                submitting = true;
                input.blur();
                setState('is-loading', 'Verifying…');

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({ code: input.value }),
                    credentials: 'same-origin',
                }).then(function (res) {
                    if (res.ok) {
                        return res.json().then(function (data) {
                            setState('is-success', 'Verified');
                            setTimeout(function () {
                                window.location.href = data.redirect || '{{ route('login') }}';
                            }, 1100);
                        });
                    }
                    if (res.status === 429) {
                        return fail('Too many attempts. Please wait a moment, then try again.');
                    }
                    return res.json().then(function (data) {
                        // 409 (session lost) → bounce to login; everything else is a bad code.
                        if (res.status === 409 && data && data.redirect) {
                            setState('is-error', 'Session expired. Redirecting…');
                            setTimeout(function () { window.location.href = data.redirect; }, 1200);
                            return;
                        }
                        fail((data && data.message) || 'The authentication code is invalid. Please try again.');
                    }).catch(function () {
                        fail('The authentication code is invalid. Please try again.');
                    });
                }).catch(function () {
                    fail('Network error. Please check your connection and try again.');
                });
            }

            // Failed attempt: show the red X briefly, then hand the form back.
            function fail(message) {
                setState('is-error', message);
                setTimeout(function () {
                    overlay.className = 'status-overlay';
                    formArea.style.display = '';
                    input.value = '';
                    input.classList.add('shake');
                    setTimeout(function () { input.classList.remove('shake'); }, 450);
                    input.focus();
                    submitting = false;
                }, 1500);
            }

            // Too-short manual submit: just shake, no server round-trip.
            function nudgeError() {
                input.classList.add('shake');
                setTimeout(function () { input.classList.remove('shake'); }, 450);
                input.focus();
            }

            function setState(stateClass, text) {
                formArea.style.display = 'none';
                overlay.className = 'status-overlay ' + stateClass;
                status.textContent = text;
            }
        })();
    </script>
</body>
</html>
