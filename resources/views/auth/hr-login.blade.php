<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#c8102e">
    <title>HR Portal — Sign in</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --brand: #c8102e; --ink: #3c3c3b; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            background: #fff url('{{ \App\Models\Setting::wallpaperUrl() }}') no-repeat center center;
            background-size: cover;
            display: flex; align-items: center; justify-content: center;
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .card-box {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 50px rgba(0,0,0,.18);
            width: 100%; max-width: 400px;
            padding: 36px 28px;
            text-align: center;
        }
        .brand-logo { height: 46px; width: auto; object-fit: contain; margin-bottom: 22px; }
        .brand-mark {
            width: 76px; height: 76px; border-radius: 18px;
            background: var(--brand);
            display: inline-flex; align-items: center; justify-content: center;
            margin-bottom: 22px;
        }
        .brand-mark img { width: 52px; }
        h1 { font-size: 1.35rem; font-weight: 700; color: var(--ink); margin-bottom: 8px; }
        .subtitle { color: #6c757d; font-size: .93rem; margin-bottom: 26px; line-height: 1.45; }
        .sso-btn {
            width: 100%; padding: 14px 16px;
            border: 2px solid #e6e6e6; border-radius: 12px;
            background: #fff; color: var(--ink); font-weight: 600;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            text-decoration: none;
            transition: box-shadow .15s, transform .05s;
        }
        .sso-btn:hover { box-shadow: 0 4px 14px rgba(0,0,0,.08); }
        .sso-btn:active { transform: translateY(1px); }
        .sso-btn img { width: 20px; height: 20px; }
        .footer-note { margin-top: 24px; color: #adb5bd; font-size: .76rem; }
        .alert-inline {
            background: #fdecea; color: #8a1f1f; border: 1px solid #f1b4b4;
            padding: 10px 12px; border-radius: 10px; margin-bottom: 16px;
            font-size: .88rem; text-align: left;
        }
    </style>
</head>
<body>
@php $settings = \App\Models\Setting::get(); @endphp
<div class="card-box">
    @if ($settings->company_logo ?? false)
        <img class="brand-logo" src="{{ \Illuminate\Support\Facades\Storage::url($settings->company_logo) }}" alt="{{ $settings->company_name }}">
    @else
        <div class="brand-mark"><img src="{{ asset('images/brand/samir-mark.png') }}" alt=""></div>
    @endif

    <h1>HR Portal</h1>
    <p class="subtitle">Sign in with your company Microsoft account to onboard new hires, raise terminations, and request employee data changes.</p>

    @if (session('error'))
        <div class="alert-inline">{{ session('error') }}</div>
    @endif

    @if ($settings->sso_enabled ?? false)
        <a href="{{ route('auth.microsoft') }}" class="sso-btn">
            <img src="https://learn.microsoft.com/en-us/entra/identity-platform/media/howto-add-branding-in-apps/ms-symbollockup_mssymbol_19.svg" alt="">
            Sign in with Microsoft
        </a>
    @else
        <div class="alert-inline">
            Single sign-on is not configured. Please contact IT.
        </div>
    @endif

    <div class="footer-note">
        &copy; {{ date('Y') }} {{ $settings->company_name ?? 'Samir Group' }}
    </div>
</div>
</body>
</html>
