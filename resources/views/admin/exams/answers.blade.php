@extends('layouts.admin')

@section('title', $attempt->exam->code.' — Answers')

@section('content')
<div class="mx-auto" style="max-width: 900px;">
    <a href="{{ route('admin.exams.attempts.result', $attempt) }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Score report</a>

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mt-2 mb-3">
        <div>
            <h4 class="fw-bold mb-0">{{ $attempt->exam->code }} answers</h4>
            <small class="text-muted">{{ $attempt->user?->name }} · score {{ $attempt->score }} · {{ $attempt->correct_count }} of {{ $attempt->total_questions }} correct</small>
        </div>
        <div class="btn-group btn-group-sm">
            <a href="{{ route('admin.exams.attempts.answers', $attempt) }}" class="btn {{ $filter === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">All</a>
            <a href="{{ route('admin.exams.attempts.answers', [$attempt, 'show' => 'wrong']) }}" class="btn {{ $filter === 'wrong' ? 'btn-primary' : 'btn-outline-primary' }}">Wrong only</a>
        </div>
    </div>

    @forelse ($rows as $row)
        @php
            $q = $row['question'];
        @endphp
        <div class="card shadow-sm border-0 mb-3 border-start border-4 {{ $row['right'] ? 'border-success' : 'border-danger' }}">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <span class="small text-muted">Question {{ $row['position'] }}@if ($q) · {{ $q->domain }}@endif</span>
                    <span>
                        @if ($row['flagged'])<i class="bi bi-flag-fill text-warning me-1" title="Marked for review"></i>@endif
                        <span class="badge {{ $row['right'] ? 'text-bg-success' : 'text-bg-danger' }}">{{ $row['right'] ? 'Correct' : ($row['chosen'] ? 'Incorrect' : 'Not answered') }}</span>
                    </span>
                </div>

                @if (! $q)
                    <p class="text-muted mb-0">This question has since been removed from the bank.</p>
                @else
                    <div class="mb-3" style="white-space: pre-line;">{{ $q->question }}</div>
                    <ul class="list-group mb-3">
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
                                <span class="flex-fill">{{ $text }}</span>
                                @if ($isChosen)<span class="small text-muted text-nowrap">{{ (int) $attempt->user_id === (int) auth()->id() ? 'your answer' : 'their answer' }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($q->explanation)
                        <div class="small bg-body-tertiary rounded p-2"><i class="bi bi-lightbulb me-1 text-warning"></i>{{ $q->explanation }}</div>
                    @endif
                    @if ($q->reference)
                        <div class="small mt-2"><a href="{{ $q->reference }}" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i>Microsoft Learn</a></div>
                    @endif
                @endif
            </div>
        </div>
    @empty
        <div class="alert alert-success">Nothing to show — every answer was correct.</div>
    @endforelse
</div>
@endsection
