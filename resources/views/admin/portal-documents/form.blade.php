@extends('layouts.admin')

@section('title', $document->exists ? 'Edit Document' : 'New Document')

@section('content')
@php
    $isEdit = $document->exists;
    $action = $isEdit
        ? route('admin.portal-documents.update', $document)
        : route('admin.portal-documents.store');
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-folder2-open me-2 text-primary"></i>{{ $isEdit ? 'Edit' : 'New' }} Document
        </h4>
        <small class="text-muted">Published to the employee home portal for everyone it targets</small>
    </div>
    <a href="{{ route('admin.portal-documents.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<form method="POST" action="{{ $action }}" enctype="multipart/form-data">
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
                               value="{{ old('title', $document->title) }}"
                               placeholder="e.g. Acceptable Use Policy">
                        @error('title') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title (Arabic)</label>
                        <input type="text" name="title_ar" maxlength="200" dir="rtl"
                               class="form-control @error('title_ar') is-invalid @enderror"
                               value="{{ old('title_ar', $document->title_ar) }}">
                        @error('title_ar') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" rows="3" maxlength="500"
                                  class="form-control @error('description') is-invalid @enderror"
                                  placeholder="One or two lines — this is the text under the title on the card.">{{ old('description', $document->description) }}</textarea>
                        @error('description') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-select">
                                @foreach(\App\Models\PortalDocument::CATEGORIES as $key => $label)
                                    <option value="{{ $key }}" @selected(old('category', $document->category) === $key)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                <strong>IT Policy</strong> lands behind the IT Policy card; everything
                                else behind Documentation &amp; Manuals.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Version</label>
                            <input type="text" name="version" maxlength="32"
                                   class="form-control @error('version') is-invalid @enderror"
                                   value="{{ old('version', $document->version) }}"
                                   placeholder="e.g. 2.1">
                            @error('version') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Effective date</label>
                            <input type="date" name="effective_date"
                                   class="form-control @error('effective_date') is-invalid @enderror"
                                   value="{{ old('effective_date', $document->effective_date?->format('Y-m-d')) }}">
                            @error('effective_date') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-paperclip me-1 text-primary"></i>The document itself
                </div>
                <div class="card-body">
                    <div class="alert alert-light border small mb-3">
                        A document is <strong>an upload, a YouTube video, or a link</strong> &mdash;
                        one of the three, and an upload wins if you fill in more than one.
                        Uploads are stored privately and can only be fetched by a signed-in employee
                        the document is published to; they are never a public URL.
                        <strong>PDFs, images and videos open inside the portal</strong>; anything else
                        downloads.
                    </div>

                    @if($document->isFile())
                        <div class="d-flex align-items-center gap-2 mb-3 p-2 border rounded bg-light">
                            <i class="bi bi-file-earmark-text fs-4 text-secondary"></i>
                            <div class="flex-grow-1">
                                <div class="fw-semibold small">{{ $document->file_name }}</div>
                                <div class="text-muted small">{{ $document->humanSize() }}</div>
                            </div>
                            <a href="{{ route('admin.portal-documents.download', $document) }}"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-download me-1"></i>Download
                            </a>
                        </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            {{ $document->isFile() ? 'Replace file' : 'Upload file' }}
                        </label>
                        <input type="file" name="file"
                               class="form-control @error('file') is-invalid @enderror">
                        <div class="form-text">
                            PDF, Word, Excel, PowerPoint, text, CSV, images or a zip. Up to 50&nbsp;MB.
                            @if($document->isFile())
                                Leave empty to keep the current file — a new one replaces and deletes it.
                            @endif
                        </div>
                        @error('file') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">…or a YouTube video</label>
                        <input type="url" name="video_url" maxlength="500"
                               class="form-control @error('video_url') is-invalid @enderror"
                               value="{{ old('video_url', $document->video_url) }}"
                               placeholder="https://www.youtube.com/watch?v=…">
                        <div class="form-text">
                            Paste whatever the Share button gave you — watch, <code>youtu.be</code>,
                            embed and shorts links all work. It plays embedded on the portal page.
                            Video is <strong>not uploaded</strong>: a large MP4 served by this server
                            to the whole company is the wrong trade when YouTube already does it.
                            @if($document->isVideo())
                                <br><span class="text-success">Currently embeds video id
                                <code>{{ $document->youtubeId() }}</code>.</span>
                            @endif
                        </div>
                        @error('video_url') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label fw-semibold">…or a link</label>
                        <input type="url" name="link_url" maxlength="500"
                               class="form-control @error('link_url') is-invalid @enderror"
                               value="{{ old('link_url', $document->link_url) }}"
                               placeholder="https://…">
                        <div class="form-text">
                            For anything hosted elsewhere — SharePoint, a vendor manual, a
                            non-YouTube video. Opens in a new tab rather than in the portal.
                        </div>
                        @error('link_url') <span class="invalid-feedback">{{ $message }}</span> @enderror
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
                               {{ old('is_published', $document->is_published) ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="is_published">Published</label>
                        <div class="form-text">Unpublished documents are drafts and appear to nobody.</div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="pinned" name="pinned" value="1"
                               {{ old('pinned', $document->pinned) ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="pinned">Pin to the top</label>
                        <div class="form-text">Pinned documents lead the list and carry a “Must read” tag.</div>
                    </div>

                    <div>
                        <label class="form-label fw-semibold">Sort order</label>
                        <input type="number" name="sort_order" min="0" max="9999"
                               class="form-control @error('sort_order') is-invalid @enderror"
                               value="{{ old('sort_order', $document->sort_order ?? 0) }}">
                        <div class="form-text">Lowest first within a category. Ties fall back to newest.</div>
                        @error('sort_order') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-people me-1 text-primary"></i>Audience
                </div>
                <div class="card-body">
                    <select name="audience" id="audienceSelect" class="form-select mb-3">
                        <option value="all" @selected(old('audience', $document->audience) === 'all')>Everyone</option>
                        <option value="branch" @selected(old('audience', $document->audience) === 'branch')>One branch</option>
                        <option value="department" @selected(old('audience', $document->audience) === 'department')>One department</option>
                    </select>

                    <div id="branchWrap" class="mb-3" hidden>
                        <label class="form-label fw-semibold">Branch</label>
                        <select name="audience_branch_id" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}"
                                        @selected((string) old('audience_branch_id', $document->audience_branch_id) === (string) $branch->id)>
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
                                        @selected((string) old('audience_department_id', $document->audience_department_id) === (string) $dept->id)>
                                    {{ $dept->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-text mt-2">
                        People with no branch or department on their HR record always see
                        &ldquo;Everyone&rdquo; documents, so a missing record never hides the
                        IT policy from them.
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-save me-1"></i>{{ $isEdit ? 'Save Changes' : 'Create Document' }}
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
