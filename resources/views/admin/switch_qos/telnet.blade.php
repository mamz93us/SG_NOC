@extends('layouts.admin')
@section('title', 'Telnet Console — ' . $device->name)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-terminal me-2 text-primary"></i>Telnet Console</h4>
        <small class="text-muted">{{ $device->name }} <span class="font-monospace ms-2">{{ $device->ip_address }}</span></small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.switch-qos.setup', $device->id) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Setup
        </a>
        <a href="{{ route('admin.switch-qos.dashboard') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>
</div>

@if(!$telnet)
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    No <code>telnet</code> credential configured.
    <a href="{{ route('admin.switch-qos.setup', $device->id) }}">Add one</a> first.
</div>
@else
<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-light d-flex justify-content-between align-items-center">
        <span class="small font-monospace" id="statusLine">
            <i class="bi bi-circle-fill text-warning me-1" style="font-size:.5rem;"></i>
            Opening session…
        </span>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-light py-0" id="btnClear" title="Clear screen"><i class="bi bi-eraser"></i></button>
            <button class="btn btn-sm btn-outline-warning py-0" id="btnReconnect" title="Reconnect"><i class="bi bi-arrow-clockwise"></i></button>
            <button class="btn btn-sm btn-outline-danger py-0" id="btnClose" title="End session"><i class="bi bi-x-lg"></i></button>
        </div>
    </div>
    <div class="card-body p-0">
        <pre id="term" tabindex="0"
             style="background:#111;color:#d4d4d4;font-family:ui-monospace,Menlo,Consolas,monospace;
                    font-size:13px;padding:14px 16px;margin:0;min-height:68vh;max-height:72vh;
                    overflow:auto;white-space:pre-wrap;word-break:break-all;outline:none;"></pre>
    </div>
    <div class="card-footer bg-dark">
        <form id="cmdForm" class="d-flex gap-2 align-items-center mb-0">
            <span class="text-success font-monospace small" id="promptHint">&gt;</span>
            <input type="text" id="cmdInput" class="form-control form-control-sm bg-black text-light border-0 font-monospace"
                   placeholder="Type a command and press Enter — config mode is supported (session stays open)"
                   autocomplete="off" spellcheck="false" autofocus disabled>
            <button class="btn btn-sm btn-outline-light py-0" type="button" id="btnCtrlC" title="Send Ctrl+C">^C</button>
            <button class="btn btn-sm btn-success" type="submit" id="btnSend" disabled>
                <i class="bi bi-send"></i>
            </button>
        </form>
        <div class="small text-muted mt-1">
            <i class="bi bi-info-circle me-1"></i>Persistent session — <code>configure terminal</code>, <code>interface …</code>, etc. stay in context.
            <kbd>↑</kbd>/<kbd>↓</kbd> history · <kbd>Ctrl</kbd>+<kbd>L</kbd> clear.
        </div>
    </div>
</div>

