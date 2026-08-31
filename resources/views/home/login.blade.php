@extends('layouts.home')

@section('title', 'Sign in | Samir Group Employee Portal')

@section('content')
@php
    // True when Entra declined a silent sign-in — this browser has no usable
    // Windows session (personal device, private window, not Entra-joined).
    $silentDeclined = $silentDeclined ?? false;
@endphp

<div style="max-width:520px;margin:6vh auto 0;text-align:center;">

    <div style="width:74px;height:74px;border-radius:20px;background:var(--red-100);color:var(--red-600);
                display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;">
        <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="4" y="10" width="16" height="10.5" rx="2.4"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10" stroke-linecap="round"/><circle cx="12" cy="15.2" r="1.5"/></svg>
    </div>

    <h2 style="font-size:24px;font-weight:700;margin-bottom:10px;">Employee Portal</h2>

    @if(session('error'))
        <div style="background:var(--red-100);color:var(--red-700);border-radius:12px;
                    padding:12px 16px;font-size:13.5px;text-align:left;margin-bottom:18px;">
            {{ session('error') }}
        </div>
    @endif

    <p style="color:var(--ink-soft);font-size:14.5px;line-height:1.6;margin-bottom:26px;">
        @if($silentDeclined)
            We could not sign you in automatically from your Windows account on this device.
            Sign in with your work account to continue.
        @else
            Sign in with your Samir Group work account to continue.
        @endif
    </p>

    <a href="{{ route('home.sign-in') }}" class="btn btn-primary"
       style="display:inline-flex;align-items:center;gap:10px;padding:13px 26px;font-size:14.5px;text-decoration:none;">
        <svg viewBox="0 0 23 23" width="18" height="18" aria-hidden="true">
            <rect x="0" y="0" width="10.5" height="10.5" fill="#F25022"/>
            <rect x="12.5" y="0" width="10.5" height="10.5" fill="#7FBA00"/>
            <rect x="0" y="12.5" width="10.5" height="10.5" fill="#00A4EF"/>
            <rect x="12.5" y="12.5" width="10.5" height="10.5" fill="#FFB900"/>
        </svg>
        Sign in with Microsoft
    </a>

    <p style="margin-top:28px;font-size:12px;color:var(--gray-500);line-height:1.6;">
        On a company computer this page normally signs you in by itself, using the
        account you logged into Windows with.
    </p>
</div>
@endsection
