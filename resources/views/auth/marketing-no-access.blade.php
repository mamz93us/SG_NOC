<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Marketing — No access</title>
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
            width: 100%; max-width: 440px;
            padding: 36px 28px;
            text-align: center;
        }
        .lock-icon {
            width: 86px; height: 86px;
            border-radius: 20px;
            background: linear-gradient(135deg,#1a56db,#6c47ff);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 40px; color: #fff;
            box-shadow: 0 8px 18px rgba(108,71,255,.35);
            margin-bottom: 18px;
        }
        h1 { font-size: 1.35rem; font-weight: 700; margin: 0 0 8px; color: #222; }
        .subtitle { color: #555; font-size: 0.95rem; margin-bottom: 22px; line-height: 1.5; }
        .who { color: #888; font-size: .82rem; margin-bottom: 22px; }
        .signout-btn {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e3e3e3;
            border-radius: 12px;
            background: #fff;
            color: #222;
            font-weight: 600;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            cursor: pointer;
            transition: box-shadow .15s, transform .05s;
        }
        .signout-btn:hover { box-shadow: 0 4px 14px rgba(0,0,0,.08); }
        .signout-btn:active { transform: translateY(1px); }
        .footer-note { margin-top: 24px; color: #999; font-size: .78rem; }
    </style>
</head>
<body>
    @php $settings = \App\Models\Setting::get(); @endphp
    <div class="portal-card">
        <div class="lock-icon"><i class="bi bi-shield-lock"></i></div>

        <h1>No marketing access yet</h1>
        <p class="subtitle">
            {{ session('error') ?? 'Your account does not have access to the marketing portal yet. Please contact your administrator to be granted access.' }}
        </p>

        @auth
            <p class="who">Signed in as {{ auth()->user()->email }}</p>
        @endauth

        <form method="POST" action="{{ route('portal.marketing.logout') }}">
            @csrf
            <button type="submit" class="signout-btn">
                <i class="bi bi-box-arrow-right"></i> Sign out / use another account
            </button>
        </form>

        <div class="footer-note">
            &copy; {{ date('Y') }} {{ $settings->company_name ?? 'Samir Group' }} — Marketing
        </div>
    </div>
</body>
</html>
