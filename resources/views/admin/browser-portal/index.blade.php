@extends('layouts.portal')

@section('title', 'My Portal')

@section('content')
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0"><i class="bi bi-grid-1x2-fill me-2 text-primary"></i>My Portal</h3>
            <small class="text-muted">Everything you need, in one place.</small>
        </div>
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

    <style>
        .portal-tile {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 26px 22px;
            border-radius: 18px;
            color: #fff;
            text-decoration: none;
            min-height: 170px;
            box-shadow: 0 6px 16px rgba(0,0,0,.08);
            transition: transform .2s, box-shadow .2s;
        }
        .portal-tile:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(0,0,0,.16);
            color: #fff;
        }
        .portal-tile .tile-icon  { font-size: 34px; }
        .portal-tile .tile-title { font-size: 20px; font-weight: 700; margin: 0; }
        .portal-tile .tile-desc  { font-size: 13px; opacity: .92; margin: 0; }
        .tile-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .tile-success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .tile-info    { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .tile-pink    { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .tile-orange  { background: linear-gradient(135deg, #ff9966 0%, #ff5e62 100%); }
    </style>

    {{-- ═══ Remote Browser (primary — has real state: active / not active) ═══ --}}
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white border-0 d-flex align-items-center">
            <i class="bi bi-globe2 me-2 text-primary"></i>
            <strong>Remote Browser</strong>
        </div>
        <div class="card-body">
            @if ($active)
                <h5 class="card-title mb-1">Your session is running</h5>
                <p class="text-muted mb-3">
                    Session ID: <code>{{ $active->session_id }}</code>
                    &middot; started {{ $active->created_at->diffForHumans() }}
                </p>
                <div class="d-flex gap-2">
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
                <h5 class="card-title mb-1">Launch a remote browser</h5>
                <p class="text-muted mb-3">
                    Hosted Chromium on the company VPS, streamed over WebRTC. Internal web apps
                    are reachable from inside it.
                </p>
                <form method="POST" action="{{ route('portal.store') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">
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

    {{-- ═══ Quick links — everything that used to live on the public home ═══ --}}
    <div class="row g-3">
        <div class="col-12 col-md-6 col-lg-4">
            <a href="{{ route('public.contacts') }}" class="portal-tile tile-primary">
                <i class="bi bi-people-fill tile-icon"></i>
                <h5 class="tile-title">Browse Directory</h5>
                <p class="tile-desc">Search and view all employee contacts.</p>
            </a>
        </div>

        <div class="col-12 col-md-6 col-lg-4">
            <a href="{{ route('public.contacts.print') }}" target="_blank" class="portal-tile tile-success">
                <i class="bi bi-printer-fill tile-icon"></i>
                <h5 class="tile-title">Print Directory</h5>
                <p class="tile-desc">Print or save the full directory as PDF.</p>
            </a>
        </div>

        <div class="col-12 col-md-6 col-lg-4">
            <a href="/admin/my-printers" class="portal-tile tile-info">
                <i class="bi bi-printer tile-icon"></i>
                <h5 class="tile-title">My Printers</h5>
                <p class="tile-desc">Set up and connect printers for your office.</p>
            </a>
        </div>

        <div class="col-12 col-md-6 col-lg-4">
            <a href="{{ route('public.documentation.index') }}" class="portal-tile tile-pink">
                <i class="bi bi-file-earmark-text-fill tile-icon"></i>
                <h5 class="tile-title">Documentation</h5>
                <p class="tile-desc">Reports, guides and technical documents.</p>
            </a>
        </div>
    </div>
</div>
@endsection
