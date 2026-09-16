<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', __('archive_layout.default_title'))</title>
    <link rel="icon" href="{{ asset('images/brand/samir-mark.png') }}">

    {{--
        The same chrome as the employee portal — the gradient header carrying the
        Samir mark, the live clock, the dark footer — because to the people using
        it this is one system with several rooms, not a separate product. The
        palette, the type stack and the shadow language are lifted from
        layouts/home.blade.php deliberately: if the brand changes there it should
        change here in the same breath.

        Bootstrap stays, though. The home portal is a launcher — a dozen big tiles
        and nothing else. This is a reading and filing tool: tables of documents, a
        search form per archive, a viewer pane, a filing form. Its views are built
        on Bootstrap's grid, tables and form controls. Re-theming Bootstrap onto
        the Samir palette is the cheap half of the job; rewriting every archive
        page is not, and it would buy nothing a reader could see.
    --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap{{ app()->getLocale() === 'ar' ? '.rtl' : '' }}.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
      :root{
        /* Samir brand palette — from the Email Signature Guidelines (EC2024 / 58595B / D8D8D8) */
        --gray-900:#2B2C2D;
        --gray-800:#3E3F41;
        --gray-700:#58595B;
        --gray-600:#77787A;
        --gray-500:#9A9B9D;
        --red-600:#EC2024;
        --red-500:#F13337;
        --red-700:#C81A1E;
        --red-100:#FCE6E6;
        --bg:#F4F4F5;
        --card:#FFFFFF;
        --ink:#2B2C2D;
        --ink-soft:#6B6C6E;
        --line:#E4E4E5;
        --green:#16A34A;
        --amber:#D97706;
        --shadow: 0 1px 2px rgba(43,44,45,.05), 0 8px 24px rgba(43,44,45,.06);
        --shadow-hover: 0 4px 10px rgba(43,44,45,.08), 0 18px 40px rgba(43,44,45,.12);
        --font-sans:'Montserrat','Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,sans-serif;
        --font-ar:'Cairo','Segoe UI',Tahoma,sans-serif;

        /* Bootstrap, re-themed onto the palette rather than replaced. */
        --bs-body-font-family:var(--font-sans);
        --bs-body-font-size:.9rem;
        --bs-body-color:var(--ink);
        --bs-body-bg:var(--bg);
        --bs-primary:var(--red-600);
        --bs-link-color:var(--red-700);
        --bs-link-hover-color:var(--red-600);
        --bs-border-color:var(--line);
      }

      html{ height:100%; }
      body{
        background:var(--bg);
        color:var(--ink);
        font-family:var(--font-sans);
        -webkit-font-smoothing:antialiased;
        min-height:100vh;
        display:flex;
        flex-direction:column;
      }

      /* ===== Header =====
         `body > header`, not a bare `header`: this paints a dark gradient, and a
         page is free to use <header> for a card or a section title. The home
         portal learned that one the hard way — its ticket page and My Assets
         ended up with unreadable dark bars where their titles should have been. */
      body > header{
        position:relative;
        flex-shrink:0;
        background:linear-gradient(120deg, var(--gray-900) 0%, var(--gray-800) 55%, var(--gray-700) 100%);
        overflow:hidden;
        padding:18px clamp(16px, 4vw, 40px);
      }
      body > header::before{
        content:"";
        position:absolute; inset:0;
        background:
          radial-gradient(600px 240px at 85% -20%, rgba(236,32,36,.18), transparent 60%),
          radial-gradient(400px 200px at 15% 120%, rgba(236,32,36,.10), transparent 60%);
        pointer-events:none;
      }
      .arc-header-inner{
        position:relative;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:20px;
        max-width:1600px;
        margin:0 auto;
        flex-wrap:wrap;
      }
      .arc-brand{ display:flex; align-items:center; gap:18px; text-decoration:none; }
      .arc-brand img{ height:34px; width:auto; display:block; filter:brightness(0) invert(1); }
      .arc-brand-divider{ width:1px; height:32px; background:rgba(255,255,255,.25); }
      .arc-brand-title h1{
        font-weight:700;
        font-size:clamp(16px, 2vw, 21px);
        letter-spacing:.4px;
        color:#fff;
        margin:0;
      }
      .arc-brand-title p{
        font-family:var(--font-ar);
        font-size:12.5px;
        color:rgba(255,255,255,.6);
        margin:2px 0 0;
      }

      .arc-header-right{ display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
      .arc-header-link{
        display:inline-flex; align-items:center; gap:7px;
        background:rgba(255,255,255,.08);
        border:1px solid rgba(255,255,255,.16);
        color:rgba(255,255,255,.85);
        border-radius:10px;
        padding:7px 13px;
        font-size:12.5px;
        font-weight:600;
        text-decoration:none;
        transition:background .18s ease, color .18s ease;
      }
      .arc-header-link:hover{ background:rgba(255,255,255,.16); color:#fff; }
      .arc-header-clock{ display:flex; align-items:center; gap:10px; color:#fff; }
      .arc-header-clock svg{ flex-shrink:0; opacity:.85; }
      .arc-header-clock .time{ font-size:18px; font-weight:600; letter-spacing:.3px; line-height:1.15; }
      .arc-header-clock .date{ font-size:12px; color:rgba(255,255,255,.62); margin-top:1px; }
      .arc-header-user{ color:rgba(255,255,255,.72); font-size:12.5px; font-weight:600; }
      .arc-header-signout{
        background:rgba(255,255,255,.08);
        border:1px solid rgba(255,255,255,.16);
        color:rgba(255,255,255,.75);
        border-radius:10px;
        padding:7px 13px;
        font-size:12.5px;
        font-weight:600;
        cursor:pointer;
        transition:background .18s ease, color .18s ease;
      }
      .arc-header-signout:hover{ background:rgba(255,255,255,.16); color:#fff; }

      /* ===== Content ===== */
      main{
        flex:1;
        width:100%;
        max-width:1600px;
        margin:0 auto;
        padding:clamp(18px,3vw,30px) clamp(16px,4vw,40px) 52px;
      }

      /* The card in the portal's shape: same border, same shadow, same 18px
         radius. Not the home portal's own `.card` — that is a 26px-padded
         clickable tile built for a launcher, and a table of half a million
         invoices inside one would be absurd. */
      .arc-card{
        background:var(--card);
        border:1px solid var(--line);
        border-radius:18px;
        box-shadow:var(--shadow);
      }
      .arc-muted{ color:var(--ink-soft); }
      .arc-empty{ padding:3rem 1rem; text-align:center; color:var(--ink-soft); }

      .btn-brand{ background:var(--red-600); border-color:var(--red-600); color:#fff; font-weight:600; }
      .btn-brand:hover{ background:var(--red-700); border-color:var(--red-700); color:#fff; }
      .btn-brand:focus-visible{ outline:2px solid var(--red-500); outline-offset:2px; }
      .badge-soft{ background:var(--red-100); color:var(--red-700); font-weight:600; }

      .arc-table th{
        font-size:.74rem;
        text-transform:uppercase;
        letter-spacing:.4px;
        color:var(--ink-soft);
        font-weight:700;
      }
      .arc-table td{ vertical-align:middle; }

      .arc-viewer{
        width:100%;
        height:78vh;
        border:1px solid var(--line);
        border-radius:12px;
        background:#fff;
      }

      a{ color:var(--red-700); }
      .form-control:focus, .form-select:focus{
        border-color:var(--red-500);
        box-shadow:0 0 0 .2rem rgba(236,32,36,.12);
      }

      /* ===== Footer ===== */
      footer{
        background:var(--gray-900);
        color:rgba(255,255,255,.5);
        text-align:center;
        padding:16px 20px;
        font-size:12.5px;
        flex-shrink:0;
      }

      @media (max-width:720px){
        .arc-header-clock, .arc-header-user{ display:none; }
      }
    </style>
    @stack('head')
</head>
<body>

<header>
  <div class="arc-header-inner">
    <a class="arc-brand" href="{{ route('archive.index') }}">
      <img src="{{ asset('images/brand/samir-logo.png') }}" alt="{{ __('archive_layout.brand_subtitle') }}">
      <div class="arc-brand-divider"></div>
      <div class="arc-brand-title">
        <h1>{{ __('archive_layout.brand_title') }}</h1>
        <p>{{ __('archive_layout.brand_subtitle') }}</p>
      </div>
    </a>

    @auth
      <div class="arc-header-right">
        @can('use-archive-portal')
          <a class="arc-header-link" href="{{ route('archive.inbox') }}">
            <i class="bi bi-inbox"></i> {{ __('archive_layout.inbox') }}
          </a>
        @endcan
        @can('manage-archive-portal')
          <a class="arc-header-link" href="{{ route('archive.manage.index') }}">
            <i class="bi bi-sliders"></i> {{ __('archive_layout.manage') }}
          </a>
        @endcan

        <div class="arc-header-clock">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9.25"/><path d="M12 7v5.2l3.4 2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <div>
            <div class="time" id="arcClockTime">--:--</div>
            <div class="date" id="arcClockDate">&nbsp;</div>
          </div>
        </div>

        <span class="arc-header-user">{{ auth()->user()->name }}</span>

        <form method="POST" action="{{ route('archive.logout') }}" class="m-0">
          @csrf
          <button type="submit" class="arc-header-signout">{{ __('archive_layout.sign_out') }}</button>
        </form>
      </div>
    @endauth
  </div>
</header>

<main>
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @yield('content')
</main>

<footer>
  {{ __('archive_layout.footer', ['year' => now()->year]) }}
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // The same live clock as the employee portal's header. Rendered as a
  // placeholder first so the header does not jump as the page settles.
  (function () {
    var timeEl = document.getElementById('arcClockTime');
    var dateEl = document.getElementById('arcClockDate');
    if (!timeEl || !dateEl) return;

    function tick() {
      var now = new Date();
      timeEl.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      dateEl.textContent = now.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
    }
    tick();
    setInterval(tick, 15000);
  })();
</script>
@stack('scripts')
</body>
</html>
