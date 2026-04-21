@extends('layouts.admin')
@section('title', 'Telnet Console — ' . $device->name)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-terminal me-2 text-primary"></i>Telnet Console</h4>
        <small class="text-muted">{{ $device->name }} <span class="font-monospace ms-2">{{ $device->ip_address }}</span></small>
    </div>
    <div class="d-flex gap-2">
        @if($device->type === 'switch' || $device->type === 'router')
        <a href="{{ route('admin.switch-qos.setup', $device->id) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Setup
        </a>
        @endif
        <a href="{{ route('admin.switch-qos.dashboard') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>
</div>

@if(!$telnet)
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    No <code>telnet</code> credential configured for this device.
    <a href="{{ route('admin.switch-qos.setup', $device->id) }}">Add one</a> before using the console.
</div>
@else
<div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-light d-flex justify-content-between align-items-center">
        <span class="small font-monospace">
            <i class="bi bi-circle-fill text-success me-1" style="font-size:.5rem;"></i>
            Connected via stored credentials · {{ $enable ? 'enable mode' : 'user mode' }}
        </span>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-light py-0" id="btnClear" title="Clear screen"><i class="bi bi-eraser"></i></button>
        </div>
    </div>
    <div class="card-body p-0">
        <pre id="term" style="background:#111; color:#d4d4d4; font-family: ui-monospace, Menlo, Consolas, monospace; font-size:13px; padding:14px 16px; margin:0; min-height:60vh; max-height:70vh; overflow:auto; white-space:pre-wrap; word-break:break-all;"></pre>
    </div>
    <div class="card-footer bg-dark">
        <form id="cmdForm" class="d-flex gap-2 align-items-center mb-0">
            <span class="text-success font-monospace small">{{ explode('.', $device->name)[0] }}{{ $enable ? '#' : '>' }}</span>
            <input type="text" id="cmdInput" class="form-control form-control-sm bg-black text-light border-0 font-monospace"
                   placeholder="Enter a Cisco command (e.g. show running-config, show vlan brief)"
                   autocomplete="off" spellcheck="false" autofocus>
            <button class="btn btn-sm btn-success" type="submit" id="btnSend">
                <i class="bi bi-send"></i>
            </button>
        </form>
        <div class="small text-muted mt-1">
            <i class="bi bi-info-circle me-1"></i>Each command opens a fresh telnet session. Use <kbd>↑</kbd>/<kbd>↓</kbd> for history.
        </div>
    </div>
</div>

<script>
(function () {
    const term    = document.getElementById('term');
    const form    = document.getElementById('cmdForm');
    const input   = document.getElementById('cmdInput');
    const btnSend = document.getElementById('btnSend');
    const btnClr  = document.getElementById('btnClear');
    const endpoint = @json(route('admin.switch-qos.telnet.send', $device->id));
    const csrf     = @json(csrf_token());
    const promptStr = @json((explode('.', $device->name)[0] ?? $device->name) . ($enable ? '#' : '>'));

    const history = [];
    let histIdx = -1;

    function append(text, cls) {
        const span = document.createElement('span');
        if (cls) span.className = cls;
        span.textContent = text;
        term.appendChild(span);
        term.scrollTop = term.scrollHeight;
    }

    append(`Ready. Connected to {{ $device->ip_address }}\n\n`, 'text-success');

    async function run(command) {
        append(`${promptStr} ${command}\n`, 'text-info');
        btnSend.disabled = true;
        input.disabled = true;
        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ command }),
            });
            const data = await res.json();
            if (data.error) {
                append(data.error + '\n', 'text-danger');
            } else {
                append((data.output || '').replace(/\r/g, '') + '\n');
            }
        } catch (err) {
            append('Request failed: ' + err.message + '\n', 'text-danger');
        } finally {
            btnSend.disabled = false;
            input.disabled = false;
            input.focus();
        }
    }

    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        const cmd = input.value.trim();
        if (!cmd) return;
        history.push(cmd);
        histIdx = history.length;
        input.value = '';
        run(cmd);
    });

    input.addEventListener('keydown', (ev) => {
        if (ev.key === 'ArrowUp') {
            if (histIdx > 0) { histIdx--; input.value = history[histIdx]; }
            ev.preventDefault();
        } else if (ev.key === 'ArrowDown') {
            if (histIdx < history.length - 1) { histIdx++; input.value = history[histIdx]; }
            else { histIdx = history.length; input.value = ''; }
            ev.preventDefault();
        }
    });

    btnClr.addEventListener('click', () => { term.innerHTML = ''; input.focus(); });
})();
</script>
@endif
@endsection
