@extends('layouts.archive')

@section('title', 'Sign in')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="arc-card p-4 mt-5 text-center">
                <h1 class="h5 mb-1">Sign in</h1>
                <p class="arc-muted small mb-4">Use your Samir Group work account.</p>

                {{--
                    Microsoft only. There is no password form here at all — this
                    host has no local sign-in, and 2FA is skipped on it (see
                    RequireTwoFactor), which is only defensible because the host
                    serves nothing but the archive and every document is gated by
                    per-archive membership on each request.
                --}}
                <a href="{{ route('auth.microsoft') }}" class="btn btn-brand w-100 py-2">
                    <i class="bi bi-microsoft me-1"></i> Sign in with Microsoft
                </a>

                <p class="arc-muted small mt-4 mb-0">
                    Use your work account. Access to each archive is granted separately.
                </p>
            </div>
        </div>
    </div>
@endsection
