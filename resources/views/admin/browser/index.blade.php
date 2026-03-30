<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Browser — SG NOC</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; background: #0d1117; color: #e6edf3; font-family: system-ui, sans-serif; overflow: hidden; }

        /* ── Toolbar ── */
        #toolbar {
            display: flex; align-items: center; gap: .4rem;
            height: 48px; padding: 0 .6rem;
            background: #161b22; border-bottom: 1px solid #30363d;
            flex-shrink: 0;
        }

        .btn-tool {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border: 1px solid transparent;
            border-radius: 6px; background: transparent; color: #8b949e;
            cursor: pointer; font-size: .95rem; flex-shrink: 0;
            text-decoration: none;
        }
        .btn-tool:hover { background: #21262d; color: #e6edf3; }
        .btn-tool:disabled { opacity: .35; cursor: default; }

        /* ── Address bar ── */
        #address-form { flex: 1; display: flex; align-items: center; gap: .4rem; }
        #url-input {
            flex: 1; height: 34px;
            background: #0d1117; border: 1px solid #30363d; border-radius: 20px;
            color: #e6edf3; font-size: .85rem; padding: 0 1rem;
            font-family: monospace; outline: none; transition: border-color .15s;
        }
        #url-input:focus { border-color: #388bfd; background: #0d1117; }

        #go-btn {
            height: 34px; padding: 0 .9rem; border-radius: 20px;
            background: #1f6feb; border: none; color: #fff;
            font-size: .8rem; font-weight: 600; cursor: pointer;
            display: inline-flex; align-items: center; gap: .3rem;
            white-space: nowrap;
        }
        #go-btn:hover { background: #388bfd; }

        /* ── Status/tag strip ── */
        #infobar {
            height: 26px; background: #0d1117;
            border-bottom: 1px solid #21262d;
            display: flex; align-items: center; gap: .5rem;
            padding: 0 .8rem; font-size: .72rem; color: #484f58;
        }
        #infobar .proxied-url { color: #388bfd; font-family: monospace; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap; max-width: 60vw; }
        #loading-indicator { display: none; width: 10px; height: 10px;
            border: 2px solid #388bfd; border-top-color: transparent;
            border-radius: 50%; animation: spin .6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Frame ── */
        #browser-frame {
            width: 100%; border: none; background: #fff;
            height: calc(100vh - 74px); /* toolbar 48px + infobar 26px */
            display: block;
        }

        /* ── Empty state ── */
        #empty-state {
            position: absolute; inset: 74px 0 0 0;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 1rem; color: #484f58;
        }
        #empty-state i { font-size: 3.5rem; }
        #empty-state p { font-size: .95rem; }

        /* ── Quick suggestions ── */
        #suggestions {
            display: flex; flex-wrap: wrap; gap: .5rem;
            justify-content: center; max-width: 600px;
        }
        .suggestion-chip {
            background: #161b22; border: 1px solid #30363d;
            border-radius: 20px; padding: .25rem .75rem;
            font-size: .78rem; color: #8b949e; cursor: pointer;
            font-family: monospace; text-decoration: none;
        }
        .suggestion-chip:hover { border-color: #388bfd; color: #58a6ff; }
    </style>
</head>
<body>

{{-- ── Toolbar ── --}}
<div id="toolbar">
    {{-- Back to NOC --}}
    <a href="{{ route('admin.telnet.index') }}" class="btn-tool" title="Back to NOC">
        <i class="bi bi-arrow-left"></i>
    </a>

    {{-- Back / Forward / Reload (iframe history) --}}
    <button class="btn-tool" id="btn-back"    title="Back"    onclick="frameNav(-1)"><i class="bi bi-chevron-left"></i></button>
    <button class="btn-tool" id="btn-forward" title="Forward" onclick="frameNav(1)"><i class="bi bi-chevron-right"></i></button>
    <button class="btn-tool" id="btn-reload"  title="Reload"  onclick="reloadFrame()"><i class="bi bi-arrow-clockwise"></i></button>

    {{-- Address bar --}}
    <form id="address-form" onsubmit="navigate(event)">
        <i class="bi bi-lock-fill" id="scheme-icon" style="color:#484f58;font-size:.8rem;flex-shrink:0"></i>
        <input type="text" id="url-input"
               placeholder="Enter URL — e.g. http://10.1.0.1/ or https://192.168.1.1/"
               value="{{ $url }}"
               autocomplete="off" spellcheck="false">
        <button type="submit" id="go-btn">
            <i class="bi bi-arrow-right-circle-fill"></i> Go
        </button>
    </form>

    {{-- Open original in new tab --}}
    <button class="btn-tool" id="btn-open-direct" title="Open original URL in new tab" onclick="openDirect()" style="display:none">
        <i class="bi bi-box-arrow-up-right"></i>
    </button>
</div>

{{-- ── Info bar ── --}}
<div id="infobar">
    <div id="loading-indicator"></div>
    <span style="flex-shrink:0">Proxied via SG NOC →</span>
    <span class="proxied-url" id="current-url-display">—</span>
</div>

{{-- ── Frame ── --}}
<iframe id="browser-frame"
        name="sg-noc-browser"
        sandbox="allow-forms allow-scripts allow-same-origin allow-popups"
        title="SG NOC Web Browser"></iframe>

{{-- ── Empty state (shown when no URL loaded) ── --}}
<div id="empty-state" id="empty">
    <i class="bi bi-globe2"></i>
    <p>Enter a URL above to browse through the NOC server</p>
    <div id="suggestions">
        <span class="suggestion-chip" onclick="loadSuggestion(this)">http://192.168.1.1/</span>
        <span class="suggestion-chip" onclick="loadSuggestion(this)">http://10.0.0.1/</span>
        <span class="suggestion-chip" onclick="loadSuggestion(this)">http://172.16.0.1/</span>
        <span class="suggestion-chip" onclick="loadSuggestion(this)">https://192.168.0.1/</span>
    </div>
    <p style="font-size:.78rem;color:#30363d;margin-top:.5rem">
        <i class="bi bi-shield-check me-1 text-success"></i>
        All requests are made by the NOC server — your browser never connects directly.
    </p>
</div>

<script>
(function () {
    const FETCH_BASE = @json(route('admin.browser.fetch'));
    const frame      = document.getElementById('browser-frame');
    const input      = document.getElementById('url-input');
    const emptyState = document.getElementById('empty-state');
    const loadingEl  = document.getElementById('loading-indicator');
    const urlDisplay = document.getElementById('current-url-display');
    const btnDirect  = document.getElementById('btn-open-direct');
    const schemeIcon = document.getElementById('scheme-icon');

    let currentUrl = '';

    // ── Navigate ──────────────────────────────────────────────────────────
    function navigate(e) {
        if (e) e.preventDefault();
        let url = input.value.trim();
        if (!url) return;

        // Auto-prepend http:// if no scheme
        if (!/^https?:\/\//i.test(url)) url = 'http://' + url;

        input.value = url;
        loadUrl(url);
    }

    function loadUrl(url) {
        currentUrl = url;
        const proxied = FETCH_BASE + '?url=' + encodeURIComponent(url);

        emptyState.style.display = 'none';
        loadingEl.style.display  = 'block';

        frame.src = proxied;

        urlDisplay.textContent = url;
        btnDirect.style.display = '';
        updateSchemeIcon(url);
    }

    function loadSuggestion(el) {
        input.value = el.textContent.trim();
        navigate(null);
    }
    window.loadSuggestion = loadSuggestion;

    // ── Frame events ──────────────────────────────────────────────────────
    frame.addEventListener('load', () => {
        loadingEl.style.display = 'none';

        // Try to read the frame's current proxied URL to update the address bar
        try {
            const loc = frame.contentWindow?.location?.href;
            if (loc && loc !== 'about:blank') {
                const params = new URL(loc).searchParams;
                const realUrl = params.get('url');
                if (realUrl) {
                    currentUrl = realUrl;
                    input.value = realUrl;
                    urlDisplay.textContent = realUrl;
                    updateSchemeIcon(realUrl);
                }
            }
        } catch (_) {}
    });

    // ── Address bar: pressing Escape restores previous URL ────────────────
    input.addEventListener('focus', () => input.select());
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { input.value = currentUrl; input.blur(); }
    });

    // ── Scheme icon colouring ─────────────────────────────────────────────
    function updateSchemeIcon(url) {
        if (/^https:\/\//i.test(url)) {
            schemeIcon.className = 'bi bi-lock-fill';
            schemeIcon.style.color = '#3fb950';
        } else {
            schemeIcon.className = 'bi bi-unlock-fill';
            schemeIcon.style.color = '#e3b341';
        }
    }

    // ── Navigation helpers ────────────────────────────────────────────────
    function frameNav(dir) {
        try {
            if (dir === -1) frame.contentWindow.history.back();
            else            frame.contentWindow.history.forward();
        } catch (_) {}
    }
    function reloadFrame() {
        if (frame.src && frame.src !== window.location.href) {
            frame.src = frame.src;
        }
    }
    window.frameNav    = frameNav;
    window.reloadFrame = reloadFrame;

    function openDirect() {
        if (currentUrl) window.open(currentUrl, '_blank');
    }
    window.openDirect = openDirect;

    // ── Auto-load if URL passed from device page ──────────────────────────
    const initialUrl = input.value.trim();
    if (initialUrl) {
        loadUrl(initialUrl.startsWith('http') ? initialUrl : 'http://' + initialUrl);
    }
})();
</script>
</body>
</html>
