<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSH — {{ $label }} | SG NOC</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.min.css">
    <script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-web-links@0.9.0/lib/xterm-addon-web-links.min.js"></script>

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; width: 100%; background: #0d0d0d; overflow: hidden; }

        #toolbar {
            display: flex; align-items: center; gap: .75rem;
            padding: .45rem 1rem; background: #1a1a1a;
            border-bottom: 1px solid #2a2a2a; height: 46px; flex-shrink: 0;
        }
        #toolbar .label { font-weight: 600; font-size: .875rem; color: #e0e0e0; }
        .status-badge {
            display: inline-flex; align-items: center; gap: .35rem;
            font-size: .75rem; padding: .2rem .55rem;
            border-radius: 999px; border: 1px solid transparent;
        }
        .status-dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }

        #wrapper { display: flex; flex-direction: column; height: 100vh; }
        #terminal-container { flex: 1; overflow: hidden; padding: 6px 4px 0; }
        .xterm { height: 100%; }

        /* Disconnected overlay */
        #overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,.75);
            display: flex; align-items: center; justify-content: center;
            z-index: 999; backdrop-filter: blur(4px);
        }
        #overlay.d-none { display: none !important; }
        #overlay-card {
            background: #1c1c1e; border-radius: 12px; padding: 2.5rem 2rem;
            text-align: center; max-width: 420px; width: 90%;
            box-shadow: 0 24px 64px rgba(0,0,0,.6);
        }
        #overlay-icon { font-size: 2.5rem; display: block; margin-bottom: .75rem; }
        #overlay-title { font-size: 1.25rem; font-weight: 700; margin-bottom: .5rem; }
        #overlay-msg { font-size: .9rem; color: #8e8e93; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
<div id="wrapper">

    <div id="toolbar">
        <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-sm btn-outline-secondary border-0 me-1">
            <i class="bi bi-arrow-left"></i>
        </a>

        <span class="label">
            <i class="bi bi-shield-lock-fill text-info me-1"></i>
            <span class="badge bg-info bg-opacity-20 text-info border border-info me-1" style="font-size:.65rem">SSH</span>
            {{ $label }}
        </span>

        <span id="status-badge" class="status-badge bg-warning bg-opacity-10 text-warning">
            <span class="status-dot" style="background:#ffc107"></span>
            <span id="status-text">Connecting…</span>
        </span>

        <div class="ms-auto d-flex gap-2 align-items-center">
            <button class="btn btn-sm btn-outline-secondary border-0" id="btn-copy-all" title="Copy all output">
                <i class="bi bi-clipboard"></i> Copy All
            </button>
            <button class="btn btn-sm btn-outline-secondary border-0" id="btn-clear" title="Clear screen">
                <i class="bi bi-eraser-fill"></i>
            </button>
            <button class="btn btn-sm btn-outline-warning border-0 d-none" id="btn-reconnect">
                <i class="bi bi-arrow-repeat"></i> Reconnect
            </button>
            <button class="btn btn-sm btn-outline-danger border-0" id="btn-disconnect">
                <i class="bi bi-x-circle"></i> Disconnect
            </button>
        </div>
    </div>

    <div id="terminal-container"></div>
</div>

