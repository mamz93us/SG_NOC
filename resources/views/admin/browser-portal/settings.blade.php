@extends('layouts.admin')

@section('title', 'Remote Browser — Settings')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-gear me-2"></i>Remote Browser — Settings</h3>
        <a href="{{ route('admin.browser-portal.admin.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="card shadow-sm" method="POST" action="{{ route('admin.browser-portal.admin.settings.update') }}">
        @csrf
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Idle timeout (minutes)</label>
                    <input type="number" name="idle_minutes" class="form-control" min="5" max="1440"
                           value="{{ old('idle_minutes', $settings->idle_minutes) }}" required>
                    <small class="text-muted">Session is auto-stopped after this many minutes of no heartbeat.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Max concurrent sessions</label>
                    <input type="number" name="max_concurrent_sessions" class="form-control" min="1" max="100"
                           value="{{ old('max_concurrent_sessions', $settings->max_concurrent_sessions) }}" required>
                    <small class="text-muted">Also bounded by the UDP port range ÷ ports-per-session.</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Desktop resolution</label>
                    <input type="text" name="desktop_resolution" class="form-control"
                           value="{{ old('desktop_resolution', $settings->desktop_resolution) }}" required>
                    <small class="text-muted">e.g. <code>1920x1080@30</code></small>
                </div>

                <div class="col-md-4">
                    <label class="form-label">UDP port range start</label>
                    <input type="number" name="udp_port_range_start" class="form-control" min="1024" max="65535"
                           value="{{ old('udp_port_range_start', $settings->udp_port_range_start) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">UDP port range end</label>
                    <input type="number" name="udp_port_range_end" class="form-control" min="1024" max="65535"
                           value="{{ old('udp_port_range_end', $settings->udp_port_range_end) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ports per session</label>
                    <input type="number" name="ports_per_session" class="form-control" min="1" max="100"
                           value="{{ old('ports_per_session', $settings->ports_per_session) }}" required>
                    <small class="text-muted">Reserved WebRTC UDP ports per session. Must also be open in UFW on the VPS.</small>
                </div>

                <div class="col-md-12">
                    <label class="form-label">Neko image</label>
                    <input type="text" name="neko_image" class="form-control"
                           value="{{ old('neko_image', $settings->neko_image) }}" required>
                    <small class="text-muted">Image pulled before <code>docker run</code>. Changing doesn't auto-restart running sessions.</small>
                </div>

                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="auto_request_control" name="auto_request_control" value="1"
                               @checked(old('auto_request_control', $settings->auto_request_control))>
                        <label class="form-check-label" for="auto_request_control">
                            Auto-request control on session open
                        </label>
                    </div>
                    <small class="text-muted">When on, the viewer page tries to auto-grab keyboard/mouse control.</small>
                </div>
                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="hide_neko_branding" name="hide_neko_branding" value="1"
                               @checked(old('hide_neko_branding', $settings->hide_neko_branding))>
                        <label class="form-check-label" for="hide_neko_branding">
                            Hide Neko branding inside the iframe
                        </label>
                    </div>
                    <small class="text-muted">Injects CSS into the iframe to hide the n.eko header / logo.</small>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2 me-1"></i>Save settings
            </button>
        </div>
    </form>

    <p class="small text-muted mt-3 mb-0">
        Settings are cached for 60&nbsp;s; saving invalidates the cache so next launch uses the new values.
        Running containers are unaffected until they're stopped and relaunched.
    </p>
</div>
@endsection
