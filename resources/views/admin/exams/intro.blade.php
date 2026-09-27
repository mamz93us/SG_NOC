@extends('layouts.admin')

@section('title', $exam->code.' — Exam')

@section('content')
<div class="mx-auto" style="max-width: 820px;">
    <a href="{{ route('admin.exams.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>All exams</a>

    <div class="card shadow-sm border-0 mt-2">
        <div class="card-body p-4">
            <span class="badge text-bg-primary fs-6 mb-2">{{ $exam->code }}</span>
            <h4 class="fw-bold">{{ $exam->title }}</h4>
            @if ($exam->description)
                <p class="text-muted">{{ $exam->description }}</p>
            @endif

            <div class="row g-3 text-center my-3">
                <div class="col-4"><div class="border rounded py-3"><div class="fs-3 fw-bold">{{ $questionCount }}</div><div class="small text-muted">questions</div></div></div>
                <div class="col-4"><div class="border rounded py-3"><div class="fs-3 fw-bold">{{ $exam->duration_minutes }}</div><div class="small text-muted">minutes</div></div></div>
                <div class="col-4"><div class="border rounded py-3"><div class="fs-3 fw-bold">{{ $exam->passing_score }}</div><div class="small text-muted">to pass (of 1000)</div></div></div>
            </div>

            <h6 class="fw-semibold mt-4">Before you start</h6>
            <ul class="small">
                <li>The clock starts when you select <strong>Start exam</strong> and does not stop — not if you close the page, and not if you leave. Come back from the Exams menu to resume while there is time left.</li>
                <li>When the time runs out the exam is scored with the answers you have given. Each answer is saved as soon as you select it.</li>
                <li>Questions come in a random order, drawn from every skill area. Some ask you to <strong>select two or three</strong> answers — you score only if you pick exactly the right ones.</li>
                <li>An unanswered question scores zero, so answer every question. Use <strong>Mark for review</strong> to come back to one, and <strong>Review all</strong> to see which are incomplete.</li>
                <li>Nothing is final until you select <strong>Finish exam</strong>.</li>
                <li>Work on your own and without notes — the score is only useful if it tells you whether you are ready for the real exam.</li>
            </ul>

            @if ($domains->isNotEmpty())
                <h6 class="fw-semibold mt-4">Skills measured</h6>
                <ul class="small mb-0">
                    @foreach ($domains as $d)
                        <li>{{ $d->domain }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
        <div class="card-footer bg-transparent d-flex justify-content-end gap-2 p-3">
            <a href="{{ route('admin.exams.index') }}" class="btn btn-outline-secondary">Not now</a>
            <form method="POST" action="{{ route('admin.exams.start', $exam) }}">
                @csrf
                @if ($open)
                    <button class="btn btn-warning"><i class="bi bi-play-circle me-1"></i>Resume exam
                        <span class="small">({{ gmdate($open->secondsLeft() >= 3600 ? 'G:i:s' : 'i:s', $open->secondsLeft()) }} left)</span></button>
                @else
                    <button class="btn btn-primary" @disabled($questionCount === 0)><i class="bi bi-play-fill me-1"></i>Start exam</button>
                @endif
            </form>
        </div>
    </div>
</div>
@endsection
