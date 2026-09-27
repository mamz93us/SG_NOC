{{-- The exam-room screen: no NOC menus, just the exam, the candidate, the
     clock and the question count — the way a test centre shows it. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $attempt->exam->code }} — Exam</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --exam-bar: #0f3b66; --exam-bar-2: #0b2d4f; }
        html, body { height: 100%; }
        body { background: #eef1f5; display: flex; flex-direction: column; font-size: 1rem; }
        .exam-top { background: var(--exam-bar); color: #fff; }
        .exam-top .small { color: rgba(255,255,255,.75); }
        .exam-sub { background: var(--exam-bar-2); color: #fff; font-size: .9rem; }
        .exam-timer { font-variant-numeric: tabular-nums; font-weight: 600; letter-spacing: .02em; }
        .exam-timer.low { color: #ffc107; }
        .exam-timer.critical { color: #ff6b6b; animation: blink 1s steps(2, start) infinite; }
        @keyframes blink { to { visibility: hidden; } }
        @media (prefers-reduced-motion: reduce) { .exam-timer.critical { animation: none; } }
        main.exam-main { flex: 1 0 auto; padding-bottom: 5.5rem; }
        .exam-card { background: #fff; border-radius: .5rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .exam-question { font-size: 1.05rem; line-height: 1.6; white-space: pre-line; }
        .exam-option { border: 1px solid #d5dbe3; border-radius: .4rem; padding: .75rem 1rem; cursor: pointer; display: flex; gap: .75rem; align-items: flex-start; transition: background .1s, border-color .1s; }
        .exam-option:hover { background: #f5f8fc; border-color: #9fb3cc; }
        .exam-option input { margin-top: .3rem; flex-shrink: 0; transform: scale(1.15); }
        .exam-option.selected { background: #e8f1fb; border-color: #0d6efd; }
        .exam-option .opt-letter { font-weight: 600; min-width: 1.25rem; }
        .exam-bottom { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #d5dbe3; box-shadow: 0 -2px 6px rgba(0,0,0,.05); z-index: 10; }
        .save-state { font-size: .8rem; }
    </style>
    @stack('head')
</head>
<body>
    <header class="exam-top">
        <div class="container-xl d-flex flex-wrap align-items-center gap-3 py-2">
            <div class="me-auto">
                <div class="fw-semibold"><i class="bi bi-mortarboard me-1"></i>{{ $attempt->exam->code }} · {{ $attempt->exam->title }}</div>
                <div class="small">Candidate: {{ auth()->user()->name }}</div>
            </div>
            <div class="text-end">
                <div class="small">Time remaining</div>
                <div class="exam-timer fs-5" id="exam-timer" data-seconds="{{ $attempt->secondsLeft() }}"
                     data-timeout-url="{{ route('admin.exams.attempts.result', $attempt) }}">--:--</div>
            </div>
        </div>
    </header>
    <div class="exam-sub">
        <div class="container-xl d-flex flex-wrap align-items-center gap-3 py-1">
            <span class="me-auto">@yield('subtitle')</span>
            <span>{{ $attempt->answeredCount() }} of {{ $attempt->total_questions }} answered</span>
        </div>
    </div>

    <main class="exam-main">
        <div class="container-xl py-4">
            @yield('content')
        </div>
    </main>

    @yield('bottom')

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Mirrors the server's clock. The server is what enforces it: an answer
    // arriving after the end is refused and the attempt graded as it stood.
    (function () {
        const el = document.getElementById('exam-timer');
        let left = parseInt(el.dataset.seconds, 10) || 0;
        const endsAt = Date.now() + left * 1000;
        function fmt(s) {
            const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
            const mm = String(m).padStart(2, '0'), ss = String(sec).padStart(2, '0');
            return h > 0 ? h + ':' + mm + ':' + ss : mm + ':' + ss;
        }
        function tick() {
            left = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
            el.textContent = fmt(left);
            el.classList.toggle('low', left <= 300 && left > 60);
            el.classList.toggle('critical', left <= 60);
            if (left <= 0) {
                clearInterval(timer);
                window.examTimeUp = true;
                window.location.href = el.dataset.timeoutUrl;
            }
        }
        const timer = setInterval(tick, 1000);
        tick();
    })();
    </script>
    @stack('scripts')
</body>
</html>
