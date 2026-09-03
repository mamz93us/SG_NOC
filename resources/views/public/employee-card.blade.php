<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <title>{{ $name }} — {{ $company }}</title>
    <meta property="og:title" content="{{ $name }}">
    <meta property="og:description" content="{{ $job_title }}{{ $department ? ' · ' . $department : '' }} · {{ $company }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            /* Samir brand — same values as the Wallet pass (WalletPassService)
               and the login wallpaper, so the three read as one system. */
            --brand:    #c8102e;
            --ink:      #3c3c3b;
            --card-bg:  #ffffff;
            --text:     #3c3c3b;
            --muted:    #6c757d;
            --border:   #e9ecef;
            --radius:   18px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            /* Clean brand-tinted base. Most views of this page are a phone, where
               the wallpaper below would be cropped to a ~26% slice and the red
               wave would cut in at the edges as arbitrary slabs. */
            background: #f4f4f6;
            background-image:
                radial-gradient(120% 55% at 50% 0%, rgba(200,16,46,.10) 0%, rgba(200,16,46,0) 60%);
            background-repeat: no-repeat;
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 24px 16px 48px;
        }
        /* Wide enough to show the wave as composed rather than cropped: use the
           same branded wallpaper as the login / 2FA screens. */
        @media (min-width: 768px) {
            body {
                background:
                    radial-gradient(120% 55% at 50% 0%, rgba(200,16,46,.10) 0%, rgba(200,16,46,0) 60%),
                    #f4f4f6 url('{{ \App\Models\Setting::wallpaperUrl() }}') no-repeat center center fixed;
                background-size: auto, cover;
            }
        }
        .card-wrap {
            width: 100%;
            max-width: 400px;
        }

        /* ── Main card ── */
        .id-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: 0 18px 50px rgba(60,60,59,.18), 0 3px 12px rgba(60,60,59,.08);
            overflow: hidden;
        }

        /* Header stripe — white, so the logo prints in its true red & grey.
           Same call as the Wallet pass; a knocked-out logo is the fallback for
           dark surfaces, not the default. */
        .id-card__header {
            background: #fff;
            border-bottom: 3px solid var(--brand);
            padding: 20px 24px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .id-card__logo {
            height: 38px;
            max-width: 150px;
            object-fit: contain;
        }
        .id-card__logo-text {
            color: var(--ink);
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* Avatar + name */
        .id-card__profile {
            padding: 28px 24px 20px;
            text-align: center;
            border-bottom: 1px solid var(--border);
        }
        .id-card__avatar {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: var(--brand);
            color: #fff;
            font-size: 32px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            box-shadow: 0 4px 16px rgba(200,16,46,.32);
        }
        .id-card__name {
            font-size: 22px;
            font-weight: 700;
            color: var(--text);
            line-height: 1.2;
            margin-bottom: 4px;
        }
        .id-card__title {
            font-size: 14px;
            color: var(--brand);
            font-weight: 600;
            margin-bottom: 2px;
        }
        .id-card__dept {
            font-size: 13px;
            color: var(--muted);
        }

        /* Contact rows */
        .id-card__contact {
            padding: 8px 24px 16px;
        }
        .contact-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 0;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
            color: var(--text);
            text-decoration: none;
        }
        .contact-row:last-child { border-bottom: none; }
        .contact-row i {
            font-size: 17px;
            color: var(--brand);
            width: 22px;
            flex-shrink: 0;
            text-align: center;
        }
        .contact-row span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .contact-row .lbl {
            font-size: 11px;
            color: var(--muted);
            display: block;
            line-height: 1;
            margin-bottom: 1px;
        }
        .contact-row .val { font-size: 14px; font-weight: 500; }
        a.contact-row:hover .val { color: var(--brand); }

        /* Action buttons */
        .id-card__actions {
            padding: 16px 24px 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            border-top: 1px solid var(--border);
        }
        .btn-action {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 11px 8px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: opacity .15s, transform .1s;
        }
        .btn-action:active { transform: scale(.97); opacity: .85; }
        button.btn-action { font-family: inherit; }
        .btn-primary-action {
            background: var(--brand);
            color: #fff;
        }
        .btn-secondary-action {
            background: #f1f3f5;
            color: var(--ink);
        }
        .btn-wallet {
            grid-column: 1 / -1;
            background: #000;
            color: #fff;
        }
        .btn-action i { font-size: 16px; }

        /* QR section */
        .id-card__qr {
            padding: 16px 24px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            border-top: 1px solid var(--border);
        }
        .id-card__qr p {
            font-size: 11px;
            color: var(--muted);
            text-align: center;
        }
        .qr-svg-wrap {
            width: 120px;
            height: 120px;
        }
        .qr-svg-wrap svg {
            width: 100%;
            height: 100%;
        }

        /* Footer */
        .card-footer {
            margin-top: 16px;
            text-align: center;
            font-size: 11px;
            color: var(--muted);
        }
        .card-footer a { color: var(--brand); text-decoration: none; font-weight: 600; }
        .card-footer a:hover { text-decoration: underline; }

        @media print {
            body { background: #fff; padding: 0; }
            .id-card { box-shadow: none; border: 1px solid #ddd; }
            .id-card__actions, .id-card__qr { display: none; }
        }
    </style>
</head>
<body>
<div class="card-wrap">
    <div class="id-card">

        {{-- Header --}}
        <div class="id-card__header">
            @if($logo_path)
                <img src="{{ $logo_path }}" alt="{{ $company }}" class="id-card__logo">
            @else
                <span class="id-card__logo-text">{{ $company }}</span>
            @endif
        </div>

        {{-- Profile --}}
        <div class="id-card__profile">
            <div class="id-card__avatar">{{ $initials }}</div>
            <div class="id-card__name">{{ $name }}</div>
            @if($job_title)
            <div class="id-card__title">{{ $job_title }}</div>
            @endif
            @if($department)
            <div class="id-card__dept">{{ $department }}</div>
            @endif
        </div>

        {{-- Contact rows --}}
        <div class="id-card__contact">
            @if($email)
            <a class="contact-row" href="mailto:{{ $email }}">
                <i class="bi bi-envelope-fill"></i>
                <span>
                    <span class="lbl">Email</span>
                    <span class="val">{{ $email }}</span>
                </span>
            </a>
            @endif
            @if($phone)
            <a class="contact-row" href="tel:{{ $phone }}">
                <i class="bi bi-telephone-fill"></i>
                <span>
                    <span class="lbl">Work</span>
                    <span class="val">{{ $phone }}</span>
                </span>
            </a>
            @endif
            @if($mobile)
            <a class="contact-row" href="tel:{{ $mobile }}">
                <i class="bi bi-phone-fill"></i>
                <span>
                    <span class="lbl">Mobile</span>
                    <span class="val">{{ $mobile }}</span>
                </span>
            </a>
            @endif
            @if($extension)
            <div class="contact-row">
                <i class="bi bi-telephone-plus-fill"></i>
                <span>
                    <span class="lbl">Extension</span>
                    <span class="val">{{ $extension }}</span>
                </span>
            </div>
            @endif
            @if($branch || $city)
            <div class="contact-row">
                <i class="bi bi-building-fill"></i>
                <span>
                    <span class="lbl">Office</span>
                    <span class="val">{{ implode(', ', array_filter([$branch, $city])) }}</span>
                </span>
            </div>
            @endif
        </div>

        {{-- Actions --}}
        <div class="id-card__actions">
            @if($email)
            <a href="mailto:{{ $email }}" class="btn-action btn-primary-action">
                <i class="bi bi-envelope"></i> Email
            </a>
            @endif
            @if($mobile ?: $phone)
            <a href="tel:{{ $mobile ?: $phone }}" class="btn-action btn-secondary-action">
                <i class="bi bi-telephone"></i> Call
            </a>
            @endif
            <a href="{{ $vcard_url }}" class="btn-action btn-secondary-action" download>
                <i class="bi bi-person-plus"></i> Save Contact
            </a>
            <button type="button" class="btn-action btn-secondary-action" id="shareBtn"
                    data-url="{{ $card_url }}" data-name="{{ $name }}">
                <i class="bi bi-share"></i> <span id="shareLabel">Share</span>
            </button>
            {{-- Wallet actions require a logged-in session; hide them from public viewers --}}
            @auth
                @if($wallet_ready)
                <a href="{{ $wallet_url }}" class="btn-action btn-wallet">
                    <i class="bi bi-wallet2"></i> Add to Apple Wallet
                </a>
                @endif
                @if($samsung_ready ?? false)
                {{-- A redirect to Samsung's add-to-wallet link, not a download —
                     the card itself travels inside the URL. --}}
                <a href="{{ $samsung_url }}" class="btn-action btn-wallet">
                    <i class="bi bi-wallet2"></i> Add to Samsung Wallet
                </a>
                @endif
            @endauth
        </div>

        {{-- QR Code --}}
        <div class="id-card__qr">
            @php
                use chillerlan\QRCode\QRCode;
                use chillerlan\QRCode\QROptions;
                $qrOpts = new QROptions;
                $qrOpts->eccLevel     = 'M';
                $qrOpts->outputBase64 = false; // inline <svg>, not a data: URI
                $qrSvg  = (new QRCode($qrOpts))->render($card_url);
            @endphp
            <div class="qr-svg-wrap">{!! $qrSvg !!}</div>
            <p>Scan to share this card</p>
        </div>

    </div><!-- /.id-card -->

    <div class="card-footer">
        {{ $company }} · Digital Business Card
        @if($is_owner)
            · <a href="#" onclick="event.preventDefault();document.getElementById('signOutForm').submit();">Sign out</a>
        @endif
    </div>

    @if($is_owner)
    <form id="signOutForm" method="POST" action="{{ \Illuminate\Support\Facades\Route::has('vcard.logout') ? route('vcard.logout') : route('logout') }}" hidden>
        @csrf
    </form>
    @endif
</div>

<script>
    // Native share sheet where the browser supports it (iOS/Android), clipboard
    // copy everywhere else. Both share the canonical card-host URL, never the
    // host this page happens to be open on.
    (function () {
        var btn = document.getElementById('shareBtn');
        if (!btn) return;

        var label = document.getElementById('shareLabel');

        btn.addEventListener('click', async function () {
            var url = btn.dataset.url;
            var name = btn.dataset.name;

            if (navigator.share) {
                try {
                    await navigator.share({ title: name, text: name, url: url });
                    return;
                } catch (e) {
                    // User dismissed the sheet, or share is blocked — fall through to copy.
                    if (e && e.name === 'AbortError') return;
                }
            }

            try {
                await navigator.clipboard.writeText(url);
            } catch (e) {
                // Clipboard API needs a secure context; prompt() always works.
                window.prompt('Copy this link:', url);
                return;
            }

            var original = label.textContent;
            label.textContent = 'Link copied';
            setTimeout(function () { label.textContent = original; }, 1800);
        });
    })();
</script>
</body>
</html>
