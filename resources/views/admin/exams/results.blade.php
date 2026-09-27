@extends('layouts.admin')

@section('title', 'Exams — Team results')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Team results</h4>
        <small class="text-muted">Who is ready to book the real exam: best and latest score per person.</small>
    </div>
    <a href="{{ route('admin.exams.results.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>CSV</a>
</div>

<form class="row g-2 mb-3" method="GET">
    <div class="col-md-3">
        <select name="exam" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All exams</option>
            @foreach ($exams as $e)
                <option value="{{ $e->id }}" @selected($examId === $e->id)>{{ $e->code }} — {{ $e->title }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <select name="result" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Any result</option>
            <option value="pass" @selected(request('result') === 'pass')>Pass</option>
            <option value="fail" @selected(request('result') === 'fail')>Fail</option>
            <option value="open" @selected(request('result') === 'open')>In progress</option>
        </select>
    </div>
    <div class="col-md-4"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Name or email…"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
</form>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent fw-semibold">By person</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Person</th><th>Exam</th><th class="text-end">Attempts</th><th class="text-end">Best</th><th class="text-end">Latest</th><th>Last taken</th><th>Ready?</th></tr></thead>
            <tbody>
                @forelse ($summary as $s)
                    @php($exam = $exams[$s['exam_id']] ?? null)
                    @php($pass = $exam?->passing_score ?? 700)
                    <tr>
                        <td>{{ $users[$s['user_id']]->name ?? '#'.$s['user_id'] }}<div class="small text-muted">{{ $users[$s['user_id']]->email ?? '' }}</div></td>
                        <td class="fw-semibold">{{ $exam?->code }}</td>
                        <td class="text-end">{{ $s['attempts'] }}</td>
                        <td class="text-end fw-semibold {{ $s['best'] >= $pass ? 'text-success' : 'text-danger' }}">{{ $s['best'] }}</td>
                        <td class="text-end"><a href="{{ route('admin.exams.attempts.result', $s['latest_id']) }}">{{ $s['latest'] }}</a></td>
                        <td class="small">{{ $s['last_at']?->format('d M Y') }}</td>
                        <td>
                            {{-- Ready = the latest attempt passed with some margin, not one lucky pass long ago. --}}
                            @if ($s['latest'] >= $pass + 100)
                                <span class="badge text-bg-success">Ready</span>
                            @elseif ($s['latest'] >= $pass)
                                <span class="badge text-bg-warning">Borderline</span>
                            @else
                                <span class="badge text-bg-secondary">Not yet</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No finished attempts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent small text-muted">Ready = latest score at least 100 above the pass mark. Borderline = passed, by less than that.</div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent fw-semibold">Every attempt</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Person</th><th>Exam</th><th>Language</th><th>Started</th><th>Time taken</th><th class="text-end">Correct</th><th class="text-end">Score</th><th>Result</th><th></th></tr></thead>
            <tbody>
                @forelse ($attempts as $a)
                    <tr>
                        <td>{{ $a->user?->name }}</td>
                        <td class="fw-semibold">{{ $a->exam?->code }}</td>
                        <td class="small">{{ \App\Models\Exams\ExamAttempt::LANGUAGES[$a->language] ?? $a->language }}</td>
                        <td class="small">{{ $a->started_at?->format('d M Y H:i') }}</td>
                        <td class="small">{{ $a->durationSeconds() !== null ? gmdate($a->durationSeconds() >= 3600 ? 'G:i:s' : 'i:s', $a->durationSeconds()) : '—' }}</td>
                        <td class="text-end">{{ $a->correct_count !== null ? $a->correct_count.' / '.$a->total_questions : $a->answeredCount().' answered' }}</td>
                        <td class="text-end fw-semibold">{{ $a->score ?? '—' }}</td>
                        <td>
                            @if ($a->isInProgress())
                                <span class="badge text-bg-warning">In progress</span>
                            @elseif ($a->passed)
                                <span class="badge text-bg-success">Pass</span>
                            @else
                                <span class="badge text-bg-danger">Fail</span>
                            @endif
                            @if ($a->status === \App\Models\Exams\ExamAttempt::STATUS_EXPIRED)<span class="badge text-bg-light border">Timed out</span>@endif
                        </td>
                        <td class="text-end">
                            @unless ($a->isInProgress())
                                <a href="{{ route('admin.exams.attempts.result', $a) }}" class="btn btn-sm btn-outline-primary">Report</a>
                                <a href="{{ route('admin.exams.attempts.answers', $a) }}" class="btn btn-sm btn-outline-secondary" title="Answers"><i class="bi bi-journal-check"></i></a>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No attempts match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $attempts->links() }}</div>
@endsection