<script>
(function () {
    const term         = document.getElementById('term');
    const form         = document.getElementById('cmdForm');
    const input        = document.getElementById('cmdInput');
    const btnSend      = document.getElementById('btnSend');
    const btnClear     = document.getElementById('btnClear');
    const btnReconnect = document.getElementById('btnReconnect');
    const btnClose     = document.getElementById('btnClose');
    const btnCtrlC     = document.getElementById('btnCtrlC');
    const statusLine   = document.getElementById('statusLine');

    const openUrl  = @json(route('admin.switch-qos.telnet.open', $device->id));
    const readUrl  = @json(route('admin.switch-qos.telnet.read'));
    const writeUrl = @json(route('admin.switch-qos.telnet.write'));
    const closeUrl = @json(route('admin.switch-qos.telnet.close'));
    const csrf     = @json(csrf_token());

    let token  = null;
    let offset = 0;
    let polling = false;
    let stopped = false;
    const history = [];
    let histIdx = -1;

    function setStatus(text, cls) {
        statusLine.innerHTML = `<i class="bi bi-circle-fill me-1 ${cls}" style="font-size:.5rem;"></i>` + text;
    }

    function append(text) {
        if (!text) return;
        // Minimal ANSI CSI stripping — Cisco IOS rarely emits colors but strip just in case.
        text = text.replace(/\x1b\[[0-9;?]*[a-zA-Z]/g, '');
        const atBottom = term.scrollTop + term.clientHeight >= term.scrollHeight - 4;
        term.appendChild(document.createTextNode(text));
        if (atBottom) term.scrollTop = term.scrollHeight;
    }

    async function openSession() {
        stopped = false;
        offset  = 0;
        term.textContent = '';
        setStatus('Opening session…', 'text-warning');
        btnSend.disabled = true;
        input.disabled   = true;

        try {
            const res = await fetch(openUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || ('HTTP ' + res.status));

            token = data.token;
            if (data.status && data.status.startsWith('error:')) {
                setStatus('Error: ' + data.status.slice(6), 'text-danger');
            } else if (data.status === 'ready') {
                setStatus('Connected · persistent session', 'text-success');
                btnSend.disabled = false;
                input.disabled   = false;
                input.focus();
            } else {
                setStatus('Spawning daemon: ' + data.status, 'text-warning');
            }
            pollLoop();
        } catch (err) {
            setStatus('Failed to open: ' + err.message, 'text-danger');
        }
    }

    async function pollLoop() {
        if (polling || stopped || !token) return;
        polling = true;
        try {
            while (!stopped && token) {
                const url = readUrl + '?token=' + encodeURIComponent(token) + '&offset=' + offset;
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) { setStatus('Poll error HTTP ' + res.status, 'text-danger'); break; }
                const data = await res.json();
                if (data.data) append(data.data);
                offset = data.offset;

                if (data.status === 'ready') {
                    if (input.disabled) { btnSend.disabled = false; input.disabled = false; input.focus(); }
                    setStatus('Connected · persistent session', 'text-success');
                } else if (data.status === 'closed') {
                    setStatus('Session ended', 'text-secondary');
                    btnSend.disabled = true; input.disabled = true;
                    stopped = true; break;
                } else if (data.status && data.status.startsWith('error:')) {
                    setStatus('Error: ' + data.status.slice(6), 'text-danger');
                    btnSend.disabled = true; input.disabled = true;
                    stopped = true; break;
                }

                await new Promise(r => setTimeout(r, data.data ? 150 : 400));
            }
        } finally { polling = false; }
    }

    async function sendRaw(bytes) {
        if (!token || stopped) return;
        await fetch(writeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify({ token, input: bytes }),
        });
    }

    async function closeSession() {
        if (!token) return;
        try {
            await fetch(closeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ token }),
            });
        } catch {}
        stopped = true;
    }

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const cmd = input.value;
        if (!cmd && cmd !== '') return;
        history.push(cmd); histIdx = history.length;
        input.value = '';
        // Cisco expects CRLF for command submission
        sendRaw(cmd + '\r\n');
    });

    input.addEventListener('keydown', (ev) => {
        if (ev.key === 'ArrowUp') {
            if (histIdx > 0) { histIdx--; input.value = history[histIdx]; }
            ev.preventDefault();
        } else if (ev.key === 'ArrowDown') {
            if (histIdx < history.length - 1) { histIdx++; input.value = history[histIdx]; }
            else { histIdx = history.length; input.value = ''; }
            ev.preventDefault();
        } else if (ev.key === 'l' && ev.ctrlKey) {
            term.textContent = ''; ev.preventDefault();
        }
    });

    btnClear.addEventListener('click',     () => { term.textContent = ''; input.focus(); });
    btnReconnect.addEventListener('click', async () => { await closeSession(); await openSession(); });
    btnClose.addEventListener('click',     async () => { await closeSession(); setStatus('Session ended by user', 'text-secondary'); btnSend.disabled = true; input.disabled = true; });
    btnCtrlC.addEventListener('click',     () => sendRaw('\x03'));

    window.addEventListener('beforeunload', () => {
        if (token && !stopped) {
            navigator.sendBeacon(closeUrl,
                new Blob([JSON.stringify({ token, _token: csrf })], { type: 'application/json' }));
        }
    });

    openSession();
})();
</script>
@endif
@endsection
