@extends('layouts.admin')

@section('title', 'Exams — Manage')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-collection me-2 text-primary"></i>Exams &amp; question banks</h4>
        <small class="text-muted">The banks hold the answers — only people with Manage Exams see this page.</small>
    </div>
    <a href="{{ route('admin.exams.manage.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New exam</a>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr><th>Exam</th><th class="text-end">Questions</th><th class="text-end">Per attempt</th><th class="text-end">Minutes</th><th class="text-end">Pass</th><th class="text-end">Attempts</th><th class="text-end">Pass rate</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($exams as $exam)
                    <tr>
                        <td><span class="fw-semibold">{{ $exam->code }}</span><div class="small text-muted">{{ $exam->title }}</div></td>
                        <td class="text-end">{{ $exam->active_questions_count }}@if ($exam->questions_count !== $exam->active_questions_count)<span class="text-muted small"> / {{ $exam->questions_count }}</span>@endif</td>
                        <td class="text-end">{{ $exam->questionsPerAttempt($exam->active_questions_count) }}</td>
                        <td class="text-end">{{ $exam->duration_minutes }}</td>
                        <td class="text-end">{{ $exam->passing_score }}</td>
                        <td class="text-end">{{ $exam->finished_count }}</td>
                        <td class="text-end">{{ $exam->finished_count ? round($exam->passed_count * 100 / $exam->finished_count).'%' : '—' }}</td>
                        <td>
                            <span class="badge {{ $exam->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $exam->is_active ? 'Open' : 'Closed' }}</span>
                            @unless ($exam->show_review)<span class="badge text-bg-light border" title="Candidates do not see the correct answers">Answers hidden</span>@endunless
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('admin.exams.manage.questions.index', $exam) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-check me-1"></i>Questions</a>
                            <a href="{{ route('admin.exams.manage.edit', $exam) }}" class="btn btn-sm btn-outline-secondary" title="Settings"><i class="bi bi-gear"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No exams yet — load the bundled banks below.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-box-seam me-1"></i>Bundled question banks</div>
            <div class="card-body">
                <p class="small text-muted">Original practice questions written against Microsoft's published skills outline. Loading is safe to repeat:
                    it adds new questions and updates wording, never deletes a question, never switches one back on, and leaves an existing exam's settings alone.</p>
                <ul class="small">
                    @forelse ($bundled as $b)
                        <li><strong>{{ $b['code'] }}</strong> {{ $b['title'] }} — {{ $b['questions'] }} questions <span class="text-muted">({{ $b['file'] }})</span></li>
                    @empty
                        <li class="text-muted">None found in database/data/exams.</li>
                    @endforelse
                </ul>
                <form method="POST" action="{{ route('admin.exams.manage.load-bundled') }}">
                    @csrf
                    <button class="btn btn-sm btn-primary" @disabled($bundled->isEmpty())><i class="bi bi-download me-1"></i>Load bundled banks</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-upload me-1"></i>Import a bank (JSON)</div>
            <div class="card-body">
                <p class="small text-muted mb-2">Same format as the bundled files. Questions are matched on their <code>uid</code>, so importing a corrected file updates them in place.</p>
<pre class="small bg-body-tertiary rounded p-2 mb-3">{"exam": {"code": "AZ-104", "title": "…", "duration_minutes": 100,
          "question_count": 50, "passing_score": 700},
 "questions": [{"uid": "az104-001", "domain": "…", "type": "single",
                "question": "…", "options": {"A": "…", "B": "…"},
                "answer": ["B"], "explanation": "…"}]}</pre>
                <form method="POST" action="{{ route('admin.exams.manage.import') }}" enctype="multipart/form-data" class="d-flex gap-2">
                    @csrf
                    <input type="file" name="bank" accept=".json,application/json" class="form-control form-control-sm" required>
                    <button class="btn btn-sm btn-outline-primary text-nowrap">Import</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
