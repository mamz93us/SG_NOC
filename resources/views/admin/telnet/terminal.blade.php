<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $label }} — Telnet | SG NOC</title>

    {{-- Bootstrap --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    {{-- xterm.js --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.min.css">
    <script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-web-links@0.9.0/lib/xterm-addon-web-links.min.js"></script>

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            height: 100%; width: 100%;
            background: #0d0d0d;
            overflow: hidden;
        }

        /* ── Top toolbar ── */
        #toolbar {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .45rem 1rem;
            background: #1a1a1a;
            border-bottom: 1px solid #2a2a2a;
            height: 46px;
            flex-shrink: 0;
        }

        #toolbar .label {
            font-weight: 600;
            font-size: .875rem;
            color: #e0e0e0;
        }

        #toolbar .status-badge {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            font-size: .75rem;
            padding: .2rem .55rem;
            border-radius: 999px;
            font-weight: 600;
            letter-spacing: .03em;
        }

        #toolbar .status-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* ── Terminal wrapper ── */
        #wrapper {
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        #terminal-container {
            flex: 1;
            padding: .5rem;
            overflow: hidden;
        }

        #terminal-container .xterm {
            height: 100%;
        }

        /* ── Overlay (disconnected / error) ── */
        #overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.75);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        #overlay.hidden { display: none; }

        #overlay-card {
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 12px;
            padding: 2.5rem 2rem;
            max-width: 420px;
            width: 90%;
            text-align: center;
        }
    </style>
</head>
<body>

<div id="wrapper">

    {{-- ── Toolbar ── --}}
    <div id="toolbar">
        <a href="{{ route('admin.telnet.index') }}" class="btn btn-sm btn-outline-secondary border-0 me-1"
           title="Back to device list">
            <i class="bi bi-arrow-left"></i>
        </a>

        <span class="label">
            @if($protocol === 'ssh')
                <i class="bi bi-shield-lock-fill text-info me-1"></i>
                <span class="badge bg-info bg-opacity-20 text-info border border-info me-1" style="font-size:.65rem">SSH</span>
            @else
                <i class="bi bi-terminal-fill text-success me-1"></i>
                <span class="badge bg-success bg-opacity-20 text-success border border-success me-1" style="font-size:.65rem">TELNET</span>
            @endif
            {{ $label }}
        </span>

        <span id="status-badge" class="status-badge bg-warning bg-opacity-10 text-warning">
            <span class="status-dot" style="background:#ffc107"></span>
            <span id="status-text">Connecting…</span>
        </span>

        <div class="ms-auto d-flex gap-2 align-items-center">
            {{-- Copy All --}}
            <button class="btn btn-sm btn-outline-secondary border-0" id="btn-copy-all" title="Copy all terminal output">
                <i class="bi bi-clipboard"></i> Copy All
            </button>
            {{-- Clear screen --}}
            <button class="btn btn-sm btn-outline-secondary border-0" id="btn-clear" title="Clear screen">
                <i class="bi bi-eraser-fill"></i>
            </button>
            {{-- Reconnect --}}
            <button class="btn btn-sm btn-outline-warning border-0 d-none" id="btn-reconnect" title="Reconnect">
                <i class="bi bi-arrow-repeat"></i> Reconnect
            </button>
            {{-- Disconnect --}}
            <button class="btn btn-sm btn-outline-danger border-0" id="btn-disconnect" title="Disconnect">
                <i class="bi bi-x-circle"></i> Disconnect
            </button>
        </div>
    </div>

    {{-- ── xterm.js mount point ── --}}
    <div id="terminal-container"></div>

</div>

