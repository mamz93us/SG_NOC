@extends('layouts.exam')

@section('subtitle')
    Review
@endsection

@php
    $ids = array_map('intval', $attempt->question_ids);
    $unanswered = collect($ids)->filter(fn ($id) => ! $attempt->answerFor($id))->count();
    $flaggedCount = collect($ids)->filter(fn ($id) => $attempt->isFlagged($id))->count();
@endphp

@section('content')
<div class="exam-card p-4 mx-auto" style="max-width: 900px;">
    <h5 class="fw-semibold mb-1">Review your answers</h5>
    <p class="text-muted small mb-3">
        Pick a question to go back to it. Nothing is final until you select <strong>Finish exam</strong> — after that you
        cannot change any answer.
    </p>

    <div class="d-flex flex-wrap gap-3 small mb-3">
        <span><span class="badge text-bg-primary">&nbsp;&nbsp;</span> Answered ({{ count($ids) - $unanswered }})</span>
        <span><span class="badge border border-secondary text-body">&nbsp;&nbsp;</span> Incomplete ({{ $unanswered }})</span>
        <span><i class="bi bi-flag-fill text-warning"></i> Marked for review ({{ $flaggedCount }})</span>
    </div>

    <div class="d-flex flex-wrap gap-2">
        @foreach ($ids as $i => $id)
            @php($answered = (bool) $attempt->answerFor($id))
            <a href="{{ route('admin.exams.attempts.question', [$attempt, $i + 1]) }}"
               class="btn {{ $answered ? 'btn-primary' : 'btn-outline-secondary' }} position-relative"
               style="width: 3.25rem;" title="{{ $answered ? 'Answered' : 'Incomplete' }}{{ $attempt->isFlagged($id) ? ' · marked for review' : '' }}">
                {{ $i + 1 }}
                @if ($attempt->isFlagged($id))
                    <i class="bi bi-flag-fill text-warning position-absolute" style="top: -.45rem; right: -.35rem;"></i>
                @endif
            </a>
        @endforeach
    </div>

    @if ($flaggedCount)
        <div class="mt-4">
            <a href="{{ route('admin.exams.attempts.question', [$attempt, collect($ids)->search(fn ($id) => $attempt->isFlagged($id)) + 1]) }}"
               class="btn btn-sm btn-outline-warning"><i class="bi bi-flag me-1"></i>Go to the first marked question</a>
            @if ($unanswered)
                <a href="{{ route('admin.exams.attempts.question', [$attempt, collect($ids)->search(fn ($id) => ! $attempt->answerFor($id)) + 1]) }}"
                   class="btn btn-sm btn-outline-secondary ms-1"><i class="bi bi-square me-1"></i>Go to the first incomplete question</a>
            @endif
        </div>
    @elseif ($unanswered)
        <div class="mt-4">
            <a href="{{ route('admin.exams.attempts.question', [$attempt, collect($ids)->search(fn ($id) => ! $attempt->answerFor($id)) + 1]) }}"
               class="btn btn-sm btn-outline-secondary"><i class="bi bi-square me-1"></i>Go to the first incomplete question</a>
        </div>
    @endif
</div>

<div class="modal fade" id="finish-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Finish the exam?</h5></div>
            <div class="modal-body">
                @if ($unanswered)
                    <p class="text-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i>
                        {{ $unanswered }} {{ \Illuminate\Support\Str::plural('question', $unanswered) }} not answered — an unanswered question scores zero.</p>
                @endif
                @if ($flaggedCount)
                    <p class="mb-2">{{ $flaggedCount }} {{ \Illuminate\Support\Str::plural('question', $flaggedCount) }} still marked for review.</p>
                @endif
                <p class="mb-0">Once you finish you cannot go back and change your answers.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep reviewing</button>
                <form method="POST" action="{{ route('admin.exams.attempts.finish', $attempt) }}">
                    @csrf
                    <button class="btn btn-danger"><i class="bi bi-check2-circle me-1"></i>Finish exam</button>
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
            <i class="bi bi-chevron-left"></i> Back to question {{ count($ids) }}
        </a>
        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#finish-modal">
            <i class="bi bi-check2-circle me-1"></i>Finish exam
        </button>
    </div>
</div>
@endsection
