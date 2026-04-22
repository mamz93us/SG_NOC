@extends('layouts.portal')

@section('title', 'Remote Browser')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-globe2 me-2"></i>Remote Browser</h3>
        @can('manage-browser-portal')
            <a href="{{ route('admin.browser-portal.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-shield-lock me-1"></i>Admin view
            </a>
        @endcan
    </div>

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            @if ($active)
                <h5 class="card-title">Your session is running</h5>
                <p class="text-muted mb-3">
                    Session ID: <code>{{ $active->session_id }}</code>
                    &middot; started {{ $active->created_at->diffForHumans() }}
                </p>
                <div class="d-flex gap-2">
                    <a href="{{ route('portal.show', $active->session_id) }}" class="btn btn-primary">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open browser
                    </a>
                    <form method="POST" action="{{ route('portal.destroy', $active->session_id) }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-outline-danger" type="submit"
                                onclick="return confirm('Stop the session? Your browser profile (cookies, bookmarks) will be kept for next time.')">
                            <i class="bi bi-stop-circle me-1"></i>Stop
                        </button>
                    </form>
                </div>
            @else
                <h5 class="card-title">Launch a remote browser</h5>
                <p class="text-muted">
                    You'll get a hosted Chromium running on the company VPS, streamed to you over WebRTC.
                    It's on the corporate network, so internal web apps are reachable.
                </p>
                <form method="POST" action="{{ route('portal.store') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-play-circle me-1"></i>Launch browser
                    </button>
                </form>
                <p class="text-muted small mt-3 mb-0">
                    Tip: your cookies, bookmarks, and saved passwords are preserved across sessions.
                    Idle sessions are automatically stopped after 4 hours.
                </p>
            @endif
        </div>
    </div>
</div>
@endsection
