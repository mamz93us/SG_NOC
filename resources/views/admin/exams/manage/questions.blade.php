@extends('layouts.admin')

@section('title', $exam->code.' — Questions')

@section('content')
<a href="{{ route('admin.exams.manage.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Exams &amp; question banks</a>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mt-2 mb-3">
    <div>
        <h4 class="mb-0 fw-bold">{{ $exam->code }} question bank</h4>
        <small class="text-muted">{{ $exam->title }}</small>
    </div>
    <a href="{{ route('admin.exams.manage.questions.create', $exam) }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add question</a>
</div>

@if ($domains->isNotEmpty())
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-3 small">
                @php($active = $domains->sum('active'))
                @foreach ($domains as $d)
                    <a href="{{ route('admin.exams.manage.questions.index', [$exam, 'domain' => $d->domain]) }}" class="text-decoration-none">
                        {{ $d->domain }} <span class="badge text-bg-light border">{{ (int) $d->active }}@if ((int) $d->active !== (int) $d->n)/{{ $d->n }}@endif · {{ $active ? round($d->active * 100 / $active) : 0 }}%</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endif

<form class="row g-2 mb-3" method="GET">
    <div class="col-md-5">
        <select name="domain" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All skill areas</option>
            @foreach ($domains as $d)
                <option value="{{ $d->domain }}" @selected(request('domain') === $d->domain)>{{ $d->domain }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">On and off</option>
            <option value="active" @selected(request('status') === 'active')>On</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Off</option>
        </select>
    </div>
    <div class="col-md-4"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search question text…"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
</form>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th style="width: 55%;">Question</th><th>Skill area</th><th>Type</th><th>Answer</th><th></th></tr></thead>
            <tbody>
                @forelse ($questions as $q)
                    <tr class="{{ $q->is_active ? '' : 'opacity-50' }}">
                        <td>
                            <div class="small">{{ \Illuminate\Support\Str::limit($q->question, 220) }}</div>
                            <div class="small text-muted">
                                {{ $q->uid }}
                                @if ($q->hasArabic())<span class="badge text-bg-light border ms-1" title="Has an Arabic translation">AR</span>@else<span class="badge text-bg-warning ms-1" title="No complete Arabic translation — shown in English in Arabic sittings">EN only</span>@endif
                            </div>
                        </td>
                        <td class="small">{{ $q->domain }}</td>
                        <td class="small">{{ $q->isMultiple() ? 'Multiple' : 'Single' }}</td>
                        <td class="small fw-semibold">{{ implode(', ', $q->answer ?? []) }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('admin.exams.manage.questions.edit', [$exam, $q]) }}" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('admin.exams.manage.questions.toggle', [$exam, $q]) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-{{ $q->is_active ? 'warning' : 'success' }}" title="{{ $q->is_active ? 'Switch off' : 'Switch on' }}">
                                    <i class="bi bi-{{ $q->is_active ? 'pause' : 'play' }}"></i>
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.exams.manage.questions.destroy', [$exam, $q]) }}" class="d-inline"
                                  onsubmit="return confirm('Delete this question? Scores already given stay as they are.');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No questions match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $questions->links() }}</div>
@endsection
