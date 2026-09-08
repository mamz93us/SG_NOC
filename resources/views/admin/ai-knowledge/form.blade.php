@extends('layouts.admin')

@section('title', $article->exists ? 'Edit Article' : 'New Article')

@section('content')
@php
    $isEdit = $article->exists;
    $action = $isEdit
        ? route('admin.ai-assistant.knowledge.update', $article)
        : route('admin.ai-assistant.knowledge.store');
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-robot me-2 text-primary"></i>{{ $isEdit ? 'Edit' : 'New' }} Article
        </h4>
        <small class="text-muted">Searched and cited by the AI IT Assistant. Write in plain Markdown — headings become the retrieval chunks.</small>
    </div>
    <a href="{{ route('admin.ai-assistant.knowledge.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<form method="POST" action="{{ $action }}">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-card-text me-1 text-primary"></i>Details
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" maxlength="200" required
                               class="form-control @error('title') is-invalid @enderror"
                               value="{{ old('title', $article->title) }}"
                               placeholder="e.g. How to connect to the office VPN">
                        @error('title') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title (Arabic)</label>
                        <input type="text" name="title_ar" maxlength="200" dir="rtl"
                               class="form-control @error('title_ar') is-invalid @enderror"
                               value="{{ old('title_ar', $article->title_ar) }}">
                        @error('title_ar') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Body (Markdown) <span class="text-danger">*</span></label>
                        <textarea name="body" rows="14" required
                                  class="form-control font-monospace @error('body') is-invalid @enderror"
                                  placeholder="## Heading&#10;Answer text under this heading becomes one searchable chunk.">{{ old('body', $article->body) }}</textarea>
                        <div class="form-text">Use <code>## Headings</code> to split the article into topics — each becomes its own retrieval chunk.</div>
                        @error('body') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Body (Arabic)</label>
                        <textarea name="body_ar" rows="10" dir="rtl"
                                  class="form-control @error('body_ar') is-invalid @enderror"
                                  placeholder="اختياري — يُستخدم عند تصفح المستخدم بالعربية.">{{ old('body_ar', $article->body_ar) }}</textarea>
                        <div class="form-text">Optional. If set, the assistant searches and answers from this text for Arabic sessions.</div>
                        @error('body_ar') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-sliders me-1 text-primary"></i>Publishing
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="is_published" name="is_published" value="1"
                               {{ old('is_published', $article->is_published) ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="is_published">Published</label>
                        <div class="form-text">Unpublished articles are drafts — the assistant cannot search them, and saving one removes its chunks.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category</label>
                        <input type="text" name="category" maxlength="50"
                               class="form-control @error('category') is-invalid @enderror"
                               value="{{ old('category', $article->category) }}"
                               placeholder="e.g. vpn, printers, payroll">
                        @error('category') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label fw-semibold">Tags</label>
                        <input type="text" name="tags" class="form-control"
                               value="{{ old('tags', is_array($article->tags) ? implode(', ', $article->tags) : '') }}"
                               placeholder="comma, separated, tags">
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-people me-1 text-primary"></i>Audience
                </div>
                <div class="card-body">
                    <select name="audience" id="audienceSelect" class="form-select mb-3">
                        <option value="all" @selected(old('audience', $article->audience) === 'all')>Everyone</option>
                        <option value="branch" @selected(old('audience', $article->audience) === 'branch')>One branch</option>
                        <option value="department" @selected(old('audience', $article->audience) === 'department')>One department</option>
                    </select>

                    <div id="branchWrap" class="mb-3" hidden>
                        <label class="form-label fw-semibold">Branch</label>
                        <select name="audience_branch_id" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}"
                                        @selected((string) old('audience_branch_id', $article->audience_branch_id) === (string) $branch->id)>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div id="deptWrap" hidden>
                        <label class="form-label fw-semibold">Department</label>
                        <select name="audience_department_id" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($departments as $dept)
                                <option value="{{ $dept->id }}"
                                        @selected((string) old('audience_department_id', $article->audience_department_id) === (string) $dept->id)>
                                    {{ $dept->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-save me-1"></i>{{ $isEdit ? 'Save Changes' : 'Create Article' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
  var select = document.getElementById('audienceSelect');
  var branchWrap = document.getElementById('branchWrap');
  var deptWrap = document.getElementById('deptWrap');
  if (!select) return;

  function sync() {
    branchWrap.hidden = select.value !== 'branch';
    deptWrap.hidden = select.value !== 'department';
  }
  select.addEventListener('change', sync);
  sync();
})();
</script>
@endsection
