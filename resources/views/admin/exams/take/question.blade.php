@extends('layouts.exam')

@section('subtitle')
    Question {{ $position }} of {{ $total }}
@endsection

@section('content')
<form method="POST" action="{{ route('admin.exams.attempts.answer', [$attempt, $position]) }}" id="answer-form">
    @csrf
    <input type="hidden" name="go" value="next" id="go">

    <div class="exam-card p-4 mx-auto" style="max-width: 900px;">
        @if (! $question)
            <p class="text-muted mb-0"><i class="bi bi-info-circle me-1"></i>This question was removed from the exam after you started.
                It does not count towards your score — move on to the next one.</p>
        @else
            <div class="d-flex justify-content-between align-items-start mb-3">
                <span class="badge text-bg-light border">Question {{ $position }}</span>
                @if ($question->isMultiple())
                    <span class="badge text-bg-info">Select {{ $question->requiredSelections() }} answers</span>
                @endif
            </div>

            <div class="exam-question mb-4">{{ $question->question }}</div>

            <div class="d-grid gap-2" id="options">
                @foreach ($order as $i => $key)
                    @php($checked = in_array($key, $chosen, true))
                    <label class="exam-option {{ $checked ? 'selected' : '' }}">
                        <input type="{{ $question->isMultiple() ? 'checkbox' : 'radio' }}" name="keys[]" value="{{ $key }}"
                               class="form-check-input" @checked($checked)>
                        <span class="opt-letter">{{ chr(65 + $i) }}.</span>
                        <span>{{ $question->options[$key] ?? '' }}</span>
                    </label>
                @endforeach
            </div>
        @endif
    </div>
</form>
@endsection

@section('bottom')
<div class="exam-bottom">
    <div class="container-xl d-flex flex-wrap align-items-center gap-2 py-2">
        <button type="button" class="btn btn-outline-secondary" data-go="prev" @disabled($position <= 1)>
            <i class="bi bi-chevron-left"></i> Previous
        </button>
        <div class="form-check ms-2 me-auto">
            <input class="form-check-input" type="checkbox" name="flagged" value="1" id="flag" form="answer-form" @checked($flagged)>
            <label class="form-check-label" for="flag"><i class="bi bi-flag-fill text-warning me-1"></i>Mark for review</label>
        </div>
        <span class="save-state text-muted me-2" id="save-state"></span>
        <button type="button" class="btn btn-outline-primary" data-go="review">
            <i class="bi bi-grid-3x3-gap me-1"></i>Review all
        </button>
        <button type="button" class="btn btn-primary" data-go="next">
            {{ $position >= $total ? 'Review' : 'Next' }} <i class="bi bi-chevron-right"></i>
        </button>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const form = document.getElementById('answer-form');
    const go = document.getElementById('go');
    const state = document.getElementById('save-state');
    let leaving = false;

    document.querySelectorAll('[data-go]').forEach(btn => btn.addEventListener('click', () => {
        leaving = true;
        go.value = btn.dataset.go;
        form.submit();
    }));

    // Keep the highlight in step with the inputs.
    function paint() {
        document.querySelectorAll('.exam-option').forEach(l => l.classList.toggle('selected', l.querySelector('input').checked));
    }

    // Save every change in the background, so a click is never lost to the clock.
    let pending = null;
    function save() {
        if (leaving || window.examTimeUp) return;
        const data = new FormData(form);
        data.set('go', 'stay');
        if (document.getElementById('flag').checked) data.set('flagged', '1');
        state.textContent = 'Saving…';
        pending = fetch(form.action, {
            method: 'POST', body: data, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(r => r.json()).then(j => {
            if (j.saved === false && j.redirect) { window.location.href = j.redirect; return; }
            state.innerHTML = '<i class="bi bi-check2"></i> Saved';
        }).catch(() => { state.textContent = 'Not saved — it will be saved when you move on.'; });
    }

    form.addEventListener('change', () => { paint(); save(); });
    document.getElementById('flag').addEventListener('change', save);
})();
</script>
@endpush
