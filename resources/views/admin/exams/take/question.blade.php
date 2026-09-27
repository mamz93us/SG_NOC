@extends('layouts.exam')

@php
    $lang = $attempt->language ?? 'en';
    // The language this question is really shown in: Arabic only when it is fully translated.
    $shown = $question ? $question->shownIn($lang) : 'en';
    $bilingual = $shown === 'ar';
    $saveText = [
        'saving' => __('exams.saving', [], $lang),
        'saved' => __('exams.saved', [], $lang),
        'failed' => __('exams.not_saved', [], $lang),
    ];
@endphp

@section('subtitle')
    {{ __('exams.question_x_of_y', ['x' => $position, 'y' => $total], $lang) }}
@endsection

@section('content')
<form method="POST" action="{{ route('admin.exams.attempts.answer', [$attempt, $position]) }}" id="answer-form">
    @csrf
    <input type="hidden" name="go" value="next" id="go">

    <div class="exam-card p-4 mx-auto" style="max-width: 900px;">
        @if (! $question)
            <p class="text-muted mb-0"><i class="bi bi-info-circle me-1"></i>{{ __('exams.removed', [], $lang) }}</p>
        @else
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <span class="badge text-bg-light border">{{ __('exams.question_n', ['n' => $position], $lang) }}</span>
                <span class="d-flex gap-2 align-items-center">
                    @if ($question->isMultiple())
                        <span class="badge text-bg-info">{{ __('exams.select_n', ['n' => $question->requiredSelections()], $lang) }}</span>
                    @endif
                    @if ($bilingual)
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="toggle-original" aria-pressed="false">
                            <i class="bi bi-translate me-1"></i><span class="t-translated">{{ __('exams.show_english', [], $lang) }}</span><span class="t-original">{{ __('exams.show_translation', [], $lang) }}</span>
                        </button>
                    @elseif ($lang === 'ar')
                        <span class="small text-muted">{{ __('exams.in_english_only', [], $lang) }}</span>
                    @endif
                </span>
            </div>

            @if ($bilingual)
                <div class="exam-question mb-4 t-translated">{{ $question->textIn('ar') }}</div>
                <div class="exam-question mb-4 t-original" dir="ltr" lang="en">{{ $question->question }}</div>
            @else
                <div class="exam-question mb-4" @if ($lang === 'ar') dir="ltr" lang="en" @endif>{{ $question->question }}</div>
            @endif

            <div class="d-grid gap-2" id="options" @if ($lang === 'ar' && ! $bilingual) dir="ltr" @endif>
                @foreach ($order as $i => $key)
                    @php($checked = in_array($key, $chosen, true))
                    <label class="exam-option {{ $checked ? 'selected' : '' }}">
                        <input type="{{ $question->isMultiple() ? 'checkbox' : 'radio' }}" name="keys[]" value="{{ $key }}"
                               class="form-check-input" @checked($checked)>
                        <span class="opt-letter" dir="ltr">{{ chr(65 + $i) }}.</span>
                        @if ($bilingual)
                            <span class="t-translated">{{ $question->optionIn($key, 'ar') }}</span>
                            <span class="t-original" dir="ltr" lang="en">{{ $question->options[$key] ?? '' }}</span>
                        @else
                            <span>{{ $question->options[$key] ?? '' }}</span>
                        @endif
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
            <i class="bi {{ $lang === 'ar' ? 'bi-chevron-right' : 'bi-chevron-left' }}"></i> {{ __('exams.previous', [], $lang) }}
        </button>
        <div class="form-check ms-2 me-auto">
            <input class="form-check-input" type="checkbox" name="flagged" value="1" id="flag" form="answer-form" @checked($flagged)>
            <label class="form-check-label" for="flag"><i class="bi bi-flag-fill text-warning me-1"></i>{{ __('exams.mark_for_review', [], $lang) }}</label>
        </div>
        <span class="save-state text-muted me-2" id="save-state"></span>
        <button type="button" class="btn btn-outline-primary" data-go="review">
            <i class="bi bi-grid-3x3-gap me-1"></i>{{ __('exams.review_all', [], $lang) }}
        </button>
        <button type="button" class="btn btn-primary" data-go="next">
            {{ $position >= $total ? __('exams.review', [], $lang) : __('exams.next', [], $lang) }} <i class="bi {{ $lang === 'ar' ? 'bi-chevron-left' : 'bi-chevron-right' }}"></i>
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
    const text = @json($saveText);
    let leaving = false;

    document.querySelectorAll('[data-go]').forEach(btn => btn.addEventListener('click', () => {
        leaving = true;
        go.value = btn.dataset.go;
        form.submit();
    }));

    // Show the English original of this question, and back.
    const toggle = document.getElementById('toggle-original');
    if (toggle) {
        toggle.addEventListener('click', () => {
            const on = document.body.classList.toggle('show-original');
            toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    // Keep the highlight in step with the inputs.
    function paint() {
        document.querySelectorAll('.exam-option').forEach(l => l.classList.toggle('selected', l.querySelector('input').checked));
    }

    // Save every change in the background, so a click is never lost to the clock.
    function save() {
        if (leaving || window.examTimeUp) return;
        const data = new FormData(form);
        data.set('go', 'stay');
        if (document.getElementById('flag').checked) data.set('flagged', '1');
        state.textContent = text.saving;
        fetch(form.action, {
            method: 'POST', body: data, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(r => r.json()).then(j => {
            if (j.saved === false && j.redirect) { window.location.href = j.redirect; return; }
            state.innerHTML = '<i class="bi bi-check2"></i> ' + text.saved;
        }).catch(() => { state.textContent = text.failed; });
    }

    form.addEventListener('change', () => { paint(); save(); });
    document.getElementById('flag').addEventListener('change', save);
})();
</script>
@endpush