{{-- Disconnected overlay --}}
<div id="overlay" class="d-none">
    <div id="overlay-card">
        <span id="overlay-icon">🔌</span>
        <div id="overlay-title">Disconnected</div>
        <div id="overlay-msg">The session was closed.</div>
        <div class="d-flex gap-2 justify-content-center">
            <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <button id="overlay-reconnect" class="btn btn-success btn-sm d-none">
                <i class="bi bi-arrow-repeat me-1"></i>Reconnect
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    const WS_URL    = @json($wsUrl);
    const DEVICE_ID = @json($device->id);
    const SESSION_ID= @json($session->id);
    const CSRF      = document.querySelector('meta[name=csrf-token]').content;
    const DISC_URL  = @json(route('admin.devices.ssh.disconnect', [$device, $session]));

    // ── xterm.js setup ──────────────────────────────────────────────────
    const term     = new Terminal({ cursorBlink: true, fontSize: 14, theme: { background: '#0d0d0d' }, convertEol: true });
    const fitAddon = new FitAddon.FitAddon();
    const linksAddon = new WebLinksAddon.WebLinksAddon();
    term.loadAddon(fitAddon);
    term.loadAddon(linksAddon);
    term.open(document.getElementById('terminal-container'));
    setTimeout(() => fitAddon.fit(), 50);
    window.addEventListener('resize', () => fitAddon.fit());

    const btnReconn = document.getElementById('btn-reconnect');
    let ws          = null;
    let sessionEnded = false;

    // ── WebSocket ───────────────────────────────────────────────────────
    function connect() {
        if (ws) { try { ws.close(); } catch (_) {} }
        ws = new WebSocket(WS_URL);
        ws.binaryType = 'arraybuffer';

        ws.onopen = () => {
            setStatus('connected');
            btnReconn.classList.add('d-none');
            hideOverlay();
        };

        ws.onmessage = (e) => {
            if (typeof e.data === 'string') {
                try {
                    const msg = JSON.parse(e.data);
                    if (msg.type === 'status')       { setStatus('connecting', msg.message); return; }
                    if (msg.type === 'connected')    { setStatus('connected', msg.message); return; }
                    if (msg.type === 'error')        { showOverlay('❌', 'Connection Error', msg.message, false); setStatus('error'); return; }
                    if (msg.type === 'disconnected') { endSession(); showOverlay('🔌', 'Disconnected', msg.message, true); return; }
                } catch (_) {}
                term.write(e.data);
            } else {
                term.write(new Uint8Array(e.data));
            }
        };

        ws.onclose = (e) => {
            if (e.code !== 1000) {
                showOverlay('🔌', 'Disconnected', 'The connection was lost unexpectedly.', true);
            }
            setStatus('disconnected');
            btnReconn.classList.remove('d-none');
            endSession();
        };

        ws.onerror = () => setStatus('error');

        // Resize
        term.onResize(({ cols, rows }) => {
            if (ws && ws.readyState === WebSocket.OPEN)
                ws.send(JSON.stringify({ type: 'resize', cols, rows }));
        });

        // Input
        term.onData((data) => {
            if (ws && ws.readyState === WebSocket.OPEN) ws.send(data);
        });
    }

    // ── Notify Laravel that the session ended ───────────────────────────
    function endSession() {
        if (sessionEnded) return;
        sessionEnded = true;
        fetch(DISC_URL, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            keepalive: true,
        }).catch(() => {});
    }
    window.addEventListener('beforeunload', endSession);

    // ── Status badge ────────────────────────────────────────────────────
    function setStatus(state, msg) {
        const badge = document.getElementById('status-badge');
        const dot   = badge.querySelector('.status-dot');
        const text  = document.getElementById('status-text');
        const map   = {
            connecting:   ['#ffc107', 'bg-warning', 'Connecting…'],
            connected:    ['#28a745', 'bg-success',  msg || 'Connected'],
            disconnected: ['#6c757d', 'bg-secondary', 'Disconnected'],
            error:        ['#dc3545', 'bg-danger',    msg || 'Error'],
        };
        const [colour, cls, label] = map[state] || map.connecting;
        dot.style.background = colour;
        badge.className = `status-badge ${cls} bg-opacity-10 text-${cls.replace('bg-','')}`;
        text.textContent = label;
    }

    // ── Overlay ─────────────────────────────────────────────────────────
    function showOverlay(icon, title, msg, showReconnect) {
        document.getElementById('overlay-icon').textContent  = icon;
        document.getElementById('overlay-title').textContent = title;
        document.getElementById('overlay-msg').textContent   = msg;
        document.getElementById('overlay-reconnect').classList.toggle('d-none', !showReconnect);
        document.getElementById('overlay').classList.remove('d-none');
    }
    function hideOverlay() {
        document.getElementById('overlay').classList.add('d-none');
    }

    // ── Copy All ────────────────────────────────────────────────────────
    document.getElementById('btn-copy-all').addEventListener('click', () => {
        const btn   = document.getElementById('btn-copy-all');
        const buf   = term.buffer.active;
        const lines = [];
        for (let i = 0; i < buf.length; i++) {
            const l = buf.getLine(i);
            if (l) lines.push(l.translateToString(true));
        }
        while (lines.length && !lines[lines.length - 1].trim()) lines.pop();
        navigator.clipboard.writeText(lines.join('\n')).then(() => {
            btn.innerHTML = '<i class="bi bi-clipboard-check"></i> Copied!';
            btn.classList.replace('btn-outline-secondary', 'btn-outline-success');
            setTimeout(() => {
                btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy All';
                btn.classList.replace('btn-outline-success', 'btn-outline-secondary');
            }, 2000);
        });
    });

    // ── Toolbar buttons ─────────────────────────────────────────────────
    document.getElementById('btn-clear').addEventListener('click', () => term.clear());
    document.getElementById('btn-disconnect').addEventListener('click', () => {
        if (ws) ws.close(1000, 'User disconnected');
    });
    btnReconn.addEventListener('click', connect);
    document.getElementById('overlay-reconnect').addEventListener('click', () => {
        hideOverlay();
        sessionEnded = false;
        connect();
    });

    connect();
    setTimeout(() => fitAddon.fit(), 50);
})();
</script>
</body>
</html>
