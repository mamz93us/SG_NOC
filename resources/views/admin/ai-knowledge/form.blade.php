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
            @if($isEdit && $article->import)
                @php $import = $article->import; @endphp
                <div class="alert alert-info d-flex gap-2 align-items-start">
                    <i class="bi bi-file-earmark-pdf fs-5"></i>
                    <div>
                        Imported from
                        <a href="{{ route('admin.ai-assistant.knowledge.imports.file', $import) }}" target="_blank" rel="noopener" class="fw-semibold">{{ $import->file_name }}</a>
                        ({{ $import->page_count }} {{ Str::plural('page', (int) $import->page_count) }}@if($import->sourceLanguageName()), {{ $import->sourceLanguageName() }}@endif)
                        {{ $import->finished_at ? 'on '.$import->finished_at->format('j M Y') : '' }}.
                        @if($import->source_language === 'en')
                            The body is the text read from the PDF — check it against the original before publishing.
                        @else
                            The English body is a machine translation{{ $import->source_language === 'ar' ? ', and the Arabic body is the text read from the PDF' : '' }}.
                            Check it against the original before publishing, especially figures, dates and entitlements: employees will get answers from it.
                        @endif
                    </div>
                </div>
            @endif

            @if($isEdit && $article->webPage)
                @php $webPage = $article->webPage; @endphp
                <div class="alert alert-info d-flex gap-2 align-items-start">
                    <i class="bi bi-globe2 fs-5"></i>
                    <div>
                        Read from
                        <a href="{{ $webPage->url }}" target="_blank" rel="noopener noreferrer" class="fw-semibold text-break">{{ $webPage->url }}</a>
                        {{ $webPage->fetched_at ? 'on '.$webPage->fetched_at->format('j M Y') : '' }}, part of
                        <a href="{{ route('admin.ai-assistant.knowledge.websites.show', $webPage->source_id) }}">{{ $webPage->source?->name }}</a>.
                        When the page changes on the site, the next read replaces this article, so correct it there rather than here.
                        @if($webPage->language && $webPage->language !== 'en')
                            The English body is a machine translation.
                        @endif
                    </div>
                </div>
            @endif

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
                        <label for="articleCategory" class="form-label fw-semibold">Category</label>
                        <input type="text" id="articleCategory" name="category" maxlength="50"
                               class="form-control @error('category') is-invalid @enderror"
                               value="{{ old('category', $article->category) }}"
                               placeholder="e.g. vpn, printers, payroll">
                        @error('category') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="articleTags" class="form-label fw-semibold">Tags</label>
                        <input type="text" id="articleTags" name="tags" class="form-control"
                               value="{{ old('tags', is_array($article->tags) ? implode(', ', $article->tags) : '') }}"
                               placeholder="comma, separated, tags">
                    </div>

                    <div class="mt-3">
                        <button type="button" id="suggestFiling" class="btn btn-outline-primary btn-sm"
                                data-url="{{ route('admin.ai-assistant.knowledge.classify-suggest') }}">
                            <i class="bi bi-magic me-1"></i>Suggest with AI
                        </button>
                        <div class="form-text" id="suggestFilingStatus">
                            @if($isEdit && $article->ai_classify)
                                Waiting for the AI to fill these in.
                            @elseif($isEdit && $article->ai_classify_error)
                                <span class="text-danger">The AI could not file this article: {{ $article->ai_classify_error }}</span>
                            @elseif($isEdit && $article->ai_classified_at)
                                Last filled in by the AI {{ $article->ai_classified_at->diffForHumans() }}.
                            @else
                                Reads the title and body and fills in both. Left empty, the AI fills them in after saving.
                            @endif
                        </div>
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

(function () {
  var button = document.getElementById('suggestFiling');
  if (!button) return;

  var form = button.closest('form');
  var status = document.getElementById('suggestFilingStatus');
  var category = document.getElementById('articleCategory');
  var tags = document.getElementById('articleTags');

  button.addEventListener('click', function () {
    var title = form.querySelector('[name="title"]').value.trim();
    var body = form.querySelector('[name="body"]').value.trim();

    if (!title || !body) {
      status.textContent = 'Write the title and body first.';
      return;
    }
    if ((category.value.trim() || tags.value.trim()) && !confirm('Replace the category and tags already filled in?')) {
      return;
    }

    button.disabled = true;
    status.textContent = 'Reading the article…';

    fetch(button.dataset.url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('input[name="_token"]').value },
      body: JSON.stringify({ title: title, title_ar: form.querySelector('[name="title_ar"]').value, body: body })
    })
      .then(function (response) {
        return response.json().then(function (data) { return { ok: response.ok, data: data }; });
      })
      .then(function (result) {
        if (!result.ok) {
          throw new Error(result.data.message || 'The AI could not suggest a category and tags.');
        }
        category.value = result.data.category;
        tags.value = result.data.tags.join(', ');
        status.textContent = 'Filled in. Check them, then save.';
      })
      .catch(function (error) {
        status.textContent = error.message;
      })
      .finally(function () {
        button.disabled = false;
      });
  });
})();
</script>
@endsection
