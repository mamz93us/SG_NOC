<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Web Browser — SG NOC</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; background: #0d1117; color: #e6edf3;
                     font-family: system-ui, sans-serif; overflow: hidden; }

        /* ── Toolbar ── */
        #toolbar {
            display: flex; align-items: center; gap: .35rem;
            height: 50px; padding: 0 .6rem;
            background: #161b22; border-bottom: 1px solid #30363d; flex-shrink: 0;
        }

        .btn-icon {
            width: 30px; height: 30px; display: inline-flex; align-items: center;
            justify-content: center; border-radius: 6px; border: none;
            background: transparent; color: #8b949e; cursor: pointer;
            font-size: .9rem; flex-shrink: 0; text-decoration: none;
        }
        .btn-icon:hover:not(:disabled) { background: #21262d; color: #e6edf3; }
        .btn-icon:disabled { opacity: .3; cursor: default; }

        /* ── Protocol selector ── */
        #scheme-wrap {
            display: flex; align-items: center; gap: 0;
            background: #0d1117; border: 1px solid #30363d;
            border-radius: 8px 0 0 8px; padding: 0 .5rem;
            height: 34px; flex-shrink: 0; border-right: none;
        }
        #scheme-select {
            background: transparent; border: none; color: #8b949e;
            font-size: .78rem; outline: none; cursor: pointer; padding: 0;
            appearance: none; -webkit-appearance: none;
        }
        #scheme-select option { background: #161b22; color: #e6edf3; }

        /* ── Address input ── */
        #url-input {
            flex: 1; height: 34px; min-width: 0;
            background: #0d1117; border: 1px solid #30363d;
            border-left: none; border-right: none;
            color: #e6edf3; font-size: .82rem; padding: 0 .5rem;
            font-family: monospace; outline: none;
        }
        #url-input:focus { background: #0d1117; }
        #toolbar:focus-within #scheme-wrap,
        #toolbar:focus-within #url-input,
        #toolbar:focus-within #port-wrap,
        #toolbar:focus-within #go-btn { border-color: #388bfd; }

        /* ── Port selector ── */
        #port-wrap {
            display: flex; align-items: center;
            background: #0d1117; border: 1px solid #30363d;
            border-left: none; border-right: none;
            height: 34px; padding: 0 .4rem; flex-shrink: 0; gap: .2rem;
        }
        #port-wrap span { color: #484f58; font-size: .75rem; }
        #port-input {
            background: transparent; border: none; color: #8b949e;
            font-size: .78rem; outline: none; width: 52px;
            font-family: monospace;
        }

        /* ── Go button ── */
        #go-btn {
            height: 34px; padding: 0 .9rem;
            border-radius: 0 8px 8px 0; border: 1px solid #30363d; border-left: none;
            background: #1f6feb; color: #fff; font-size: .8rem; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: .3rem;
            white-space: nowrap; flex-shrink: 0;
        }
        #go-btn:hover { background: #388bfd; }

        /* ── Info strip ── */
        #infobar {
            height: 24px; background: #0d1117; border-bottom: 1px solid #21262d;
            display: flex; align-items: center; gap: .5rem;
            padding: 0 .8rem; font-size: .7rem; color: #484f58; flex-shrink: 0;
        }
        #infobar .live-url { color: #388bfd; font-family: monospace;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .spinner { display: none; width: 10px; height: 10px; border: 2px solid #388bfd;
            border-top-color: transparent; border-radius: 50%;
            animation: spin .5s linear infinite; flex-shrink: 0; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Frame area ── */
        #frame-area {
            position: relative;
            height: calc(100vh - 74px); /* 50px toolbar + 24px infobar */
        }
        #browser-frame { width: 100%; height: 100%; border: none; background: #fff; display: block; }

        /* ── Empty state overlay ── */
        #empty-state {
            position: absolute; inset: 0;
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: .9rem; color: #484f58;
            background: #0d1117; pointer-events: none;
        }
        #empty-state.hidden { display: none; }
        #empty-state i { font-size: 3rem; }
        #empty-state p { font-size: .88rem; }
        #suggestions { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: center;
            max-width: 520px; pointer-events: all; }
        .chip { background: #161b22; border: 1px solid #30363d; border-radius: 20px;
            padding: .2rem .65rem; font-size: .75rem; color: #8b949e; cursor: pointer;
            font-family: monospace; }
        .chip:hover { border-color: #388bfd; color: #58a6ff; }
        #security-note { font-size: .72rem; color: #30363d; }
    </style>
</head>
<body>

<div id="toolbar">
    {{-- Back --}}
    <a href="{{ route('admin.telnet.index') }}" class="btn-icon" title="Back">
        <i class="bi bi-arrow-left"></i>
    </a>
    <button class="btn-icon" id="btn-back"    title="Back"    onclick="goBack()"><i class="bi bi-chevron-left"></i></button>
    <button class="btn-icon" id="btn-forward" title="Forward" onclick="goForward()"><i class="bi bi-chevron-right"></i></button>
    <button class="btn-icon" id="btn-reload"  title="Reload"  onclick="reload()"><i class="bi bi-arrow-clockwise"></i></button>

    {{-- Address bar group --}}
    <div id="scheme-wrap">
        <select id="scheme-select" title="Protocol">
            <option value="http">http://</option>
            <option value="https">https://</option>
        </select>
    </div>

    <input type="text" id="url-input"
           placeholder="10.1.0.100  or  192.168.1.1/page"
           autocomplete="off" spellcheck="false"
           onkeydown="if(event.key==='Enter') go()">

    <div id="port-wrap">
        <span>:</span>
        <input type="number" id="port-input" value="80" min="1" max="65535"
               title="Port" onkeydown="if(event.key==='Enter') go()">
    </div>

    <button id="go-btn" onclick="go()">
        <i class="bi bi-arrow-right-circle-fill"></i> Go
    </button>

    {{-- Direct open --}}
    <button class="btn-icon" id="btn-direct" title="Open original in new tab"
            onclick="openDirect()" style="display:none">
        <i class="bi bi-box-arrow-up-right"></i>
    </button>
</div>

<div id="infobar">
    <div class="spinner" id="spinner"></div>
    <span style="flex-shrink:0">Proxied via SG NOC &rarr;</span>
    <span class="live-url" id="live-url">—</span>
</div>

<div id="frame-area">
    <iframe id="browser-frame"
            name="sg-noc-browser"
            sandbox="allow-forms allow-scripts allow-same-origin allow-popups allow-top-navigation-by-user-activation"
            title="SG NOC Browser"></iframe>

    <div id="empty-state">
        <i class="bi bi-globe2"></i>
        <p>Enter an address above and press <strong>Go</strong></p>
        <div id="suggestions">
            <span class="chip" onclick="prefill('http','192.168.1.1','80')">192.168.1.1</span>
            <span class="chip" onclick="prefill('http','10.0.0.1','80')">10.0.0.1</span>
            <span class="chip" onclick="prefill('http','172.16.0.1','80')">172.16.0.1</span>
            <span class="chip" onclick="prefill('https','192.168.1.1','443')">192.168.1.1 (HTTPS)</span>
            <span class="chip" onclick="prefill('http','10.1.0.100','8080')">10.1.0.100:8080</span>
        </div>
        <p id="security-note">
            <i class="bi bi-shield-check me-1" style="color:#3fb950"></i>
            All requests are made by the NOC server — your browser never connects directly.
        </p>
    </div>
</div>

<script>
(function () {
    const FETCH_URL  = @json(route('admin.browser.fetch'));
    const frame      = document.getElementById('browser-frame');
    const urlInput   = document.getElementById('url-input');
    const portInput  = document.getElementById('port-input');
    const schemeEl   = document.getElementById('scheme-select');
    const emptyState = document.getElementById('empty-state');
    const spinner    = document.getElementById('spinner');
    const liveUrl    = document.getElementById('live-url');
    const btnDirect  = document.getElementById('btn-direct');

    let currentTarget = '';

    // ── Build full URL from parts ─────────────────────────────────────────
    function buildUrl() {
        const scheme = schemeEl.value;
        let   host   = urlInput.value.trim();
        const port   = portInput.value.trim();

        // Strip any scheme the user typed in the host box
        host = host.replace(/^https?:\/\//i, '');

        // Strip path from host (keep it for appending)
        const slashIdx = host.indexOf('/');
        const path     = slashIdx >= 0 ? host.slice(slashIdx) : '/';
        const hostname = slashIdx >= 0 ? host.slice(0, slashIdx) : host;

        if (!hostname) return null;

        // Omit default ports
        const defaultPort = scheme === 'https' ? '443' : '80';
        const portStr     = port && port !== defaultPort ? ':' + port : '';

        return `${scheme}://${hostname}${portStr}${path}`;
    }

    // ── Navigate ──────────────────────────────────────────────────────────
    function go() {
        const url = buildUrl();
        if (!url) { urlInput.focus(); return; }
        loadUrl(url);
    }
    window.go = go;

    function loadUrl(url) {
        currentTarget = url;
        liveUrl.textContent = url;
        spinner.style.display = 'block';
        emptyState.classList.add('hidden');
        btnDirect.style.display = '';

        frame.src = FETCH_URL + '?url=' + encodeURIComponent(url);

        // Update address bar from parsed URL
        try {
            const p = new URL(url);
            schemeEl.value   = p.protocol.replace(':', '');
            urlInput.value   = p.hostname + (p.pathname !== '/' ? p.pathname : '');
            portInput.value  = p.port || (p.protocol === 'https:' ? '443' : '80');
        } catch (_) {}
    }

    function prefill(scheme, host, port) {
        schemeEl.value  = scheme;
        urlInput.value  = host;
        portInput.value = port;
        go();
    }
    window.prefill = prefill;

    // ── Frame load/error ──────────────────────────────────────────────────
    frame.addEventListener('load', () => {
        spinner.style.display = 'none';

        // Try reading proxied URL from frame's location
        try {
            const loc    = frame.contentWindow?.location?.href || '';
            const params = new URL(loc).searchParams;
            const real   = params.get('url');
            if (real) {
                currentTarget = real;
                liveUrl.textContent = real;
                const p = new URL(real);
                schemeEl.value  = p.protocol.replace(':', '');
                urlInput.value  = p.hostname + (p.pathname !== '/' ? p.pathname + p.search : '');
                portInput.value = p.port || (p.protocol === 'https:' ? '443' : '80');
            }
        } catch (_) {}
    });

    // ── Sync port default when scheme changes ─────────────────────────────
    schemeEl.addEventListener('change', () => {
        const cur = portInput.value;
        if (schemeEl.value === 'https' && cur === '80')  portInput.value = '443';
        if (schemeEl.value === 'http'  && cur === '443') portInput.value = '80';
    });

    // ── Controls ──────────────────────────────────────────────────────────
    function goBack()    { try { frame.contentWindow.history.back(); }    catch(_){} }
    function goForward() { try { frame.contentWindow.history.forward(); } catch(_){} }
    function reload()    { if (frame.src) frame.src = frame.src; }
    function openDirect() { if (currentTarget) window.open(currentTarget, '_blank'); }
    window.goBack = goBack; window.goForward = goForward;
    window.reload = reload; window.openDirect = openDirect;

    // ── Pre-load URL passed from device page ──────────────────────────────
    @if($url)
    (function() {
        const raw = @json($url);
        try {
            const p = new URL(raw);
            schemeEl.value  = p.protocol.replace(':', '');
            urlInput.value  = p.hostname + (p.pathname && p.pathname !== '/' ? p.pathname : '');
            portInput.value = p.port || (p.protocol === 'https:' ? '443' : '80');
        } catch(_) {
            urlInput.value = raw;
        }
        go();
    })();
    @endif
})();
</script>
</body>
</html>
