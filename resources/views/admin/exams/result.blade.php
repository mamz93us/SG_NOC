@extends('layouts.admin')

@section('title', $attempt->exam->code.' — Score report')

@php
    $own = (int) $attempt->user_id === (int) auth()->id();
    $pass = $attempt->passing_score;
    $duration = $attempt->durationSeconds();
@endphp

@section('content')
<div class="mx-auto" style="max-width: 900px;">
    <a href="{{ $own ? route('admin.exams.index') : route('admin.exams.results.index') }}" class="small text-decoration-none">
        <i class="bi bi-arrow-left me-1"></i>{{ $own ? 'All exams' : 'Team results' }}
    </a>

    <div class="card shadow-sm border-0 mt-2">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                <div>
                    <div class="text-muted small text-uppercase">Score report</div>
                    <h4 class="fw-bold mb-1">{{ $attempt->exam->code }} · {{ $attempt->exam->title }}</h4>
                    <div class="small text-muted">
                        {{ $attempt->user?->name }} · {{ $attempt->submitted_at?->format('d M Y H:i') }}
                        @if ($duration !== null) · {{ gmdate($duration >= 3600 ? 'G:i:s' : 'i:s', $duration) }} taken @endif
                        @if ($attempt->status === \App\Models\Exams\ExamAttempt::STATUS_EXPIRED) · <span class="text-danger">time ran out</span> @endif
                    </div>
                </div>
                <span class="badge fs-5 {{ $attempt->passed ? 'text-bg-success' : 'text-bg-danger' }}">{{ $attempt->passed ? 'PASS' : 'FAIL' }}</span>
            </div>

            <div class="row align-items-center g-4 mt-2">
                <div class="col-md-4 text-center">
                    <div class="display-4 fw-bold {{ $attempt->passed ? 'text-success' : 'text-danger' }}">{{ $attempt->score }}</div>
                    <div class="text-muted small">Your score · {{ $pass }} to pass</div>
                    <div class="small mt-1">{{ $attempt->correct_count }} of {{ $attempt->total_questions }} correct</div>
                </div>
                <div class="col-md-8">
                    {{-- The bar Microsoft's own score report draws: your score against the pass mark. --}}
                    <div class="position-relative" style="height: 2.25rem;">
                        <div class="progress h-100" role="progressbar" aria-valuenow="{{ $attempt->score }}" aria-valuemin="0" aria-valuemax="1000">
                            <div class="progress-bar {{ $attempt->passed ? 'bg-success' : 'bg-danger' }}" style="width: {{ $attempt->score / 10 }}%"></div>
                        </div>
                        <div class="position-absolute top-0 bottom-0 border-start border-2 border-dark" style="left: {{ $pass / 10 }}%;"></div>
                        <div class="position-absolute small fw-semibold" style="left: {{ $pass / 10 }}%; top: 100%; transform: translateX(-50%);">{{ $pass }}</div>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mt-4"><span>0</span><span>1000</span></div>
                </div>
            </div>

            <h6 class="fw-semibold mt-4 mb-3">Performance by skill area</h6>
            @foreach ($attempt->domain_results ?? [] as $d)
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>{{ $d['domain'] }}</span>
                        <span class="text-muted">{{ $d['correct'] }} / {{ $d['total'] }} · {{ $d['percent'] }}%</span>
                    </div>
                    <div class="progress" style="height: .6rem;">
                        <div class="progress-bar {{ $d['percent'] >= 70 ? 'bg-success' : ($d['percent'] >= 50 ? 'bg-warning' : 'bg-danger') }}" style="width: {{ $d['percent'] }}%"></div>
                    </div>
                </div>
            @endforeach
            <p class="small text-muted mb-0">A skill area under 70% is the one to study before the real exam.</p>
        </div>
        <div class="card-footer bg-transparent d-flex flex-wrap gap-2 p-3">
            @if ($canSeeAnswers)
                <a href="{{ route('admin.exams.attempts.answers', $attempt) }}" class="btn btn-primary"><i class="bi bi-journal-check me-1"></i>Review answers</a>
                <a href="{{ route('admin.exams.attempts.answers', [$attempt, 'show' => 'wrong']) }}" class="btn btn-outline-primary">Only the ones I got wrong</a>
            @endif
            @if ($own && $attempt->exam->is_active)
                <a href="{{ route('admin.exams.show', $attempt->exam) }}" class="btn btn-outline-secondary ms-auto"><i class="bi bi-arrow-repeat me-1"></i>Take it again</a>
            @endif
        </div>
    </div>

    @if ($history->count() > 1)
        <div class="card shadow-sm border-0 mt-3">
            <div class="card-header bg-transparent fw-semibold">{{ $own ? 'Your' : 'Their' }} {{ $attempt->exam->code }} attempts</div>
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
