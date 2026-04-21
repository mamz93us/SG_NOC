@extends('layouts.admin')

@section('title', 'Remote Browser — Session')

@push('head')
<style>
    body.browser-portal-session { overflow: hidden; }
    .bp-frame-wrap {
        position: fixed;
        inset: 56px 0 0 0; /* leave the existing top navbar visible */
        background: #000;
    }
    .bp-frame-wrap iframe {
        width: 100%; height: 100%; border: 0;
    }
    .bp-toolbar {
        position: absolute; top: 8px; right: 8px; z-index: 5;
        display: flex; gap: 8px;
    }
</style>
@endpush

@section('content')
<div class="bp-frame-wrap">
    <div class="bp-toolbar">
        <a href="{{ route('admin.browser-portal.index') }}" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <form method="POST" action="{{ route('admin.browser-portal.destroy', $session->session_id) }}">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-danger" type="submit"
                    onclick="return confirm('Stop this session?')">
                <i class="bi bi-stop-circle me-1"></i>Stop
            </button>
        </form>
    </div>

    <iframe src="/s/{{ $session->session_id }}/"
            allow="autoplay; clipboard-read; clipboard-write; fullscreen; microphone; camera; display-capture"
            referrerpolicy="same-origin"></iframe>
</div>

<script>
(function () {
    document.body.classList.add('browser-portal-session');

    // 60s heartbeat so the 4h idle cutoff only fires when the tab is really idle.
    const url = @json(route('admin.browser-portal.heartbeat'));
    const sid = @json($session->session_id);
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    async function beat() {
        if (document.hidden) return;
        try {
            await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token || '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ session_id: sid }),
                credentials: 'same-origin',
            });
        } catch (_) { /* swallow: next beat will retry */ }
    }
    beat();
    setInterval(beat, 60_000);
})();
</script>
@endsection
