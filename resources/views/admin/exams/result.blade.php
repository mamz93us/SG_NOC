@extends('layouts.admin')

@section('title', $attempt->exam->code.' — Score report')

@php
    $lang = $attempt->language ?? 'en';
    $own = (int) $attempt->user_id === (int) auth()->id();
    $pass = $attempt->passing_score;
    $duration = $attempt->durationSeconds();
    // The bar is drawn left-to-right whatever the language: it is a scale, not text.
    $start = $lang === 'ar' ? 'right' : 'left';
@endphp

@section('content')
<div class="mx-auto" style="max-width: 900px;" dir="{{ $lang === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $lang }}">
    <a href="{{ $own ? route('admin.exams.index') : route('admin.exams.results.index') }}" class="small text-decoration-none">
        <i class="bi {{ $lang === 'ar' ? 'bi-arrow-right' : 'bi-arrow-left' }} me-1"></i>{{ $own ? __('exams.all_exams', [], $lang) : __('exams.team_results', [], $lang) }}
    </a>

    <div class="card shadow-sm border-0 mt-2">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <div class="text-muted small text-uppercase">{{ __('exams.score_report', [], $lang) }}</div>
                    <h4 class="fw-bold mb-1"><bdi>{{ $attempt->exam->code }}</bdi> · {{ $attempt->exam->titleIn($lang) }}</h4>
                    <div class="small text-muted">
                        {{ $attempt->user?->name }} · <bdi>{{ $attempt->submitted_at?->format('d M Y H:i') }}</bdi>
                        @if ($duration !== null) · {{ __('exams.taken', [], $lang) }} <bdi>{{ gmdate($duration >= 3600 ? 'G:i:s' : 'i:s', $duration) }}</bdi> @endif
                        @if ($attempt->status === \App\Models\Exams\ExamAttempt::STATUS_EXPIRED) · <span class="text-danger">{{ __('exams.time_ran_out', [], $lang) }}</span> @endif
                    </div>
                </div>
                <span class="badge fs-5 {{ $attempt->passed ? 'text-bg-success' : 'text-bg-danger' }}">{{ $attempt->passed ? __('exams.pass', [], $lang) : __('exams.fail', [], $lang) }}</span>
            </div>

            <div class="row align-items-center g-4 mt-2">
                <div class="col-md-4 text-center">
                    <div class="display-4 fw-bold {{ $attempt->passed ? 'text-success' : 'text-danger' }}">{{ $attempt->score }}</div>
                    <div class="text-muted small">{{ __('exams.your_score', [], $lang) }} · {{ __('exams.to_pass', ['score' => $pass], $lang) }}</div>
                    <div class="small mt-1">{{ __('exams.correct_of', ['correct' => $attempt->correct_count, 'total' => $attempt->total_questions], $lang) }}</div>
                </div>
                <div class="col-md-8">
                    {{-- The bar Microsoft's own score report draws: your score against the pass mark. --}}
                    <div class="position-relative" style="height: 2.25rem;">
                        <div class="progress h-100" role="progressbar" aria-valuenow="{{ $attempt->score }}" aria-valuemin="0" aria-valuemax="1000">
                            <div class="progress-bar {{ $attempt->passed ? 'bg-success' : 'bg-danger' }}" style="width: {{ $attempt->score / 10 }}%"></div>
                        </div>
                        <div class="position-absolute top-0 bottom-0 border-start border-2 border-dark" style="{{ $start }}: {{ $pass / 10 }}%;"></div>
                        <div class="position-absolute small fw-semibold" style="{{ $start }}: {{ $pass / 10 }}%; top: 100%; transform: translateX({{ $lang === 'ar' ? '50%' : '-50%' }});">{{ $pass }}</div>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mt-4"><span>0</span><span>1000</span></div>
                </div>
            </div>

            <h6 class="fw-semibold mt-4 mb-3">{{ __('exams.by_skill', [], $lang) }}</h6>
            @foreach ($attempt->domain_results ?? [] as $d)
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>{{ \App\Models\Exams\ExamQuestion::domainIn($d['domain'], $lang) }}</span>
                        <span class="text-muted"><bdi>{{ $d['correct'] }} / {{ $d['total'] }}</bdi> · {{ $d['percent'] }}%</span>
                    </div>
                    <div class="progress" style="height: .6rem;">
                        <div class="progress-bar {{ $d['percent'] >= 70 ? 'bg-success' : ($d['percent'] >= 50 ? 'bg-warning' : 'bg-danger') }}" style="width: {{ $d['percent'] }}%"></div>
                    </div>
                </div>
            @endforeach
            <p class="small text-muted mb-0">{{ __('exams.study_hint', [], $lang) }}</p>
        </div>
        <div class="card-footer bg-transparent d-flex flex-wrap gap-2 p-3">
            @if ($canSeeAnswers)
                <a href="{{ route('admin.exams.attempts.answers', $attempt) }}" class="btn btn-primary"><i class="bi bi-journal-check me-1"></i>{{ __('exams.review_answers', [], $lang) }}</a>
                <a href="{{ route('admin.exams.attempts.answers', [$attempt, 'show' => 'wrong']) }}" class="btn btn-outline-primary">{{ __('exams.only_wrong', [], $lang) }}</a>
            @endif
            @if ($own && $attempt->exam->is_active)
                <a href="{{ route('admin.exams.show', $attempt->exam) }}" class="btn btn-outline-secondary ms-auto"><i class="bi bi-arrow-repeat me-1"></i>{{ __('exams.take_again', [], $lang) }}</a>
            @endif
        </div>
    </div>

    @if ($history->count() > 1)
        <div class="card shadow-sm border-0 mt-3">
            <div class="card-header bg-transparent fw-semibold">{{ __('exams.attempts_history', ['code' => $attempt->exam->code], $lang) }}</div>
            <div class="card-body">
                <div class="d-flex align-items-end gap-2" style="height: 120px;">
                    @foreach ($history as $h)
                        <a href="{{ route('admin.exams.attempts.result', $h) }}" class="d-flex flex-column align-items-center text-decoration-none flex-fill" style="max-width: 60px;"
                           title="{{ $h->submitted_at?->format('d M Y') }}: {{ $h->score }}">
                            <span class="small {{ $h->id === $attempt->id ? 'fw-bold' : 'text-muted' }}">{{ $h->score }}</span>
                            <div class="w-100 rounded-top {{ $h->passed ? 'bg-success' : 'bg-danger' }} {{ $h->id === $attempt->id ? '' : 'opacity-50' }}"
                                 style="height: {{ max(4, $h->score / 1000 * 90) }}px;"></div>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
