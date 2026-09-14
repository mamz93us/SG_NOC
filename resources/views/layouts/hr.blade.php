<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'HR Portal') · Samir Group</title>
    <link rel="icon" href="{{ asset('images/brand/samir-mark.png') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    {{--
        Same visual system as the employee home portal (layouts/home): the Samir
        greys, one brand red, white cards on an off-white ground. Bootstrap stays
        because the request forms, the employee pickers and the tables are built
        on it — this re-themes it onto that palette instead of replacing it, so
        no form had to be rewritten.

        Light only, like the home portal. The purple gradient, the rainbow tiles
        and the NOC's dark-mode preference no longer apply here.
    --}}
    <style>
      :root{
        /* Samir brand palette — shared with layouts/home */
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

        /* Bootstrap, re-themed onto that palette */
        --bs-body-font-family:var(--font-sans);
        --bs-body-font-size:.9rem;
        --bs-body-color:var(--ink);
        --bs-body-color-rgb:43,44,45;
        --bs-body-bg:var(--bg);
        --bs-body-bg-rgb:244,244,245;
        --bs-secondary-color:var(--ink-soft);
        --bs-border-color:var(--line);
        --bs-border-radius:10px;
        --bs-border-radius-sm:8px;
        --bs-border-radius-lg:14px;
        --bs-link-color:var(--red-700);
        --bs-link-color-rgb:200,26,30;
        --bs-link-hover-color:var(--red-600);
        --bs-link-hover-color-rgb:236,32,36;
        --bs-focus-ring-color:rgba(236,32,36,.18);

        --bs-primary:var(--red-600);
        --bs-primary-rgb:236,32,36;
        --bs-primary-text-emphasis:var(--red-700);
        --bs-primary-bg-subtle:var(--red-100);
        --bs-primary-border-subtle:#F5BDBE;

        /* "info" was cyan; here it is the calm neutral. */
        --bs-info:var(--gray-700);
        --bs-info-rgb:88,89,91;
        --bs-info-text-emphasis:var(--gray-800);
        --bs-info-bg-subtle:#EEEEEF;
        --bs-info-border-subtle:var(--line);

        --bs-success:var(--green);
        --bs-success-rgb:22,163,74;
        --bs-success-text-emphasis:#11793A;
        --bs-success-bg-subtle:#E9F8EF;
        --bs-success-border-subtle:#BFE6CD;

        --bs-warning:var(--amber);
        --bs-warning-rgb:217,119,6;
        --bs-warning-text-emphasis:#7A5B00;
        --bs-warning-bg-subtle:#FFF7E6;
        --bs-warning-border-subtle:#F5D9A0;

        --bs-danger:var(--red-700);
        --bs-danger-rgb:200,26,30;
        --bs-danger-text-emphasis:#9E1518;
        --bs-danger-bg-subtle:var(--red-100);
        --bs-danger-border-subtle:#F5BDBE;

        --bs-secondary:var(--gray-600);
        --bs-secondary-rgb:119,120,122;
        --bs-secondary-text-emphasis:var(--gray-800);
        --bs-secondary-bg-subtle:#EEEEEF;
        --bs-secondary-border-subtle:var(--line);
      }

      html{ height:100%; }
      body{
        min-height:100vh;
        display:flex;
        flex-direction:column;
        -webkit-font-smoothing:antialiased;
      }

      /* ===== Header — the home portal's, with this portal's name ===== */
      .hr-header{
        position:relative;
        flex-shrink:0;
        background:linear-gradient(120deg, var(--gray-900) 0%, var(--gray-800) 55%, var(--gray-700) 100%);
        overflow:hidden;
        padding:18px clamp(20px, 4vw, 56px);
      }
      .hr-header::before{
        content:"";
        position:absolute; inset:0;
        background:
          radial-gradient(600px 240px at 85% -20%, rgba(236,32,36,.18), transparent 60%),
          radial-gradient(400px 200px at 15% 120%, rgba(236,32,36,.10), transparent 60%);
        pointer-events:none;
      }
      .hr-header-inner{
        position:relative;
        display:flex; align-items:center; justify-content:space-between; gap:24px;
        max-width:1440px; margin:0 auto;
      }
      .hr-brand{ display:flex; align-items:center; gap:18px; text-decoration:none; }
      .hr-brand img{ height:34px; width:auto; display:block; filter:brightness(0) invert(1); }
      .hr-brand .divider{ width:1px; height:32px; background:rgba(255,255,255,.25); }
      .hr-brand .t{ display:block; font-weight:700; font-size:clamp(17px, 2.2vw, 22px); letter-spacing:.4px; color:#fff; line-height:1.2; }
      .hr-brand .s{ display:block; font-size:12.5px; color:rgba(255,255,255,.6); margin-top:2px; }

      .hr-header-right{ display:flex; align-items:center; gap:16px; }
      .hr-user{ display:flex; align-items:center; gap:10px; color:#fff; }
      .hr-user .avatar{
        width:34px; height:34px; border-radius:50%;
        background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2);
        display:inline-flex; align-items:center; justify-content:center;
        font-size:13px; font-weight:700;
      }
      .hr-user .n{ display:block; font-size:13.5px; font-weight:600; line-height:1.2; }
      .hr-user .r{ display:block; font-size:11.5px; color:rgba(255,255,255,.6); }
      .hr-signout{
        background:rgba(255,255,255,.08);
        border:1px solid rgba(255,255,255,.16);
        color:rgba(255,255,255,.8);
        border-radius:10px;
        padding:8px 14px;
        font-family:var(--font-sans); font-size:12.5px; font-weight:600;
        cursor:pointer;
        transition:background .18s ease, color .18s ease;
      }
      .hr-signout:hover{ background:rgba(255,255,255,.16); color:#fff; }

      /* ===== Section nav ===== */
      .hr-nav{ background:#fff; border-bottom:1px solid var(--line); flex-shrink:0; }
      .hr-nav-inner{
        max-width:1440px; margin:0 auto;
        padding:0 clamp(20px, 4vw, 56px);
        display:flex; gap:28px;
        overflow-x:auto; scrollbar-width:none;
      }
      .hr-nav-inner::-webkit-scrollbar{ display:none; }
      .hr-nav a{
        display:inline-flex; align-items:center; gap:8px;
        padding:14px 2px 12px;
        font-size:13.5px; font-weight:600; white-space:nowrap;
        color:var(--ink-soft); text-decoration:none;
        border-bottom:2px solid transparent;
        transition:color .15s ease, border-color .15s ease;
      }
      .hr-nav a:hover{ color:var(--ink); }
      .hr-nav a.active{ color:var(--ink); border-bottom-color:var(--red-600); }
      .hr-nav a .bi{ font-size:15px; }
      .hr-nav a.active .bi{ color:var(--red-600); }

      /* ===== Main + footer ===== */
      .hr-main{
        flex:1;
        width:100%; max-width:1440px;
        margin:0 auto;
        padding:clamp(22px, 3.4vw, 38px) clamp(20px, 4vw, 56px) 60px;
      }
      .hr-footer{
        background:var(--gray-900);
        color:rgba(255,255,255,.5);
        text-align:center;
        padding:18px 20px;
        font-size:12.5px;
      }

      .section-label{
        font-size:12px; font-weight:600; letter-spacing:1.6px; text-transform:uppercase;
        color:var(--ink-soft);
        margin:0 0 14px 2px;
      }

      /* Page titles: one weight, and the icon always in the brand red — the
         pages used to colour it per section (blue, cyan, red). */
      .hr-main h4.fw-bold{ font-size:22px; letter-spacing:-.2px; }
      .hr-main h4 > .bi:first-child{ color:var(--red-600) !important; }

      .avatar-circle{
        border-radius:50%;
        background:var(--red-100); color:var(--red-600);
        display:inline-flex; align-items:center; justify-content:center;
        font-weight:700; flex-shrink:0;
      }

      /* ===== Sign-in / no-access (the `bare` pages) — as home.login ===== */
      .hr-sign{ max-width:520px; margin:6vh auto 0; text-align:center; }
      .hr-sign-icon{
        width:74px; height:74px; border-radius:20px;
        background:var(--red-100); color:var(--red-600);
        display:inline-flex; align-items:center; justify-content:center;
        font-size:34px; margin-bottom:20px;
      }
      .hr-sign h2{ font-size:24px; font-weight:700; margin-bottom:10px; }
      .hr-sign .copy{ color:var(--ink-soft); font-size:14.5px; line-height:1.6; margin-bottom:26px; }
      .hr-sign .who{ color:var(--ink-soft); font-size:13px; margin:-12px 0 22px; }
      .hr-sign .foot{ margin-top:28px; font-size:12px; color:var(--gray-500); line-height:1.6; }
      .hr-sign-error{
        background:var(--red-100); color:var(--red-700);
        border-radius:12px; padding:12px 16px;
        font-size:13.5px; text-align:start; margin-bottom:18px;
      }
      .hr-sign-btn{
        display:inline-flex; align-items:center; gap:10px;
        padding:13px 26px; font-size:14.5px;
      }

      /* ===== Bootstrap components ===== */
      .card{
        --bs-card-border-color:var(--line);
        --bs-card-border-radius:18px;
        --bs-card-inner-border-radius:17px;
        --bs-card-cap-bg:transparent;
        --bs-card-spacer-x:1.25rem;
        --bs-card-spacer-y:1.1rem;
        --bs-card-cap-padding-x:1.25rem;
        --bs-card-cap-padding-y:.9rem;
        border:1px solid var(--line);
        box-shadow:var(--shadow);
        overflow:hidden;
      }
      .card.border-0{ border:1px solid var(--line) !important; }
      .card.shadow-sm{ box-shadow:var(--shadow) !important; }
      /* A card that holds a type-ahead must let its dropdown out. */
      .card:has(.list-group.position-absolute){ overflow:visible; }
      .card-header{ font-weight:600; border-bottom-color:var(--line); }
      .card-header .bi:first-child{ color:var(--red-600); }
      .card-footer{ border-top-color:var(--line); }

      .btn{
        --bs-btn-font-weight:600;
        --bs-btn-border-radius:10px;
        font-family:var(--font-sans);
      }
      .btn-sm{ --bs-btn-border-radius:8px; }
      .btn-primary{
        --bs-btn-bg:var(--red-600);
        --bs-btn-border-color:var(--red-600);
        --bs-btn-hover-bg:var(--red-700);
        --bs-btn-hover-border-color:var(--red-700);
        --bs-btn-active-bg:var(--red-700);
        --bs-btn-active-border-color:var(--red-700);
        --bs-btn-disabled-bg:var(--red-600);
        --bs-btn-disabled-border-color:var(--red-600);
        --bs-btn-focus-shadow-rgb:236,32,36;
      }
      .btn-danger{
        --bs-btn-bg:var(--red-700);
        --bs-btn-border-color:var(--red-700);
        --bs-btn-hover-bg:#A8161A;
        --bs-btn-hover-border-color:#A8161A;
        --bs-btn-active-bg:#A8161A;
        --bs-btn-active-border-color:#A8161A;
        --bs-btn-disabled-bg:var(--red-700);
        --bs-btn-disabled-border-color:var(--red-700);
        --bs-btn-focus-shadow-rgb:200,26,30;
      }
      .btn-secondary{
        --bs-btn-bg:var(--gray-700);
        --bs-btn-border-color:var(--gray-700);
        --bs-btn-hover-bg:var(--gray-800);
        --bs-btn-hover-border-color:var(--gray-800);
        --bs-btn-active-bg:var(--gray-800);
        --bs-btn-active-border-color:var(--gray-800);
      }
      .btn-outline-secondary{
        --bs-btn-color:var(--ink);
        --bs-btn-bg:#fff;
        --bs-btn-border-color:var(--line);
        --bs-btn-hover-color:var(--ink);
        --bs-btn-hover-bg:#fff;
        --bs-btn-hover-border-color:var(--gray-500);
        --bs-btn-active-color:var(--ink);
        --bs-btn-active-bg:var(--bg);
        --bs-btn-active-border-color:var(--gray-500);
        --bs-btn-focus-shadow-rgb:154,155,157;
      }

      .form-control, .form-select{ border-color:var(--line); }
      .form-control:focus, .form-select:focus{
        border-color:var(--red-600);
        box-shadow:0 0 0 3px var(--red-100);
      }
      .form-check-input:checked{ background-color:var(--red-600); border-color:var(--red-600); }
      .form-check-input:focus{ border-color:var(--red-500); box-shadow:0 0 0 3px var(--red-100); }

      .table{
        --bs-table-color:var(--ink);
        --bs-table-border-color:var(--line);
        --bs-table-hover-bg:#FAFAFA;
        --bs-table-hover-color:var(--ink);
      }
      .table-light{
        --bs-table-color:var(--ink-soft);
        --bs-table-bg:#FAFAFA;
        --bs-table-border-color:var(--line);
      }
      .table > thead th{
        font-size:11px; font-weight:600; letter-spacing:1px; text-transform:uppercase;
        color:var(--ink-soft); white-space:nowrap;
      }
      /* Row titles read as text, turning red only under the pointer — a table of
         red links was most of the colour left on the page. */
      .table a{ color:var(--ink); }
      .table a:hover{ color:var(--red-600); }
      .table > :not(caption) > * > *{ padding:.75rem .9rem; }
      .table > :not(caption) > * > :first-child{ padding-left:1.25rem; }
      .table > :not(caption) > * > :last-child{ padding-right:1.25rem; }

      /* Badges become soft pills: the meaning stays, the shouting goes. */
      .badge{
        --bs-badge-font-weight:600;
        --bs-badge-border-radius:20px;
        --bs-badge-padding-x:.7em;
        --bs-badge-padding-y:.42em;
        letter-spacing:.2px;
      }
      .badge.bg-success{ background-color:var(--bs-success-bg-subtle) !important; color:var(--bs-success-text-emphasis) !important; }
      .badge.bg-warning{ background-color:var(--bs-warning-bg-subtle) !important; color:var(--bs-warning-text-emphasis) !important; }
      .badge.bg-danger{ background-color:var(--red-100) !important; color:var(--red-700) !important; }
      .badge.bg-info{ background-color:#EEEEEF !important; color:var(--gray-800) !important; }
      .badge.bg-secondary{ background-color:#EEEEEF !important; color:var(--ink-soft) !important; }
      .badge.bg-primary{ background-color:var(--gray-800) !important; color:#fff !important; }

      .alert{ --bs-alert-border-radius:14px; }

      .list-group{
        --bs-list-group-border-color:var(--line);
        --bs-list-group-border-radius:12px;
        --bs-list-group-action-hover-bg:#FAFAFA;
        --bs-list-group-action-active-bg:var(--bg);
      }

      .pagination{
        --bs-pagination-color:var(--ink);
        --bs-pagination-border-color:var(--line);
        --bs-pagination-hover-color:var(--red-700);
        --bs-pagination-hover-bg:#FAFAFA;
        --bs-pagination-hover-border-color:var(--line);
        --bs-pagination-focus-color:var(--red-700);
        --bs-pagination-focus-box-shadow:0 0 0 3px var(--red-100);
        --bs-pagination-active-bg:var(--red-600);
        --bs-pagination-active-border-color:var(--red-600);
      }

      /* ===== Entrance — the home portal's short rise =====
         `backwards`, never `both`: `forwards` would pin the final keyframe and
         outrank anything that later sets opacity or transform on these. */
      @keyframes hrRise{ from{ opacity:0; transform:translateY(12px); } to{ opacity:1; transform:none; } }
      .hr-main > *{ animation:hrRise .45s cubic-bezier(.2,.75,.28,1) backwards; }
      .hr-main > *:nth-child(2){ animation-delay:.05s; }
      .hr-main > *:nth-child(3){ animation-delay:.1s; }
      .hr-main > *:nth-child(n+4){ animation-delay:.14s; }

      @media (max-width:640px){
        .hr-brand .divider, .hr-brand .s{ display:none; }
        .hr-brand img{ height:28px; }
      }
      @media (prefers-reduced-motion:reduce){
        .hr-main > *{ animation:none !important; }
      }
    </style>
    @stack('head')
</head>
<body>

@php
    $__route = Route::currentRouteName() ?? '';
    // Sign-in and no-access pages render without the section nav.
    $__bare = trim($__env->yieldContent('bare')) !== '';
@endphp

<header class="hr-header">
    <div class="hr-header-inner">
        <a class="hr-brand" href="{{ auth()->check() ? route('portal.hr.index') : route('portal.hr.login') }}">
            <img src="{{ asset('images/brand/samir-logo.png') }}" alt="Samir Group">
            <span class="divider"></span>
            <span>
                <span class="t">HR PORTAL</span>
                <span class="s">Samir Group</span>
            </span>
        </a>

        @auth
            <div class="hr-header-right">
                <div class="hr-user d-none d-md-flex">
                    <span class="avatar">{{ strtoupper(mb_substr(auth()->user()->name ?? '?', 0, 1)) }}</span>
                    <span>
                        <span class="n">{{ auth()->user()->name }}</span>
                        <span class="r">{{ \App\Models\User::roleLabel(auth()->user()->role) }}</span>
                    </span>
                </div>
                <form method="POST" action="{{ route('portal.hr.logout') }}">
                    @csrf
                    <button type="submit" class="hr-signout">Sign out</button>
                </form>
            </div>
        @endauth
    </div>
</header>

{{-- Only portal.hr.* links belong here: EnforceHrPortalHostIsolation 404s
     everything else on this host, so a NOC link would be a dead end. --}}
@auth
    @unless($__bare)
        <nav class="hr-nav" aria-label="HR Portal sections">
            <div class="hr-nav-inner">
                @can('manage-hr-portal')
                    <a href="{{ route('portal.hr.index') }}" class="{{ $__route === 'portal.hr.index' ? 'active' : '' }}">
                        <i class="bi bi-grid"></i>Overview
                    </a>
                @endcan
                @can('submit-hr-onboarding')
                    <a href="{{ route('portal.hr.onboarding.index') }}" class="{{ str_starts_with($__route, 'portal.hr.onboarding') ? 'active' : '' }}">
                        <i class="bi bi-person-plus"></i>Onboarding
                    </a>
                @endcan
                @can('submit-hr-offboarding')
                    <a href="{{ route('portal.hr.offboarding.index') }}" class="{{ str_starts_with($__route, 'portal.hr.offboarding') ? 'active' : '' }}">
                        <i class="bi bi-person-dash"></i>Termination
                    </a>
                @endcan
                @can('submit-hr-employee-update')
                    <a href="{{ route('portal.hr.employee-update.index') }}" class="{{ str_starts_with($__route, 'portal.hr.employee-update') ? 'active' : '' }}">
                        <i class="bi bi-pencil-square"></i>Data Changes
                    </a>
                @endcan
                @can('manage-hr-portal')
                    <a href="{{ route('portal.hr.requests') }}" class="{{ str_starts_with($__route, 'portal.hr.requests') ? 'active' : '' }}">
                        <i class="bi bi-list-check"></i>My Requests
                    </a>
                @endcan
            </div>
        </nav>
    @endunless
@endauth

<main class="hr-main">
    @unless($__bare)
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <div class="fw-semibold mb-1">Please fix the following:</div>
                <ul class="mb-0 small">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
    @endunless

    @yield('content')
</main>

<footer class="hr-footer">
    Samir Group &copy; {{ now()->year }} — HR Portal · Internal use only
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
