<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#c8102e">
    <title>No card yet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --brand: #c8102e; --ink: #3c3c3b; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            background: #f5f5f7;
            display: flex; align-items: center; justify-content: center;
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .box {
            background: #fff; border-radius: 18px;
            box-shadow: 0 12px 36px rgba(0,0,0,.1);
            max-width: 420px; padding: 36px 28px; text-align: center;
        }
        .icon {
            width: 68px; height: 68px; border-radius: 50%;
            background: #fdecea; color: var(--brand);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 30px; margin-bottom: 18px;
        }
        h1 { font-size: 1.25rem; color: var(--ink); margin-bottom: 10px; }
        p { color: #6c757d; font-size: .93rem; line-height: 1.5; margin-bottom: 8px; }
        code { background: #f1f3f5; padding: 2px 6px; border-radius: 5px; font-size: .85rem; color: var(--ink); }
        .signout {
            display: inline-block; margin-top: 22px;
            color: #6c757d; font-size: .85rem; text-decoration: none;
        }
        .signout:hover { color: var(--brand); }
    </style>
</head>
<body>
<div class="box">
    <div class="icon"><i class="bi bi-person-badge"></i></div>
    <h1>No card for this account</h1>
    <p>
        We couldn't find an active employee record matching
        @if($email)<code>{{ $email }}</code>@else your account @endif.
    </p>
    <p>Your card is created from your HR record — ask IT to check that your work email matches the one on file.</p>

    <form method="POST" action="{{ route('vcard.logout') }}">
        @csrf
        <button type="submit" class="signout" style="background:none;border:none;cursor:pointer;">
            <i class="bi bi-box-arrow-right"></i> Sign out
        </button>
    </form>
</div>
</body>
</html>
