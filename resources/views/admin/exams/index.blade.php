@extends('layouts.admin')

@section('title', 'Exams')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-mortarboard me-2 text-primary"></i>Practice exams</h4>
        <small class="text-muted">Timed, scored 1–1000 like the real Microsoft exams. 700 passes.</small>
    </div>
    @can('manage-exams')
        <div class="d-flex gap-2">
            <a href="{{ route('admin.exams.results.index') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-bar-chart-line me-1"></i>Team results</a>
            <a href="{{ route('admin.exams.manage.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-collection me-1"></i>Manage exams</a>
        </div>
    @endcan
</div>

<div class="row g-3 mb-4">
    @forelse ($exams as $exam)
        <div class="col-md-6 col-xl-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge text-bg-primary fs-6">{{ $exam->code }}</span>
                        @if ($best->has($exam->id))
                            <span class="badge {{ $best[$exam->id] >= $exam->passing_score ? 'text-bg-success' : 'text-bg-secondary' }}"
                                  title="Your best score">Best {{ $best[$exam->id] }}</span>
                        @endif
                    </div>
                    <h6 class="fw-semibold">{{ $exam->title }}</h6>
                    @if ($exam->arabicQuestionCount() > 0)
                        <div class="small text-muted mb-2"><i class="bi bi-translate me-1"></i>English · <span lang="ar">العربية</span></div>
                    @endif
                    @if ($exam->description)
                        <p class="small text-muted">{{ \Illuminate\Support\Str::limit($exam->description, 180) }}</p>
                    @endif
                    <ul class="list-unstyled small text-muted mb-3">
                        <li><i class="bi bi-list-ol me-1"></i>{{ $exam->questionsPerAttempt($exam->bank_size) }} questions, drawn from a bank of {{ $exam->bank_size }}</li>
                        <li><i class="bi bi-clock me-1"></i>{{ $exam->duration_minutes }} minutes</li>
                        <li><i class="bi bi-trophy me-1"></i>Passing score {{ $exam->passing_score }} / 1000</li>
                    </ul>
                    <div class="mt-auto">
                        @if ($open->has($exam->id))
                            <a href="{{ route('admin.exams.show', $exam) }}" class="btn btn-warning w-100"><i class="bi bi-play-circle me-1"></i>Resume exam</a>
                        @elseif ($exam->bank_size > 0)
                            <a href="{{ route('admin.exams.show', $exam) }}" class="btn btn-primary w-100"><i class="bi bi-pencil-square me-1"></i>Start exam</a>
                        @else
                            <button class="btn btn-outline-secondary w-100" disabled>No questions yet</button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12">
            <div class="alert alert-info mb-0">
                No exams are available yet.
                @can('manage-exams')
                    <a href="{{ route('admin.exams.manage.index') }}">Load the AZ-900 and AI-900 question banks</a> to get started.
                @endcan
            </div>
        </div>
    @endforelse
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-clock-history me-1"></i>My attempts</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr><th>Exam</th><th>Date</th><th>Time taken</th><th class="text-end">Correct</th><th class="text-end">Score</th><th>Result</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($mine as $a)
                    <tr>
                        <td><span class="fw-semibold">{{ $a->exam?->code }}</span></td>
                        <td class="small">{{ $a->started_at?->format('d M Y H:i') }}</td>
                        <td class="small">{{ $a->durationSeconds() !== null ? gmdate($a->durationSeconds() >= 3600 ? 'G:i:s' : 'i:s', $a->durationSeconds()) : '—' }}</td>
                        <td class="text-end">{{ $a->correct_count !== null ? $a->correct_count.' / '.$a->total_questions : '—' }}</td>
                        <td class="text-end fw-semibold">{{ $a->score ?? '—' }}</td>
                        <td>
                            @if ($a->isInProgress())
                                <span class="badge text-bg-warning">In progress</span>
                            @elseif ($a->passed)
                                <span class="badge text-bg-success">Pass</span>
                            @else
                                <span class="badge text-bg-danger">Fail</span>
                            @endif
                            @if ($a->status === \App\Models\Exams\ExamAttempt::STATUS_EXPIRED)
                                <span class="badge text-bg-light border" title="The time ran out">Timed out</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @if ($a->isInProgress())
                                <a href="{{ route('admin.exams.show', $a->exam_id) }}" class="btn btn-sm btn-outline-warning">Resume</a>
                            @else
                                <a href="{{ route('admin.exams.attempts.result', $a) }}" class="btn btn-sm btn-outline-primary">Score report</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">You have not taken an exam yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
