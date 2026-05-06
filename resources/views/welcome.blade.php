<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Company Directory') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
        }
        .welcome-container { max-width: 900px; width: 100%; }
        .welcome-card {
            background: white;
            border-radius: 30px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            animation: fadeInUp 0.8s ease-out;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(40px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .welcome-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 50px 40px;
            text-align: center;
            color: white;
        }
        .company-logo {
            max-width: 160px;
            max-height: 110px;
            margin-bottom: 18px;
            background: white;
            padding: 16px;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .welcome-title {
            font-size: 38px;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        .welcome-subtitle { font-size: 17px; margin: 12px 0 0 0; opacity: 0.95; }

        /* Exactly two big sign-in tiles. On small screens they stack. */
        .signin-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 28px;
            padding: 50px 40px;
        }
        @media (max-width: 640px) {
            .signin-grid { grid-template-columns: 1fr; }
        }
        .signin-card {
            border-radius: 24px;
            padding: 55px 30px;
            text-align: center;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            color: white;
            transition: transform .25s, box-shadow .25s;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            min-height: 280px;
        }
        .signin-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.22);
            color: white;
        }
        .signin-card.user {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .signin-card.admin {
            background: linear-gradient(135deg, #434a5c 0%, #1a1f2c 100%);
        }
        .signin-icon { font-size: 78px; line-height: 1; }
        .signin-title { font-size: 28px; font-weight: 700; margin: 0; }
        .signin-sub { font-size: 14px; opacity: .92; margin: 0; max-width: 280px; }
        .signin-method {
            font-size: 12px;
            opacity: .8;
            margin-top: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,.18);
            padding: 5px 12px;
            border-radius: 100px;
        }

        .footer-text {
            text-align: center;
            color: white;
            margin-top: 24px;
            font-size: 13px;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="welcome-container">
        <div class="welcome-card">
            @php
                $settings = App\Models\Setting::first();
            @endphp

            <div class="welcome-header">
                @if($settings && $settings->company_logo)
                    <img src="{{ asset('storage/' . $settings->company_logo) }}"
                         alt="{{ $settings->company_name ?? 'Company' }} Logo"
                         class="company-logo">
                @else
                    <div style="font-size: 68px; margin-bottom: 16px;">📱</div>
                @endif
                <h1 class="welcome-title">{{ $settings->company_name ?? 'Company Directory' }}</h1>
                <p class="welcome-subtitle">Sign in to continue</p>
            </div>

            <div class="signin-grid">
                {{-- User sign-in — SSO-only. Lands in the user portal. --}}
                <a href="{{ route('portal.login') }}" class="signin-card user">
                    <i class="bi bi-person-circle signin-icon"></i>
                    <h2 class="signin-title">User Sign In</h2>
                    <p class="signin-sub">Directory, printers, remote browser &amp; more</p>
                    <span class="signin-method">
                        <i class="bi bi-microsoft"></i> Microsoft SSO
                    </span>
                </a>

                {{-- Admin sign-in — username + password. --}}
                <a href="{{ route('login') }}" class="signin-card admin">
                    <i class="bi bi-shield-lock-fill signin-icon"></i>
                    <h2 class="signin-title">Admin Sign In</h2>
                    <p class="signin-sub">Operators & administrators</p>
                    <span class="signin-method">
                        <i class="bi bi-key-fill"></i> Username / password
                    </span>
                </a>
            </div>
        </div>

        <div class="footer-text">
            <p>&copy; {{ date('Y') }} {{ $settings->company_name ?? 'Company' }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
