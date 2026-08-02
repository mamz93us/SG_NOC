<!DOCTYPE html>
<html lang="en" data-bs-theme="{{ Auth::check() && Auth::user()->dark_mode ? 'dark' : 'light' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'HR Portal')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        [data-bs-theme="dark"] body { background: #1a1d21; }
        [data-bs-theme="dark"] .card { background-color: #212529; border-color: #373b3e; }
        .avatar-circle {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #8e2de2, #4a00e0);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0;
        }
        .navbar-hr { background: linear-gradient(135deg, #4a00e0 0%, #8e2de2 100%); }
        .navbar-hr .nav-link { color: rgba(255,255,255,.82); font-weight: 500; }
        .navbar-hr .nav-link:hover,
        .navbar-hr .nav-link.active { color: #fff; }
        .navbar-hr .nav-link.active { border-bottom: 2px solid #fff; }
    </style>
    @stack('head')
</head>
<body>

@php
    $__settings = \App\Models\Setting::get();
    $__route    = Route::currentRouteName() ?? '';
@endphp

{{-- Only portal.hr.* links belong here: EnforceHrPortalHostIsolation 404s
     everything else on this host, so a NOC link would be a dead end. --}}
<nav class="navbar navbar-expand-lg navbar-dark navbar-hr shadow-sm">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-bold py-1" href="{{ route('portal.hr.index') }}">
            @if($__settings->company_logo ?? false)
                <img src="{{ \Illuminate\Support\Facades\Storage::url($__settings->company_logo) }}"
                     alt="Logo" style="height:34px;width:auto;object-fit:contain;">
            @else
                <span class="avatar-circle" style="font-size:15px;">HR</span>
            @endif
            <span class="d-none d-sm-inline">HR Portal</span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#hrNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="hrNav">
            <ul class="navbar-nav me-auto">
                @can('submit-hr-onboarding')
                <li class="nav-item">
                    <a class="nav-link {{ str_starts_with($__route, 'portal.hr.onboarding') ? 'active' : '' }}"
                       href="{{ route('portal.hr.onboarding.index') }}">
                        <i class="bi bi-person-plus me-1"></i>Onboarding
                    </a>
                </li>
                @endcan
                @can('submit-hr-offboarding')
                <li class="nav-item">
                    <a class="nav-link {{ str_starts_with($__route, 'portal.hr.offboarding') ? 'active' : '' }}"
                       href="{{ route('portal.hr.offboarding.index') }}">
                        <i class="bi bi-person-dash me-1"></i>Termination
                    </a>
                </li>
                @endcan
                @can('submit-hr-employee-update')
                <li class="nav-item">
                    <a class="nav-link {{ str_starts_with($__route, 'portal.hr.employee-update') ? 'active' : '' }}"
                       href="{{ route('portal.hr.employee-update.index') }}">
                        <i class="bi bi-pencil-square me-1"></i>Data Changes
                    </a>
                </li>
                @endcan
                @can('manage-hr-portal')
                <li class="nav-item">
                    <a class="nav-link {{ $__route === 'portal.hr.requests' ? 'active' : '' }}"
                       href="{{ route('portal.hr.requests') }}">
                        <i class="bi bi-list-check me-1"></i>All Requests
                    </a>
                </li>
                @endcan
            </ul>

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
                            <form method="POST" action="{{ route('portal.hr.logout') }}">
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

    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
