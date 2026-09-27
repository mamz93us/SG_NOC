@extends('layouts.admin')

@section('title', $attempt->exam->code.' — Answers')

@php
    $lang = $attempt->language ?? 'en';
    $own = (int) $attempt->user_id === (int) auth()->id();
@endphp

@section('content')
<div class="mx-auto" style="max-width: 900px;" dir="{{ $lang === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $lang }}">
    <a href="{{ route('admin.exams.attempts.result', $attempt) }}" class="small text-decoration-none"><i class="bi {{ $lang === 'ar' ? 'bi-arrow-right' : 'bi-arrow-left' }} me-1"></i>{{ __('exams.score_report', [], $lang) }}</a>

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mt-2 mb-3">
        <div>
            <h4 class="fw-bold mb-0">{{ __('exams.answers_title', ['code' => $attempt->exam->code], $lang) }}</h4>
            <small class="text-muted">{{ $attempt->user?->name }} · {{ __('exams.score', [], $lang) }} {{ $attempt->score }} · {{ __('exams.correct_of', ['correct' => $attempt->correct_count, 'total' => $attempt->total_questions], $lang) }}</small>
        </div>
        <div class="btn-group btn-group-sm">
            <a href="{{ route('admin.exams.attempts.answers', $attempt) }}" class="btn {{ $filter === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">{{ __('exams.all', [], $lang) }}</a>
            <a href="{{ route('admin.exams.attempts.answers', [$attempt, 'show' => 'wrong']) }}" class="btn {{ $filter === 'wrong' ? 'btn-primary' : 'btn-outline-primary' }}">{{ __('exams.wrong_only', [], $lang) }}</a>
        </div>
    </div>

    @forelse ($rows as $row)
        @php
            $q = $row['question'];
            $shown = $q ? $q->shownIn($lang) : 'en';
        @endphp
        <div class="card shadow-sm border-0 mb-3 border-start border-4 {{ $row['right'] ? 'border-success' : 'border-danger' }}">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <span class="small text-muted">{{ __('exams.question_n', ['n' => $row['position']], $lang) }}@if ($q) · {{ \App\Models\Exams\ExamQuestion::domainIn($q->domain, $lang) }}@endif</span>
                    <span>
                        @if ($row['flagged'])<i class="bi bi-flag-fill text-warning me-1" title="{{ __('exams.marked', [], $lang) }}"></i>@endif
                        <span class="badge {{ $row['right'] ? 'text-bg-success' : 'text-bg-danger' }}">{{ $row['right'] ? __('exams.correct', [], $lang) : ($row['chosen'] ? __('exams.incorrect', [], $lang) : __('exams.not_answered', [], $lang)) }}</span>
                    </span>
                </div>

                @if (! $q)
                    <p class="text-muted mb-0">{{ __('exams.removed_from_bank', [], $lang) }}</p>
                @else
                    <div class="mb-3" style="white-space: pre-line;" @if ($shown !== $lang) dir="ltr" lang="en" @endif>{{ $q->textIn($lang) }}</div>
                    <ul class="list-group mb-3" @if ($shown !== $lang) dir="ltr" @endif>
                        @foreach ($q->options as $key => $text)
                            @php
                                $isAnswer = in_array((string) $key, array_map('strval', $q->answer ?? []), true);
                                $isChosen = in_array((string) $key, $row['chosen'], true);
                            @endphp
                            <li class="list-group-item d-flex gap-2 {{ $isAnswer ? 'list-group-item-success' : ($isChosen ? 'list-group-item-danger' : '') }}">
                                <span style="width: 1.25rem;">
                                    @if ($isAnswer)<i class="bi bi-check-circle-fill text-success"></i>
                                    @elseif ($isChosen)<i class="bi bi-x-circle-fill text-danger"></i>
                                    @endif
                                </span>
                                <span class="flex-fill">{{ $q->optionIn((string) $key, $lang) }}</span>
                                @if ($isChosen)<span class="small text-muted text-nowrap">{{ $own ? __('exams.your_answer', [], $lang) : __('exams.their_answer', [], $lang) }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($q->explanationIn($lang))
                        <div class="small bg-body-tertiary rounded p-2"><i class="bi bi-lightbulb me-1 text-warning"></i>{{ $q->explanationIn($lang) }}</div>
                    @endif
                    @if ($q->reference)
                        <div class="small mt-2"><a href="{{ $q->reference }}" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i>{{ __('exams.learn_more', [], $lang) }}</a></div>
                    @endif
                @endif
            </div>
        </div>
    @empty
        <div class="alert alert-success">{{ __('exams.all_correct', [], $lang) }}</div>
    @endforelse
</div>
@endsection
