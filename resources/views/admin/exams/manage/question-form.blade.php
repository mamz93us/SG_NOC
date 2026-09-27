@extends('layouts.admin')

@section('title', $exam->code.' — '.($question->exists ? 'Edit question' : 'New question'))

@php
    $options = old('options', $question->options ?? []);
    $answer = old('answer', $question->answer ?? []);
    $type = old('type', $question->type);
@endphp

@section('content')
<div class="mx-auto" style="max-width: 860px;">
    <a href="{{ route('admin.exams.manage.questions.index', $exam) }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>{{ $exam->code }} question bank</a>
    <h4 class="fw-bold mt-2 mb-3">{{ $question->exists ? 'Edit question' : 'New question' }}</h4>

    <form method="POST" action="{{ $question->exists ? route('admin.exams.manage.questions.update', [$exam, $question]) : route('admin.exams.manage.questions.store', $exam) }}" class="card shadow-sm border-0">
        @csrf
        @if ($question->exists) @method('PUT') @endif
        <div class="card-body row g-3">
            <div class="col-md-8">
                <label class="form-label">Skill area</label>
                <input name="domain" list="domain-options" class="form-control @error('domain') is-invalid @enderror" value="{{ old('domain', $question->domain) }}" required maxlength="200">
                <datalist id="domain-options">@foreach ($domainOptions as $d)<option value="{{ $d }}">@endforeach</datalist>
                @error('domain')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label">Type</label>
                <select name="type" class="form-select" id="q-type">
                    <option value="single" @selected($type === 'single')>Single answer</option>
                    <option value="multiple" @selected($type === 'multiple')>Multiple answers (choose two / three)</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Question</label>
                <textarea name="question" rows="4" class="form-control @error('question') is-invalid @enderror" required>{{ old('question', $question->question) }}</textarea>
                <div class="form-text">For a multiple-answer question, end with "(Choose two.)" as the real exam does.</div>
                @error('question')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label mb-1">Options <span class="text-muted small">— tick the correct one(s); leave unused rows empty</span></label>
                @error('options')<div class="text-danger small">{{ $message }}</div>@enderror
                @error('answer')<div class="text-danger small">{{ $message }}</div>@enderror
                @foreach ($keys as $key)
                    <div class="input-group mb-2">
                        <div class="input-group-text">
                            <input class="form-check-input mt-0 answer-box" type="{{ $type === 'multiple' ? 'checkbox' : 'radio' }}" name="answer[]" value="{{ $key }}"
                                   @checked(in_array($key, $answer, true)) aria-label="{{ $key }} is correct">
                            <span class="ms-2 fw-semibold">{{ $key }}</span>
                        </div>
                        <input name="options[{{ $key }}]" class="form-control" value="{{ $options[$key] ?? '' }}" maxlength="1000">
                    </div>
                @endforeach
            </div>
            <div class="col-12">
                <label class="form-label">Explanation <span class="text-muted small">(shown after the exam)</span></label>
                <textarea name="explanation" rows="3" class="form-control">{{ old('explanation', $question->explanation) }}</textarea>
            </div>
            <div class="col-md-8">
                <label class="form-label">Reference link</label>
                <input type="url" name="reference" class="form-control @error('reference') is-invalid @enderror" value="{{ old('reference', $question->reference) }}" placeholder="https://learn.microsoft.com/…">
                @error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4 d-flex flex-column justify-content-end">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="shuffle_options" value="1" id="shuffle" @checked(old('shuffle_options', $question->shuffle_options))>
                    <label class="form-check-label" for="shuffle">Shuffle options</label>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="active" @checked(old('is_active', $question->is_active))>
                    <label class="form-check-label" for="active">On (drawn in new attempts)</label>
                </div>
            </div>
        </div>
        <div class="card-footer bg-transparent d-flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a href="{{ route('admin.exams.manage.questions.index', $exam) }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
document.getElementById('q-type').addEventListener('change', function () {
    const multiple = this.value === 'multiple';
    document.querySelectorAll('.answer-box').forEach(b => { b.type = multiple ? 'checkbox' : 'radio'; });
});
</script>
@endsection
