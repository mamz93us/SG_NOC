@extends('layouts.admin')

@section('title', $exam->exists ? 'Exam — '.$exam->code : 'New exam')

@section('content')
<div class="mx-auto" style="max-width: 760px;">
    <a href="{{ route('admin.exams.manage.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Exams &amp; question banks</a>
    <h4 class="fw-bold mt-2 mb-3">{{ $exam->exists ? 'Exam settings — '.$exam->code : 'New exam' }}</h4>

    <form method="POST" action="{{ $exam->exists ? route('admin.exams.manage.update', $exam) : route('admin.exams.manage.store') }}" class="card shadow-sm border-0">
        @csrf
        @if ($exam->exists) @method('PUT') @endif
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">Code</label>
                <input name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $exam->code) }}" maxlength="30" required placeholder="AZ-104">
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-8">
                <label class="form-label">Title</label>
                <input name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $exam->title) }}" maxlength="200" required>
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3">{{ old('description', $exam->description) }}</textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label">Minutes</label>
                <input type="number" name="duration_minutes" class="form-control @error('duration_minutes') is-invalid @enderror" min="1" max="600" value="{{ old('duration_minutes', $exam->duration_minutes) }}" required>
                @error('duration_minutes')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label">Questions per attempt</label>
                <input type="number" name="question_count" class="form-control @error('question_count') is-invalid @enderror" min="0" max="500" value="{{ old('question_count', $exam->question_count) }}" required>
                <div class="form-text">Drawn at random, weighted by skill area. 0 = the whole bank.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Passing score (of 1000)</label>
                <input type="number" name="passing_score" class="form-control @error('passing_score') is-invalid @enderror" min="1" max="1000" value="{{ old('passing_score', $exam->passing_score) }}" required>
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $exam->is_active))>
                    <label class="form-check-label" for="is_active">Open — the team can take it</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="show_review" value="1" id="show_review" @checked(old('show_review', $exam->show_review))>
                    <label class="form-check-label" for="show_review">Show candidates the correct answers and explanations after they finish</label>
                </div>
                <div class="form-text">Turn this off to keep the bank unseen, e.g. for a final readiness test. They always see their score and skill-area breakdown.</div>
            </div>
        </div>
        <div class="card-footer bg-transparent d-flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a href="{{ route('admin.exams.manage.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>

    @if ($exam->exists)
        <form method="POST" action="{{ route('admin.exams.manage.destroy', $exam) }}" class="mt-4"
              onsubmit="return confirm('Delete {{ $exam->code }} with all its questions and every attempt and score? This cannot be undone.');">
            @csrf @method('DELETE')
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete exam, questions and results</button>
        </form>
    @endif
</div>
@endsection
