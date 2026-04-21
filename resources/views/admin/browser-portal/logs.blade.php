@extends('layouts.admin')

@section('title', 'Remote Browser — Live Logs')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-terminal me-2"></i>Live logs — <code>{{ $session->container_name }}</code></h3>
        <div class="btn-group">
            <button id="pause-btn" class="btn btn-outline-warning btn-sm"><i class="bi bi-pause-fill"></i> Pause</button>
            <button id="clear-btn" class="btn btn-outline-secondary btn-sm"><i class="bi bi-trash"></i> Clear</button>
            <a href="{{ route('admin.browser-portal.admin.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <pre id="log-box" style="
                background:#0b0e14; color:#d1d5db; padding:12px; margin:0;
                height: calc(100vh - 220px); overflow:auto; white-space:pre-wrap; word-break:break-all;
                font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12px; line-height:1.35;
            "></pre>
        </div>
        <div class="card-footer d-flex justify-content-between small text-muted">
            <span><span id="status-dot" class="badge bg-secondary">connecting…</span></span>
            <span><span id="line-count">0</span> lines · streaming <code>docker logs -f {{ $session->container_name }}</code> (10-min cap)</span>
        </div>
    </div>
</div>

<script>
(function () {
    const box   = document.getElementById('log-box');
    const dot   = document.getElementById('status-dot');
    const count = document.getElementById('line-count');
    const pauseBtn = document.getElementById('pause-btn');
    const clearBtn = document.getElementById('clear-btn');

    let paused = false;
    let lines  = 0;

    const es = new EventSource(@json(route('admin.browser-portal.admin.logs.stream', $session->session_id)));
    es.onopen  = () => { dot.className = 'badge bg-success'; dot.textContent = 'connected'; };
    es.onerror = () => { dot.className = 'badge bg-danger';  dot.textContent = 'disconnected'; };

    es.addEventListener('log', (e) => {
        if (paused) return;
        try {
            const msg = JSON.parse(e.data);
            const color = msg.stream === 'err' ? '#fca5a5' : '#d1d5db';
            const atBottom = (box.scrollHeight - box.scrollTop - box.clientHeight) < 40;
            box.insertAdjacentHTML('beforeend',
                `<span style="color:${color}">${escapeHtml(msg.line)}</span>\n`);
            lines++;
            count.textContent = lines;
            if (lines > 5000) {
                // Trim to last 4000 lines to keep DOM small.
                const all = box.innerHTML.split('\n');
                box.innerHTML = all.slice(-4000).join('\n');
            }
            if (atBottom) box.scrollTop = box.scrollHeight;
        } catch (_) {}
    });

    pauseBtn.addEventListener('click', () => {
        paused = !paused;
        pauseBtn.innerHTML = paused
            ? '<i class="bi bi-play-fill"></i> Resume'
            : '<i class="bi bi-pause-fill"></i> Pause';
    });
    clearBtn.addEventListener('click', () => { box.innerHTML = ''; lines = 0; count.textContent = 0; });
    window.addEventListener('beforeunload', () => es.close());

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
    }
})();
</script>
@endsection
