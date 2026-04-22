@extends('layouts.portal')

@section('title', 'Remote Browser — Session')

@section('content')
{{-- Inline styles: the admin layout has @stack('scripts') but not @stack('head'),
     so @push('head') would be silently dropped. --}}
<style>
    body.browser-portal-session { overflow: hidden !important; }
    body.browser-portal-session main,
    body.browser-portal-session .container,
    body.browser-portal-session .container-fluid,
    body.browser-portal-session .py-4 { padding: 0 !important; margin: 0 !important; max-width: none !important; }
    /* Lift the top navbar above the iframe so its dropdown menu (which opens
       below the 56px navbar, into the iframe's fixed overlay zone) isn't
       hidden. Bootstrap's .dropdown-menu is z-index 1000 by default — we
       bump both navbar + menu above the iframe's 1020. */
    body.browser-portal-session .navbar { position: relative; z-index: 1040; }
    body.browser-portal-session .dropdown-menu.show { z-index: 1050 !important; }
    .bp-frame-wrap {
        position: fixed;
        inset: 56px 0 0 0;       /* leave the existing top navbar visible */
        background: #000;
        z-index: 1020;
    }
    .bp-frame-wrap iframe {
        display: block;
        width: 100%; height: 100%; border: 0;
    }
    /* Toolbar lives top-LEFT so it doesn't overlap Neko's fullscreen / settings
       icons which sit top-right. Slight fade unless hovered so the remote
       browser has as much unobstructed room as possible. */
    .bp-toolbar {
        position: absolute; top: 8px; left: 8px; z-index: 1030;
        display: flex; gap: 8px;
        opacity: .35; transition: opacity .15s;
    }
    .bp-toolbar:hover { opacity: 1; }
</style>
<div class="bp-frame-wrap">
    <div class="bp-toolbar">
        <a href="{{ route('portal.index') }}" class="btn btn-sm btn-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <form method="POST" action="{{ route('portal.destroy', $session->session_id) }}">
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-danger" type="submit"
                    onclick="return confirm('Stop this session?')">
                <i class="bi bi-stop-circle me-1"></i>Stop
            </button>
        </form>
    </div>

    {{-- Query string:
          - usr=   display name passed to Neko's multiuser provider. REQUIRED
                   alongside pwd= to auto-connect — without it the "YOU HAVE
                   BEEN INVITED TO THIS ROOM" prompt appears at every session.
          - pwd=   auto-login to Neko's multiuser provider (decrypted server-side).
          - embed=1  hides Neko's "n.eko" header + control bar, leaves only the viewport.
     --}}
    @php
        $displayName = auth()->user()?->name ?: ('user-' . $session->session_id);
        $qs = http_build_query(array_filter([
            'usr'   => $displayName,
            'pwd'   => $nekoPassword,
            'embed' => 1,
        ]));
    @endphp
    <iframe src="/s/{{ $session->session_id }}/{{ $qs ? '?' . $qs : '' }}"
            allow="autoplay; clipboard-read; clipboard-write; fullscreen; microphone; camera; display-capture"
            referrerpolicy="same-origin"></iframe>
</div>

<script>
(function () {
    document.body.classList.add('browser-portal-session');

    // Belt-and-suspenders branding removal. The iframe is same-origin
    // (both served under noc.samirgroup.net), so we can inject CSS to
    // hide Neko's header/logo/menu even if ?embed=1 isn't honored by
    // the container image's Neko build.
    const iframe = document.querySelector('.bp-frame-wrap iframe');
    function stripNekoChrome() {
        try {
            const doc = iframe.contentDocument;
            if (!doc) return;
            if (doc.getElementById('bp-chrome-hide')) return;
            const s = doc.createElement('style');
            s.id = 'bp-chrome-hide';
            s.textContent = `
                header, .neko-header, .header, [class*="header"],
                .menu, [class*="top-bar"], [class*="topbar"],
                .logo, [class*="logo"], [class*="brand"],
                .controls-top, .controls-header,
                .menu-bar, .side-bar, .sidebar, .aside { display: none !important; }
                body, #app, .neko, .video, .player, main {
                    margin: 0 !important; padding: 0 !important;
                    height: 100% !important; width: 100% !important;
                    background: #000 !important;
                }
                video { background: #000 !important; }
            `;
            (doc.head || doc.documentElement).appendChild(s);
        } catch (_) { /* cross-origin at some point? leave alone */ }
    }
    if (iframe) {
        iframe.addEventListener('load', stripNekoChrome);
        // Also periodically re-apply in case Neko's SPA re-renders and removes our style node
        setInterval(stripNekoChrome, 2000);
    }

    // Auto-request control so the user doesn't have to click the lock icon after
    // every reconnect. Neko's multiuser provider grants the first requester and
    // then other viewers request/release as they please. Same-origin iframe so
    // we can reach into the Vue app's store or fall back to clicking the button.
    let controlRequested = false;
    function autoRequestControl() {
        if (controlRequested || !iframe) return;
        try {
            const win = iframe.contentWindow;
            const doc = iframe.contentDocument;
            if (!win || !doc) return;

            // 1. Preferred: Neko's injected API (v2: window.$client / window.$neko).
            const api = win.$client || win.$neko || win.neko;
            if (api?.control?.request) {
                api.control.request();
                controlRequested = true;
                return;
            }
            if (api?.sendMessage) {            // older builds
                api.sendMessage('control/request');
                controlRequested = true;
                return;
            }

            // 2. Fallback: click the lock/unlock button if visible.
            //    Neko's toggle uses an `aria-label` or an icon — match broadly.
            const btn = doc.querySelector(
                '[aria-label*="control" i], [title*="control" i], ' +
                '[aria-label*="lock" i], [title*="lock" i], ' +
                '.control-button, .toggle-control'
            );
            if (btn) { btn.click(); controlRequested = true; }
        } catch (_) { /* ignore, next interval will retry */ }
    }
    if (iframe) {
        iframe.addEventListener('load', () => setTimeout(autoRequestControl, 1500));
        // Retry for ~20s in case Neko's WebSocket connect is slow.
        const controlTimer = setInterval(() => {
            autoRequestControl();
            if (controlRequested) clearInterval(controlTimer);
        }, 1500);
        setTimeout(() => clearInterval(controlTimer), 20_000);
    }

    // 60s heartbeat so the 4h idle cutoff only fires when the tab is really idle.
    const url = @json(route('portal.heartbeat'));
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