{{-- ── Disconnected overlay ── --}}
<div id="overlay" class="hidden">
    <div id="overlay-card">
        <div id="overlay-icon" class="mb-3" style="font-size:2.5rem">🔌</div>
        <h5 id="overlay-title" class="mb-2">Disconnected</h5>
        <p id="overlay-msg" class="text-muted small mb-4"></p>
        <div class="d-flex gap-2 justify-content-center">
            <a href="{{ route('admin.telnet.index') }}" class="btn btn-outline-secondary btn-sm">
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
    'use strict';

    const WS_URL = @json($wsUrl);

    // ── Terminal init ──────────────────────────────────────────────────────
    const term = new Terminal({
        fontFamily: "'Cascadia Code', 'Fira Code', 'Consolas', monospace",
        fontSize:   14,
        lineHeight: 1.2,
        cursorBlink: true,
        cursorStyle: 'block',
        theme: {
            background:   '#0d0d0d',
            foreground:   '#e0e0e0',
            cursor:       '#22c55e',
            black:        '#1a1a1a',
            red:          '#f87171',
            green:        '#4ade80',
            yellow:       '#facc15',
            blue:         '#60a5fa',
            magenta:      '#c084fc',
            cyan:         '#22d3ee',
            white:        '#e5e7eb',
            brightBlack:  '#4b5563',
            brightRed:    '#fca5a5',
            brightGreen:  '#86efac',
            brightYellow: '#fde047',
            brightBlue:   '#93c5fd',
            brightMagenta:'#d8b4fe',
            brightCyan:   '#67e8f9',
            brightWhite:  '#f9fafb',
        },
        scrollback: 5000,
        convertEol: true,
    });

    const fitAddon      = new FitAddon.FitAddon();
    const webLinksAddon = new WebLinksAddon.WebLinksAddon();
    term.loadAddon(fitAddon);
    term.loadAddon(webLinksAddon);
    term.open(document.getElementById('terminal-container'));
    fitAddon.fit();

    window.addEventListener('resize', () => fitAddon.fit());

    // ── UI helpers ─────────────────────────────────────────────────────────
    const statusBadge = document.getElementById('status-badge');
    const statusText  = document.getElementById('status-text');
    const overlay     = document.getElementById('overlay');
    const overlayMsg  = document.getElementById('overlay-msg');
    const overlayTitle= document.getElementById('overlay-title');
    const overlayIcon = document.getElementById('overlay-icon');
    const btnReconn   = document.getElementById('btn-reconnect');

    function setStatus(state) {
        const cfg = {
            connecting: { cls: 'text-warning bg-warning', dot: '#ffc107', text: 'Connecting…' },
            connected:  { cls: 'text-success bg-success', dot: '#22c55e', text: 'Connected'   },
            error:      { cls: 'text-danger  bg-danger',  dot: '#ef4444', text: 'Error'        },
            closed:     { cls: 'text-secondary bg-secondary', dot: '#6b7280', text: 'Disconnected' },
        }[state] || {};

        statusBadge.className = `status-badge bg-opacity-10 ${cfg.cls}`;
        statusBadge.querySelector('.status-dot').style.background = cfg.dot;
        statusText.textContent = cfg.text;
    }

    function showOverlay(icon, title, msg, showReconnect = false) {
        overlayIcon.textContent  = icon;
        overlayTitle.textContent = title;
        overlayMsg.textContent   = msg;
        overlay.classList.remove('hidden');
        document.getElementById('overlay-reconnect').classList.toggle('d-none', !showReconnect);
    }

    // ── WebSocket connection ───────────────────────────────────────────────
    let ws;
    let overlayShownByControl = false; // prevent ws.onclose from overwriting a control-set overlay

    function connect() {
        overlay.classList.add('hidden');
        btnReconn.classList.add('d-none');
        overlayShownByControl = false;
        setStatus('connecting');
        term.writeln('\x1b[90m— Connecting… —\x1b[0m\r\n');

        ws = new WebSocket(WS_URL);
        ws.binaryType = 'arraybuffer';

        ws.onopen = () => {
            sendResize();
        };

        ws.onmessage = (event) => {
            let data;

            if (event.data instanceof ArrayBuffer) {
                // Binary: raw terminal bytes
                data = new Uint8Array(event.data);
            } else {
                // Try JSON control message first
                try {
                    const ctrl = JSON.parse(event.data);
                    handleControl(ctrl);
                    return;
                } catch (_) {
                    // Plain text output
                    data = event.data;
                }
            }

            term.write(data);
        };

        ws.onclose = (e) => {
            setStatus('closed');
            term.writeln('\r\n\x1b[90m— Connection closed —\x1b[0m');
            btnReconn.classList.remove('d-none');
            // Only show the generic overlay if a control message hasn't already shown a specific one
            if (!overlayShownByControl) {
                showOverlay('🔌', 'Disconnected', e.wasClean
                    ? 'The session was closed normally.'
                    : 'The connection was lost unexpectedly.', true);
            }
        };

        ws.onerror = () => {
            setStatus('error');
        };
    }

    function handleControl(ctrl) {
        switch (ctrl.type) {
            case 'status':
                term.writeln(`\x1b[90m${ctrl.message}\x1b[0m`);
                break;
            case 'connected':
                setStatus('connected');
                term.writeln(`\x1b[32m${ctrl.message}\x1b[0m\r\n`);
                break;
            case 'error':
                setStatus('error');
                term.writeln(`\r\n\x1b[31m✖ ${ctrl.message}\x1b[0m`);
                overlayShownByControl = true;
                showOverlay('❌', 'Connection Error', ctrl.message, true);
                break;
            case 'disconnected':
                setStatus('closed');
                term.writeln(`\r\n\x1b[90m${ctrl.message}\x1b[0m`);
                overlayShownByControl = true;
                showOverlay('🔌', 'Disconnected', ctrl.message, true);
                break;
        }
    }

    // ── User input → WebSocket ────────────────────────────────────────────
    term.onData((data) => {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(data);
        }
    });

    // ── Send resize to proxy ──────────────────────────────────────────────
    function sendResize() {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({
                type: 'resize',
                cols: term.cols,
                rows: term.rows,
            }));
        }
    }

    term.onResize(() => sendResize());

    // ── Copy All ───────────────────────────────────────────────────────────
    document.getElementById('btn-copy-all').addEventListener('click', () => {
        const btn    = document.getElementById('btn-copy-all');
        const buf    = term.buffer.active;
        const lines  = [];
        for (let i = 0; i < buf.length; i++) {
            const line = buf.getLine(i);
            if (line) lines.push(line.translateToString(true));
        }
        // Trim trailing empty lines
        while (lines.length && lines[lines.length - 1].trim() === '') lines.pop();
        const text = lines.join('\n');

        navigator.clipboard.writeText(text).then(() => {
            btn.innerHTML = '<i class="bi bi-clipboard-check"></i> Copied!';
            btn.classList.replace('btn-outline-secondary', 'btn-outline-success');
            setTimeout(() => {
                btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy All';
                btn.classList.replace('btn-outline-success', 'btn-outline-secondary');
            }, 2000);
        }).catch(() => {
            // Fallback for browsers that block clipboard without user gesture
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity  = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            btn.innerHTML = '<i class="bi bi-clipboard-check"></i> Copied!';
            setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy All'; }, 2000);
        });
    });

    // ── Toolbar buttons ────────────────────────────────────────────────────
    document.getElementById('btn-clear').addEventListener('click', () => term.clear());

    document.getElementById('btn-disconnect').addEventListener('click', () => {
        if (ws) ws.close(1000, 'User disconnected');
    });

    btnReconn.addEventListener('click', connect);
    document.getElementById('overlay-reconnect').addEventListener('click', connect);

    // ── Start ─────────────────────────────────────────────────────────────
    connect();

    // Fit after a tick so the container has its full height
    setTimeout(() => fitAddon.fit(), 50);

})();
</script>

</body>
</html>
