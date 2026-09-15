<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Document Archive') · Samir Group</title>
    <link rel="icon" href="{{ asset('images/brand/samir-mark.png') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    {{--
        Same visual system as the HR and home portals (layouts/hr, layouts/home):
        Samir greys, one brand red, white cards on an off-white ground, Bootstrap
        re-themed onto that palette rather than replaced.

        Light only, like the other portals. This is a reading tool — people sit
        in it looking at scanned paper, so the chrome stays quiet and the
        document gets the contrast.
    --}}
    <style>
      :root{
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
        --font-sans:'Montserrat','Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,sans-serif;

        --bs-body-font-family:var(--font-sans);
        --bs-body-font-size:.9rem;
        --bs-body-color:var(--ink);
        --bs-body-bg:var(--bg);
        --bs-primary:var(--red-600);
        --bs-link-color:var(--red-700);
        --bs-link-hover-color:var(--red-600);
      }
      body{background:var(--bg);color:var(--ink);font-family:var(--font-sans);}
      .arc-bar{background:var(--card);border-bottom:1px solid var(--line);}
      .arc-bar .brand{font-weight:700;letter-spacing:.2px;color:var(--ink);text-decoration:none;}
      .arc-bar .brand .dot{color:var(--red-600);}
      .arc-card{background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);}
      .arc-muted{color:var(--ink-soft);}
      .btn-brand{background:var(--red-600);border-color:var(--red-600);color:#fff;}
      .btn-brand:hover{background:var(--red-700);border-color:var(--red-700);color:#fff;}
      .badge-soft{background:var(--red-100);color:var(--red-700);font-weight:600;}
      .arc-table th{font-size:.74rem;text-transform:uppercase;letter-spacing:.4px;color:var(--ink-soft);font-weight:700;}
      .arc-table td{vertical-align:middle;}
      a{color:var(--red-700);}
      .arc-viewer{width:100%;height:78vh;border:1px solid var(--line);border-radius:10px;background:#fff;}
      .arc-empty{padding:3rem 1rem;text-align:center;color:var(--ink-soft);}
    </style>
    @stack('head')
</head>
<body>

<nav class="arc-bar py-2 mb-4">
    <div class="container-fluid px-4 d-flex align-items-center gap-3">
        <a class="brand" href="{{ route('archive.index') }}">Document Archive<span class="dot">.</span></a>

        @auth
            <div class="ms-auto d-flex align-items-center gap-3">
                @can('manage-archive-portal')
                    <a href="{{ route('archive.manage.index') }}" class="text-decoration-none arc-muted small">
                        <i class="bi bi-sliders"></i> Manage
                    </a>
                @endcan
                <span class="arc-muted small d-none d-md-inline">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('archive.logout') }}" class="m-0">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary" type="submit">Sign out</button>
                </form>
            </div>
        @endauth
    </div>
</nav>

<main class="container-fluid px-4 pb-5">
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @yield('content')
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
