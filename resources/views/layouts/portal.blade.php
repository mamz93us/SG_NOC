<!DOCTYPE html>
<html lang="en" data-bs-theme="{{ Auth::check() && Auth::user()->dark_mode ? 'dark' : 'light' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'My Portal')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        [data-bs-theme="dark"] body { background: #1a1d21; }
        [data-bs-theme="dark"] .card { background-color: #212529; border-color: #373b3e; }
        .avatar-circle {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0;
        }
    </style>
    @stack('head')
</head>
<body>

@php $__settings = \App\Models\Setting::get(); @endphp
<nav class="navbar navbar-dark bg-dark shadow-sm">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold py-1" href="{{ route('portal.index') }}">
            @if($__settings->company_logo ?? false)
                <img src="{{ \Illuminate\Support\Facades\Storage::url($__settings->company_logo) }}"
                     alt="Logo" style="height:34px;width:auto;object-fit:contain;">
            @else
                <span class="avatar-circle" style="background:linear-gradient(135deg,#1a56db,#6c47ff);font-size:15px;">SG</span>
            @endif
            <span class="d-none d-sm-inline">My Portal</span>
        </a>

        @auth
            <div class="dropdown">
                <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2"
                        data-bs-toggle="dropdown">
                    <span class="avatar-circle" style="width:28px;height:28px;font-size:12px;">
                        {{ strtoupper(substr(auth()->user()->name ?? '?', 0, 1)) }}
                    </span>
                    <span class="d-none d-sm-inline">{{ auth()->user()->name }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li class="px-3 py-2 small">
                        <div class="fw-semibold">{{ auth()->user()->name }}</div>
                        <div class="text-muted">{{ auth()->user()->email }}</div>
                        <span class="badge bg-secondary mt-1">
                            {{ \App\Models\User::roleLabel(auth()->user()->role ?? 'browser_user') }}
                        </span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="{{ route('portal.index') }}">
                            <i class="bi bi-grid-1x2 me-2"></i>My Portal
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="{{ route('portal.profile') }}">
                            <i class="bi bi-person-badge me-2"></i>My Profile
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="{{ route('portal.browser') }}">
                            <i class="bi bi-globe2 me-2"></i>Remote Browser
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="{{ route('portal.history') }}">
                            <i class="bi bi-clock-history me-2"></i>Browser History
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('portal.logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger">
                                <i class="bi bi-box-arrow-right me-2"></i>Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        @endauth
    </div>
</nav>

<div class="container-fluid px-3 px-lg-4 mt-3 mb-5">
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

    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
