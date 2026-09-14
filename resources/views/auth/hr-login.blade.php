@extends('layouts.hr')

@section('title', 'Sign in')
@section('bare', '1')

@section('content')
@php $settings = \App\Models\Setting::get(); @endphp

<div class="hr-sign">
    <div class="hr-sign-icon">
        <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="4" y="10" width="16" height="10.5" rx="2.4"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10" stroke-linecap="round"/><circle cx="12" cy="15.2" r="1.5"/></svg>
    </div>

    <h2>Sign in to the HR Portal</h2>

    @if (session('error'))
        <div class="hr-sign-error">{{ session('error') }}</div>
    @endif

    <p class="copy">
        Use your company Microsoft account to onboard new hires, raise terminations and request
        employee data changes.
    </p>

    @if ($settings->sso_enabled ?? false)
        <a href="{{ route('auth.microsoft') }}" class="btn btn-primary hr-sign-btn">
            <svg viewBox="0 0 23 23" width="18" height="18" aria-hidden="true">
                <rect x="0" y="0" width="10.5" height="10.5" fill="#F25022"/>
                <rect x="12.5" y="0" width="10.5" height="10.5" fill="#7FBA00"/>
                <rect x="0" y="12.5" width="10.5" height="10.5" fill="#00A4EF"/>
                <rect x="12.5" y="12.5" width="10.5" height="10.5" fill="#FFB900"/>
            </svg>
            Sign in with Microsoft
        </a>
    @else
        <div class="hr-sign-error">Single sign-on is not configured. Please contact IT.</div>
    @endif

    <p class="foot">Microsoft sign-in only — there is no separate password for this portal.</p>
</div>
@endsection
