@extends('layouts.portal')

@section('title', 'Remote Browser')

@section('content')
<div class="container py-3">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('portal.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Portal
            </a>
            <div>
                <h3 class="mb-0"><i class="bi bi-globe2 me-2 text-primary"></i>Remote Browser</h3>
                <small class="text-muted">Hosted Chromium, streamed to you over WebRTC.</small>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('portal.history') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-clock-history me-1"></i>History
            </a>
            @can('manage-browser-portal')
                <a href="{{ route('admin.browser-portal.index') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-shield-lock me-1"></i>Admin view
                </a>
            @endcan
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-4">
            @if ($active)
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="badge bg-success bg-opacity-25 text-success border border-success px-3 py-2">
                        <i class="bi bi-circle-fill me-1" style="font-size:7px"></i> ACTIVE
                    </span>
                    <div>
                        <h5 class="card-title mb-0">Your session is running</h5>
                        <small class="text-muted">
                            Session ID: <code>{{ $active->session_id }}</code>
                            &middot; started {{ $active->created_at->diffForHumans() }}
                        </small>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="{{ route('portal.show', $active->session_id) }}" class="btn btn-primary">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open browser
                    </a>
                    <form method="POST" action="{{ route('portal.destroy', $active->session_id) }}">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger" type="submit"
                                onclick="return confirm('Stop the session? Your browser profile (cookies, bookmarks) will be kept for next time.')">
                            <i class="bi bi-stop-circle me-1"></i>Stop
                        </button>
                    </form>
                </div>
            @else
                <h5 class="card-title mb-2">Launch a remote browser</h5>
                <p class="text-muted mb-3">
                    Hosted Chromium on the company VPS, streamed over WebRTC. Internal web apps
                    are reachable from inside it.
                </p>
                <form method="POST" action="{{ route('portal.store') }}">
                    @csrf
                    <button class="btn btn-primary btn-lg" type="submit">
                        <i class="bi bi-play-circle me-1"></i>Launch browser
                    </button>
                </form>
                <p class="text-muted small mt-3 mb-0">
                    Cookies, bookmarks, and saved passwords persist across sessions.
                    Idle sessions stop automatically after 4 hours.
                </p>
            @endif
        </div>
    </div>
</div>
@endsection
