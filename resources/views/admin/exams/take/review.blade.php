@extends('layouts.exam')

@php
    $lang = $attempt->language ?? 'en';
    $ids = array_map('intval', $attempt->question_ids);
    $unanswered = collect($ids)->filter(fn ($id) => ! $attempt->answerFor($id))->count();
    $flaggedCount = collect($ids)->filter(fn ($id) => $attempt->isFlagged($id))->count();
    $firstFlagged = collect($ids)->search(fn ($id) => $attempt->isFlagged($id));
    $firstIncomplete = collect($ids)->search(fn ($id) => ! $attempt->answerFor($id));
@endphp

@section('subtitle')
    {{ __('exams.review', [], $lang) }}
@endsection

@section('content')
<div class="exam-card p-4 mx-auto" style="max-width: 900px;">
    <h5 class="fw-semibold mb-1">{{ __('exams.review_title', [], $lang) }}</h5>
    <p class="text-muted small mb-3">{{ __('exams.review_help', [], $lang) }}</p>

    <div class="d-flex flex-wrap gap-3 small mb-3">
        <span><span class="badge text-bg-primary">&nbsp;&nbsp;</span> {{ __('exams.answered', [], $lang) }} ({{ count($ids) - $unanswered }})</span>
        <span><span class="badge border border-secondary text-body">&nbsp;&nbsp;</span> {{ __('exams.incomplete', [], $lang) }} ({{ $unanswered }})</span>
        <span><i class="bi bi-flag-fill text-warning"></i> {{ __('exams.marked', [], $lang) }} ({{ $flaggedCount }})</span>
    </div>

    <div class="d-flex flex-wrap gap-2">
        @foreach ($ids as $i => $id)
            @php($answered = (bool) $attempt->answerFor($id))
            <a href="{{ route('admin.exams.attempts.question', [$attempt, $i + 1]) }}"
               class="btn {{ $answered ? 'btn-primary' : 'btn-outline-secondary' }} position-relative"
               style="width: 3.25rem;" title="{{ $answered ? __('exams.answered', [], $lang) : __('exams.incomplete', [], $lang) }}{{ $attempt->isFlagged($id) ? ' · '.__('exams.marked', [], $lang) : '' }}">
                {{ $i + 1 }}
                @if ($attempt->isFlagged($id))
                    <i class="bi bi-flag-fill text-warning position-absolute" style="top: -.45rem; inset-inline-end: -.35rem;"></i>
                @endif
            </a>
        @endforeach
    </div>

    @if ($firstFlagged !== false || $firstIncomplete !== false)
        <div class="mt-4 d-flex flex-wrap gap-2">
            @if ($firstFlagged !== false)
                <a href="{{ route('admin.exams.attempts.question', [$attempt, $firstFlagged + 1]) }}"
                   class="btn btn-sm btn-outline-warning"><i class="bi bi-flag me-1"></i>{{ __('exams.first_marked', [], $lang) }}</a>
            @endif
            @if ($firstIncomplete !== false)
                <a href="{{ route('admin.exams.attempts.question', [$attempt, $firstIncomplete + 1]) }}"
                   class="btn btn-sm btn-outline-secondary"><i class="bi bi-square me-1"></i>{{ __('exams.first_incomplete', [], $lang) }}</a>
            @endif
        </div>
    @endif
</div>

<div class="modal fade" id="finish-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">{{ __('exams.finish_title', [], $lang) }}</h5></div>
            <div class="modal-body">
                @if ($unanswered)
                    <p class="text-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ __('exams.unanswered_warning', ['count' => $unanswered], $lang) }}</p>
                @endif
                @if ($flaggedCount)
                    <p class="mb-2">{{ __('exams.flagged_note', ['count' => $flaggedCount], $lang) }}</p>
                @endif
                <p class="mb-0">{{ __('exams.cannot_change', [], $lang) }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('exams.keep_reviewing', [], $lang) }}</button>
                <form method="POST" action="{{ route('admin.exams.attempts.finish', $attempt) }}">
                    @csrf
                    <button class="btn btn-danger"><i class="bi bi-check2-circle me-1"></i>{{ __('exams.finish_exam', [], $lang) }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@section('bottom')
<div class="exam-bottom">
    <div class="container-xl d-flex align-items-center gap-2 py-2">
        <a href="{{ route('admin.exams.attempts.question', [$attempt, count($ids)]) }}" class="btn btn-outline-secondary me-auto">
            <i class="bi {{ $lang === 'ar' ? 'bi-chevron-right' : 'bi-chevron-left' }}"></i> {{ __('exams.back_to_question', ['n' => count($ids)], $lang) }}
        </a>
        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#finish-modal">
            <i class="bi bi-check2-circle me-1"></i>{{ __('exams.finish_exam', [], $lang) }}
        </button>
    </div>
</div>
@endsection
