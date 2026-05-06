@extends('layouts.portal')

@section('title', 'My Portal')

@section('content')
<style>
    .hub-hero {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        border-radius: 22px;
        padding: 32px 36px;
        margin-bottom: 28px;
        box-shadow: 0 12px 30px rgba(102, 126, 234, 0.25);
    }
    [data-bs-theme="dark"] .hub-hero {
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.4);
    }
    .hub-hero h2 { font-size: 26px; font-weight: 700; margin: 0 0 4px; }
    .hub-hero p  { margin: 0; opacity: .92; }
    .hub-hero .hero-avatar {
        width: 64px; height: 64px; border-radius: 50%;
        background: rgba(255,255,255,.18);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 24px; font-weight: 700;
        border: 3px solid rgba(255,255,255,.3);
    }

    .hub-tile {
        position: relative;
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 26px 22px 22px;
        border-radius: 18px;
        color: #fff;
        text-decoration: none;
        min-height: 190px;
        box-shadow: 0 6px 18px rgba(0,0,0,.10);
        transition: transform .2s ease, box-shadow .2s ease;
        overflow: hidden;
    }
    .hub-tile::after {
        content: "";
        position: absolute; inset: -30% -30% auto auto;
        width: 180px; height: 180px; border-radius: 50%;
        background: rgba(255,255,255,.10);
        transition: transform .3s ease;
    }
    .hub-tile:hover {
        transform: translateY(-5px);
        box-shadow: 0 16px 34px rgba(0,0,0,.18);
        color: #fff;
    }
    .hub-tile:hover::after { transform: scale(1.1); }
    .hub-tile .tile-icon  { font-size: 36px; z-index: 1; }
    .hub-tile .tile-title { font-size: 19px; font-weight: 700; margin: 0; z-index: 1; }
    .hub-tile .tile-desc  { font-size: 13px; opacity: .94; margin: 0; z-index: 1; line-height: 1.4; }
    .hub-tile .tile-badge {
        position: absolute; top: 14px; right: 14px;
        background: rgba(255,255,255,.22);
        border: 1px solid rgba(255,255,255,.35);
        border-radius: 100px;
        padding: 3px 10px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: .3px;
        z-index: 2;
        backdrop-filter: blur(4px);
    }
    .hub-tile .tile-badge.live { background: rgba(25, 223, 85, .35); border-color: rgba(255,255,255,.5); }

    .tile-browser { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .tile-profile { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
    .tile-assets  { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .tile-dir     { background: linear-gradient(135deg, #1e90ff 0%, #00b4db 100%); }
    .tile-print   { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
    .tile-printer { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
    .tile-docs    { background: linear-gradient(135deg, #ff9966 0%, #ff5e62 100%); }
    .tile-hr      { background: linear-gradient(135deg, #8e2de2 0%, #4a00e0 100%); }

    .pending-banner {
        border-left: 4px solid #ffc107;
        background: #fff9e6;
        color: #7a5b00;
        padding: 12px 16px;
        border-radius: 10px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    [data-bs-theme="dark"] .pending-banner {
        background: #2d2517; color: #ffd866;
    }
</style>

@php
    $displayName = auth()->user()->name ?? 'there';
    $firstName   = explode(' ', trim($displayName))[0] ?? $displayName;
    $initials    = strtoupper(substr($displayName, 0, 1));
    $hour        = (int) now()->format('G');
    $greeting    = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
@endphp

<div class="hub-hero d-flex flex-column flex-md-row align-items-md-center gap-3">
    <span class="hero-avatar">{{ $initials }}</span>
    <div>
        <h2>{{ $greeting }}, {{ $firstName }}</h2>
        <p>Welcome back. Everything you need is just a click away.</p>
    </div>
    <div class="ms-md-auto small">
        <div class="opacity-75">{{ now()->format('l, F j') }}</div>
        @if($employee && $employee->branch)
            <div><i class="bi bi-geo-alt me-1"></i>{{ $employee->branch->name }}</div>
        @endif
    </div>
</div>

@if($pendingEdit)
    <div class="pending-banner">
        <i class="bi bi-hourglass-split fs-5"></i>
        <div>
            <strong>Profile update pending review.</strong>
            Your request is with IT — you’ll see it reflected here once approved.
            <a href="{{ route('portal.profile') }}" class="ms-2">View request &rarr;</a>
        </div>
    </div>
@endif

<div class="row g-3">
    {{-- Remote Browser (visible only with permission) --}}
    @can('view-browser-portal')
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="{{ route('portal.browser') }}" class="hub-tile tile-browser">
            @if($activeBrowser)
                <span class="tile-badge live"><i class="bi bi-circle-fill" style="font-size:7px"></i> ACTIVE</span>
            @endif
            <i class="bi bi-globe2 tile-icon"></i>
            <h5 class="tile-title">Remote Browser</h5>
            <p class="tile-desc">
                @if($activeBrowser)
                    Session running — click to resume.
                @else
                    Hosted Chromium with access to internal company apps.
                @endif
            </p>
        </a>
    </div>
    @endcan

    {{-- My Profile --}}
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="{{ route('portal.profile') }}" class="hub-tile tile-profile">
            <i class="bi bi-person-badge-fill tile-icon"></i>
            <h5 class="tile-title">My Profile</h5>
            <p class="tile-desc">View your employee info and request phone updates.</p>
        </a>
    </div>

    {{-- My Assets --}}
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="{{ route('portal.assets') }}" class="hub-tile tile-assets">
            <i class="bi bi-box-seam-fill tile-icon"></i>
            <h5 class="tile-title">My Assets</h5>
            <p class="tile-desc">Devices, items, accessories and licenses in your name.</p>
        </a>
    </div>

    {{-- Browse Directory --}}
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="{{ route('public.contacts') }}" class="hub-tile tile-dir">
            <i class="bi bi-people-fill tile-icon"></i>
            <h5 class="tile-title">Browse Directory</h5>
            <p class="tile-desc">Search and view all employee contacts.</p>
        </a>
    </div>

    {{-- Print Directory --}}
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="{{ route('public.contacts.print') }}" target="_blank" class="hub-tile tile-print">
            <i class="bi bi-printer-fill tile-icon"></i>
            <h5 class="tile-title">Print Directory</h5>
            <p class="tile-desc">Print or save the full directory as PDF.</p>
        </a>
    </div>

    {{-- My Printers --}}
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="/admin/my-printers" class="hub-tile tile-printer">
            <i class="bi bi-printer tile-icon"></i>
            <h5 class="tile-title">My Printers</h5>
            <p class="tile-desc">Set up and connect printers for your office.</p>
        </a>
    </div>

    {{-- Documentation --}}
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="{{ route('public.documentation.index') }}" class="hub-tile tile-docs">
            <i class="bi bi-file-earmark-text-fill tile-icon"></i>
            <h5 class="tile-title">Documentation</h5>
            <p class="tile-desc">Reports, guides and technical documents.</p>
        </a>
    </div>

    {{-- HR Onboarding (visible only with permission) --}}
    @can('submit-hr-onboarding')
    <div class="col-12 col-sm-6 col-lg-4">
        <a href="{{ route('portal.hr.onboarding.index') }}" class="hub-tile tile-hr">
            <i class="bi bi-person-plus-fill tile-icon"></i>
            <h5 class="tile-title">HR Onboarding</h5>
            <p class="tile-desc">Submit a new hire for IT to provision accounts, extensions and licenses.</p>
        </a>
    </div>
    @endcan
</div>
@endsection
