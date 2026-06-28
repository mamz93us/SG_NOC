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
            background: #fff url('{{ \App\Models\Setting::wallpaperUrl() }}') no-repeat center center;
            background-size: cover;
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

        /* ── 6-digit segmented input ─────────────────────────────────── */
        .otp-stage {
            position: relative;
            min-height: 96px;
            display: flex;
            align-items: center;
            justify-content: center;
            perspective: 600px;
        }

        .otp-boxes {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .otp-digit {
            width: 46px;
            height: 56px;
            text-align: center;
            font-size: 1.7rem;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            color: #333;
            background: #fff;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            transition: border-color 0.2s, box-shadow 0.2s;
            transform-style: preserve-3d;
        }

        .otp-digit:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .otp-digit.filled {
            border-color: #667eea;
        }

        /* Each number spins as it is entered */
        .otp-digit.spin {
            animation: digitSpin 0.45s ease;
        }

        @keyframes digitSpin {
            from { transform: rotateY(0deg); }
            to   { transform: rotateY(360deg); }
        }

        /* Loading: all six keep spinning (a staggered wave) while we verify */
        .otp-stage.is-loading .otp-digit {
            animation: spinY 0.8s linear infinite;
            border-color: #667eea;
            color: transparent;
        }
        .otp-stage.is-loading .otp-digit:nth-child(2) { animation-delay: 0.08s; }
        .otp-stage.is-loading .otp-digit:nth-child(3) { animation-delay: 0.16s; }
        .otp-stage.is-loading .otp-digit:nth-child(4) { animation-delay: 0.24s; }
        .otp-stage.is-loading .otp-digit:nth-child(5) { animation-delay: 0.32s; }
        .otp-stage.is-loading .otp-digit:nth-child(6) { animation-delay: 0.40s; }

        @keyframes spinY {
            to { transform: rotateY(360deg); }
        }

        /* Success: the boxes collapse inward and the green check grows in */
        .otp-stage.is-success .otp-boxes {
            animation: mergeOut 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }

        @keyframes mergeOut {
            to { transform: scale(0.2); opacity: 0; }
        }

        .otp-logo {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
        }

        .otp-stage.is-success .otp-logo {
            animation: logoIn 0.5s ease 0.28s both;
        }

        @keyframes logoIn {
            from { opacity: 0; transform: scale(0.3); }
            to   { opacity: 1; transform: scale(1); }
        }

        .otp-logo svg { width: 84px; height: 84px; }

        .otp-logo circle, .otp-logo path {
            fill: none;
            stroke-width: 6;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

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

        .otp-stage.is-success .success-circle {
            animation: draw 0.55s ease-out 0.32s forwards;
        }

        .otp-stage.is-success .success-check {
            animation: draw 0.4s ease-out 0.78s forwards;
        }

        @keyframes draw {
            to { stroke-dashoffset: 0; }
        }

        /* Error: boxes flash red and shake, then reset for another try */
        .otp-stage.is-error .otp-digit {
            border-color: #ef4444;
            color: #ef4444;
        }

        .otp-stage.is-error .otp-boxes {
            animation: shake 0.45s cubic-bezier(.36, .07, .19, .97) both;
        }

        @keyframes shake {
            10%, 90% { transform: translateX(-2px); }
            20%, 80% { transform: translateX(4px); }
            30%, 50%, 70% { transform: translateX(-7px); }
            40%, 60% { transform: translateX(7px); }
        }

        .status-text {
            margin: 18px 0 0;
            min-height: 1.2em;
            font-weight: 600;
            color: #6b7280;
            transition: color 0.2s;
        }
        .status-text.is-success { color: #16a34a; }
        .status-text.is-error   { color: #dc2626; }

        .back-link {
            color: #475569;
            text-decoration: none;
            transition: color 0.3s;
        }

        .back-link:hover {
            color: #1e293b;
        }

        @media (prefers-reduced-motion: reduce) {
            .otp-digit.spin,
            .otp-stage.is-loading .otp-digit,
            .otp-stage.is-success .otp-boxes,
            .otp-stage.is-success .otp-logo,
            .otp-stage.is-success .success-circle,
            .otp-stage.is-success .success-check,
            .otp-stage.is-error .otp-boxes {
                animation-duration: 0.001s !important;
            }
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
                {{-- Errors (server-rendered fallback for the no-JS path) --}}
                @if($errors->any())
                    <div class="alert alert-danger border-0 rounded-3 mb-3">
                        @foreach($errors->all() as $error)
                            <div><i class="bi bi-exclamation-triangle me-1"></i> {{ $error }}</div>
                        @endforeach
                    </div>
                @endif

                <p class="text-muted text-center mb-4" id="otpPrompt">
                    Enter the 6-digit code from your authenticator app.
                </p>

                <form method="POST" action="{{ route('two-factor.verify') }}" id="otpForm" autocomplete="off">
                    @csrf
                    <input type="hidden" name="code" id="otpCode">

                    <div class="otp-stage" id="otpStage">
                        <div class="otp-boxes" id="otpBoxes">
                            @for($i = 0; $i < 6; $i++)
                                <input type="text"
                                       class="otp-digit"
                                       inputmode="numeric"
                                       pattern="[0-9]*"
                                       maxlength="6"
                                       autocomplete="{{ $i === 0 ? 'one-time-code' : 'off' }}"
                                       aria-label="Digit {{ $i + 1 }}"
                                       @if($i === 0) autofocus @endif>
                            @endfor
                        </div>

                        {{-- The "verified" logo the boxes merge into --}}
                        <div class="otp-logo" id="otpLogo" aria-hidden="true">
                            <svg viewBox="0 0 100 100">
                                <circle class="success-circle" cx="50" cy="50" r="40"></circle>
                                <path   class="success-check"  d="M30 51 L44 65 L71 35"></path>
                            </svg>
                        </div>
                    </div>
                </form>

                <p class="status-text text-center" id="statusText" aria-live="polite"></p>

                {{-- No-JS fallback: a plain field + button --}}
                <noscript>
                    <form method="POST" action="{{ route('two-factor.verify') }}" class="mt-2">
                        @csrf
                        <input type="text" name="code" maxlength="6" inputmode="numeric"
                               class="form-control text-center mb-2" placeholder="------" required>
                        <button type="submit" class="btn btn-primary w-100">Verify</button>
                    </form>
                </noscript>
            </div>
        </div>

        {{-- Back to login --}}
        <div class="text-center mt-3">
            <a href="{{ route('login') }}" class="back-link small">
                <i class="bi bi-arrow-left me-1"></i> Back to Login
            </a>
        </div>

        <div class="text-center mt-2">
            <small style="color: #94a3b8;">
                &copy; {{ date('Y') }} Samir Group. All rights reserved.
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const form   = document.getElementById('otpForm');
            const stage  = document.getElementById('otpStage');
            const boxes  = Array.prototype.slice.call(document.querySelectorAll('.otp-digit'));
            const code   = document.getElementById('otpCode');
            const prompt = document.getElementById('otpPrompt');
            const status = document.getElementById('statusText');
            const token  = form.querySelector('input[name="_token"]').value;

            let submitting = false;

            const value = () => boxes.map(b => b.value).join('');

            function focusFirstEmpty() {
                const b = boxes.find(x => !x.value) || boxes[boxes.length - 1];
                b.focus();
                if (b.select) b.select();
            }

            // Spread a multi-char value (paste or autofill) across the boxes.
            function distribute(str, start) {
                const digits = String(str).replace(/\D/g, '').slice(0, boxes.length - start).split('');
                digits.forEach((d, k) => {
                    const b = boxes[start + k];
                    b.value = d;
                    b.classList.add('filled');
                });
                focusFirstEmpty();
                maybeSubmit();
            }

            boxes.forEach((box, i) => {
                box.addEventListener('input', function () {
                    const raw = box.value.replace(/\D/g, '');

                    if (raw.length > 1) {            // paste / OTP autofill landed here
                        distribute(raw, i);
                        return;
                    }

                    box.value = raw;                 // single digit (or cleared)
                    if (raw) {
                        box.classList.add('filled');
                        box.classList.remove('spin');
                        void box.offsetWidth;        // restart the spin animation
                        box.classList.add('spin');
                        if (i < boxes.length - 1) boxes[i + 1].focus();
                    } else {
                        box.classList.remove('filled');
                    }
                    maybeSubmit();
                });

                box.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && !box.value && i > 0) {
                        e.preventDefault();
                        const prev = boxes[i - 1];
                        prev.value = '';
                        prev.classList.remove('filled');
                        prev.focus();
                    } else if (e.key === 'ArrowLeft' && i > 0) {
                        e.preventDefault();
                        boxes[i - 1].focus();
                    } else if (e.key === 'ArrowRight' && i < boxes.length - 1) {
                        e.preventDefault();
                        boxes[i + 1].focus();
                    }
                });

                box.addEventListener('paste', function (e) {
                    e.preventDefault();
                    distribute((e.clipboardData || window.clipboardData).getData('text'), i);
                });

                box.addEventListener('focus', function () {
                    if (box.select) box.select();
                });
            });

            function maybeSubmit() {
                if (value().length === 6 && !submitting) {
                    verify();
                }
            }

            function setState(state, text) {
                stage.className = 'otp-stage' + (state ? ' ' + state : '');
                if (prompt) prompt.style.visibility = state ? 'hidden' : 'visible';
                status.textContent = text || '';
                status.className = 'status-text text-center'
                    + (state === 'is-success' ? ' is-success' : state === 'is-error' ? ' is-error' : '');
            }

            function verify() {
                submitting = true;
                boxes.forEach(b => b.blur());
                code.value = value();
                setState('is-loading', 'Verifying…');

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({ code: value() }),
                    credentials: 'same-origin',
                }).then(function (res) {
                    if (res.ok) {
                        return res.json().then(function (data) {
                            setState('is-success', 'Verified');
                            setTimeout(function () {
                                window.location.href = data.redirect || '{{ route('login') }}';
                            }, 1300);
                        });
                    }
                    if (res.status === 429) {
                        return fail('Too many attempts. Please wait a moment, then try again.');
                    }
                    return res.json().then(function (data) {
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

            // Wrong code: show the red shake, then clear and hand the boxes back.
            function fail(message) {
                setState('is-error', message);
                setTimeout(function () {
                    boxes.forEach(b => { b.value = ''; b.classList.remove('filled'); });
                    setState('', '');
                    boxes[0].focus();
                    submitting = false;
                }, 1500);
            }
        })();
    </script>
</body>
</html>
