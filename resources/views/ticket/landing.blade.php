<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Samir Group Assistant</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root { --red:#C8102E; --red-d:#9e0c24; --ink:#0f172a; --muted:#64748b; --line:#e7e9ee; --bg:#f4f5f7; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif; line-height:1.6; }
        html[dir="rtl"] body { font-family:'Cairo',system-ui,Segoe UI,sans-serif; }
        .lang-ar { display:none; }
        html[dir="rtl"] .lang-en { display:none; }
        html[dir="rtl"] .lang-ar { display:inline; }
        .wrap { max-width:760px; margin:0 auto; padding:0 16px 40px; }
        .topbar { background:#fff; border-bottom:1px solid var(--line); }
        .topbar .inner { max-width:760px; margin:0 auto; padding:12px 16px; display:flex; align-items:center; justify-content:space-between; }
        .brand { display:flex; align-items:center; gap:10px; }
        .brand img { height:40px; width:auto; display:block; }
        .langbtn { background:transparent; border:1px solid #cbd5e1; color:#475569; border-radius:8px; padding:6px 14px; font:inherit; font-size:13px; cursor:pointer; }
        .langbtn:hover { background:#f1f5f9; }
        .hero { background:var(--red); color:#fff; text-align:center; padding:40px 20px 44px; }
        .hero .badge { width:64px; height:64px; border-radius:16px; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; margin:0 auto 16px; font-size:30px; }
        .hero h1 { margin:0 0 8px; font-size:28px; font-weight:600; }
        .hero p { margin:0 auto 22px; font-size:16px; max-width:520px; color:#fde8ea; }
        .cta { display:inline-flex; align-items:center; gap:10px; background:#fff; color:var(--red); font-weight:600; font-size:17px; padding:14px 30px; border-radius:12px; box-shadow:0 6px 20px rgba(0,0,0,.18); }
        .cta:hover { background:#fff5f6; }
        .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:20px; margin-top:20px; }
        .notice { display:flex; gap:12px; align-items:flex-start; background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; border-radius:12px; padding:14px 16px; margin-top:-22px; position:relative; }
        .notice svg { flex-shrink:0; margin-top:2px; }
        .sectlabel { font-size:13px; font-weight:600; color:var(--muted); letter-spacing:.02em; margin:0 0 12px; }
        .apps { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .region h3 { display:flex; align-items:center; gap:8px; font-size:15px; font-weight:600; margin:0 0 10px; }
        .store { display:flex; align-items:center; gap:11px; background:var(--ink); color:#fff; border-radius:11px; padding:10px 15px; margin-bottom:10px; transition:transform .08s; }
        .store:hover { transform:translateY(-1px); }
        .store small { display:block; font-size:11px; opacity:.82; }
        .store b { display:block; font-size:16px; font-weight:500; }
        a { text-decoration:none; color:inherit; }
        .feats { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
        .feat { background:#fff; border:1px solid var(--line); border-radius:14px; padding:16px; }
        .feat .ic { width:40px; height:40px; border-radius:10px; background:#fdecef; color:var(--red); display:flex; align-items:center; justify-content:center; font-size:20px; margin-bottom:10px; }
        .feat h4 { margin:0 0 3px; font-size:14px; font-weight:600; }
        .feat p { margin:0; font-size:13px; color:var(--muted); }
        .foot { text-align:center; margin-top:26px; color:var(--muted); font-size:13px; }
        .beta { display:inline-block; background:#eef2ff; color:#3730a3; font-size:12px; font-weight:500; padding:5px 14px; border-radius:8px; margin-bottom:10px; }
        html[dir="rtl"] .flow { text-align:right; }
        @media (max-width:540px){ .apps,.feats{ grid-template-columns:1fr; } .hero h1{ font-size:24px; } }
    </style>
</head>
<body>
    <div class="topbar">
        <div class="inner">
            <div class="brand">
                @if($logoSrc)
                    <img src="{{ $logoSrc }}" alt="Samir Group">
                @else
                    <svg height="40" viewBox="0 0 210 60" fill="none" role="img" aria-label="Samir Group">
                        <path d="M8 42 C 26 8, 48 8, 60 32 C 68 48, 86 48, 98 26" stroke="#C8102E" stroke-width="9" stroke-linecap="round"/>
                        <path d="M14 47 C 30 20, 48 20, 59 39" stroke="#E8536A" stroke-width="5" stroke-linecap="round" opacity=".85"/>
                        <text x="112" y="32" font-family="Inter,Segoe UI,sans-serif" font-size="29" font-weight="600" letter-spacing="3" fill="#3b3f46">SAMIR</text>
                        <text x="112" y="51" font-family="Cairo,'Segoe UI',sans-serif" font-size="17" fill="#C8102E">سمير</text>
                    </svg>
                @endif
            </div>
            <button class="langbtn" onclick="toggleLang()">
                <span class="lang-en">العربية</span><span class="lang-ar">English</span>
            </button>
        </div>
    </div>

    <div class="hero">
        <div class="badge">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14v-3a8 8 0 0 1 16 0v3"/><path d="M18 19a2 2 0 0 1-2 2h-2"/><rect x="2" y="14" width="4" height="6" rx="1.5"/><rect x="18" y="14" width="4" height="6" rx="1.5"/></svg>
        </div>
        <h1><span class="lang-en">Samir Group Assistant</span><span class="lang-ar">تطبيق Samir Group Assistant</span></h1>
        <p class="flow">
            <span class="lang-en">Your centralized IT support platform — submit, track and resolve technical requests with full transparency.</span>
            <span class="lang-ar">منصتك المركزية لدعم تقنية المعلومات — أرسل الطلبات الفنية وتابعها وعالِجها بشفافية كاملة.</span>
        </p>
        <a class="cta" href="{{ $webAppUrl }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a14 14 0 0 0 0 18 14 14 0 0 0 0-18"/></svg>
            <span class="lang-en">Open Web App</span><span class="lang-ar">افتح تطبيق الويب</span>
        </a>
    </div>

    <div class="wrap">
        <div class="notice">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
            <p style="margin:0" class="flow">
                <span class="lang-en">From today, all IT issues are handled <strong>only</strong> through the app — phone calls and emails to the IT department will no longer be accepted.</span>
                <span class="lang-ar">اعتبارًا من اليوم، تُعالَج جميع مشكلات تقنية المعلومات <strong>فقط</strong> عبر التطبيق — لن يتم قبول المكالمات أو رسائل البريد الإلكتروني الخاصة بقسم تقنية المعلومات.</span>
            </p>
        </div>

        <div class="card">
            <p class="sectlabel flow"><span class="lang-en">Get the mobile app</span><span class="lang-ar">حمّل تطبيق الهاتف</span></p>
            <div class="apps">
                @php
                    $regions = [
                        'egypt' => ['en' => 'Egypt', 'ar' => 'مصر', 'flag' => '🇪🇬'],
                        'ksa'   => ['en' => 'Saudi Arabia', 'ar' => 'السعودية', 'flag' => '🇸🇦'],
                    ];
                @endphp
                @foreach($regions as $key => $r)
                <div class="region">
                    <h3 class="flow"><span>{{ $r['flag'] }}</span> <span class="lang-en">{{ $r['en'] }}</span><span class="lang-ar">{{ $r['ar'] }}</span></h3>
                    @if(!empty($apps[$key]['android']))
                    <a class="store" href="{{ $apps[$key]['android'] }}">
                        <svg width="19" height="21" viewBox="0 0 24 24" fill="#fff"><path d="M3 2.5v19a1 1 0 0 0 1.5.87l16-9.5a1 1 0 0 0 0-1.74l-16-9.5A1 1 0 0 0 3 2.5z"/></svg>
                        <span><small>GET IT ON</small><b>Google Play</b></span>
                    </a>
                    @endif
                    @if(!empty($apps[$key]['ios']))
                    <a class="store" href="{{ $apps[$key]['ios'] }}">
                        <svg width="19" height="21" viewBox="0 0 384 512" fill="#fff"><path d="M318.7 268.7c-.2-36.7 16.4-64.4 50-84.8-18.8-26.9-47.2-41.7-84.7-44.6-35.5-2.8-74.3 20.7-88.5 20.7-15 0-49.4-19.7-76.4-19.7C63.3 141.2 4 184.8 4 273.5c0 26.2 4.8 53.3 14.4 81.2 12.8 36.7 59 126.7 107.2 125.2 25.2-.6 43-17.9 75.8-17.9 31.8 0 48.3 17.9 76.4 17.9 48.6-.7 90.4-82.5 102.6-119.3-65.2-30.7-61.7-90-61.7-91.9zm-56.6-164.2c27.3-32.4 24.8-61.9 24-72.5-24.1 1.4-52 16.4-67.9 34.9-17.5 19.8-27.8 44.3-25.6 71.9 26.1 2 49.9-11.4 69.5-34.3z"/></svg>
                        <span><small>Download on the</small><b>App Store</b></span>
                    </a>
                    @endif
                </div>
                @endforeach
            </div>
        </div>

        <div class="feats" style="margin-top:20px">
            <div class="feat flow">
                <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l4 4M3 21l4-1L20.5 6.5a2.1 2.1 0 0 0-3-3L4 17l-1 4z"/></svg></div>
                <h4><span class="lang-en">Submit &amp; track</span><span class="lang-ar">إرسال ومتابعة</span></h4>
                <p><span class="lang-en">Structured request workflows</span><span class="lang-ar">سير عمل منظم للطلبات</span></p>
            </div>
            <div class="feat flow">
                <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a2 2 0 0 0 3.4 0"/></svg></div>
                <h4><span class="lang-en">Real-time updates</span><span class="lang-ar">إشعارات فورية</span></h4>
                <p><span class="lang-en">No more manual follow-ups</span><span class="lang-ar">لا متابعة يدوية بعد الآن</span></p>
            </div>
            <div class="feat flow">
                <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4M16 3H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7z"/></svg></div>
                <h4><span class="lang-en">Employee documents</span><span class="lang-ar">مستندات الموظفين</span></h4>
                <p><span class="lang-en">Docs, phone extensions &amp; more</span><span class="lang-ar">الأدلة وأرقام التحويلات وغيرها</span></p>
            </div>
        </div>

        <div class="foot">
            <span class="beta"><span class="lang-en">Beta · 2 weeks</span><span class="lang-ar">نسخة تجريبية · أسبوعان</span></span>
            <p style="margin:0" class="flow">
                <span class="lang-en">Facing an issue? Contact the Development department — Samir Internal Helpdesk Apps Support.</span>
                <span class="lang-ar">واجهت مشكلة؟ تواصل مع قسم التطوير — Samir Internal Helpdesk Apps Support.</span>
            </p>
        </div>
    </div>

    <script>
        function toggleLang() {
            var html = document.documentElement;
            var ar = html.getAttribute('dir') === 'rtl';
            html.setAttribute('dir', ar ? 'ltr' : 'rtl');
            html.setAttribute('lang', ar ? 'en' : 'ar');
        }
    </script>
</body>
</html>
