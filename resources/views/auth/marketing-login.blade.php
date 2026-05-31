<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Marketing — Sign in</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        html, body { height: 100%; }
        body {
            margin: 0;
            background: linear-gradient(135deg,#1a56db 0%,#6c47ff 100%);
            display: flex; align-items: center; justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .portal-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,.2);
            width: 100%; max-width: 420px;
            padding: 36px 28px;
            text-align: center;
        }
        .brand-logo-box {
            display: inline-flex; align-items: center; justify-content: center;
            background: #fff; border: 1px solid #eee; border-radius: 14px;
            padding: 10px 16px; margin-bottom: 18px; box-shadow: 0 4px 12px rgba(0,0,0,.06);
        }
        .brand-logo-box img { height: 44px; width: auto; object-fit: contain; }
        .portal-logo {
            width: 86px; height: 86px;
            border-radius: 20px;
            background: linear-gradient(135deg,#1a56db,#6c47ff);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 40px; color: #fff;
            box-shadow: 0 8px 18px rgba(108,71,255,.35);
            margin-bottom: 18px;
        }
        h1 { font-size: 1.45rem; font-weight: 700; margin: 0 0 8px; color: #222; }
        .subtitle { color: #666; font-size: 0.95rem; margin-bottom: 26px; }
        .sso-btn {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e3e3e3;
            border-radius: 12px;
            background: #fff;
            color: #222;
            font-weight: 600;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            transition: box-shadow .15s, transform .05s;
            text-decoration: none;
        }
        .sso-btn:hover { box-shadow: 0 4px 14px rgba(0,0,0,.08); color: #222; }
        .sso-btn:active { transform: translateY(1px); }
        .sso-btn img { width: 20px; height: 20px; }
        .footer-note { margin-top: 24px; color: #999; font-size: .78rem; }
        .alert-inline {
            background: #fdecea; color: #8a1f1f; border: 1px solid #f1b4b4;
            padding: 10px 12px; border-radius: 10px; margin-bottom: 16px;
            font-size: .88rem; text-align: left;
        }
    </style>
</head>
<body>
    @php $settings = \App\Models\Setting::get(); @endphp
    <div class="portal-card">
        @if ($settings->company_logo ?? false)
            <div class="brand-logo-box">
                <img src="{{ \Illuminate\Support\Facades\Storage::url($settings->company_logo) }}" alt="Logo">
            </div>
        @else
            <div class="portal-logo"><i class="bi bi-envelope-paper"></i></div>
        @endif

        <h1>Email Marketing</h1>
        <p class="subtitle">Sign in with your company Microsoft account to manage campaigns.</p>

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
                Single sign-on is not configured. Please contact your administrator.
            </div>
        @endif

        <div class="footer-note">
            &copy; {{ date('Y') }} {{ $settings->company_name ?? 'Samir Group' }} — Marketing
        </div>
    </div>
</body>
</html>
