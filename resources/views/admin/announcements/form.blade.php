@extends('layouts.admin')

@section('title', $announcement->exists ? 'Edit Announcement' : 'New Announcement')

@section('content')
@php
    $isEdit = $announcement->exists;
    $action = $isEdit
        ? route('admin.announcements.update', $announcement)
        : route('admin.announcements.store');
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-megaphone-fill me-2 text-primary"></i>{{ $isEdit ? 'Edit' : 'New' }} Announcement
        </h4>
        <small class="text-muted">Shown on the employee home portal to everyone it targets</small>
    </div>
    <a href="{{ route('admin.announcements.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<form method="POST" action="{{ $action }}">
    @csrf
    @if($isEdit) @method('PUT') @endif

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-card-text me-1 text-primary"></i>Content
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" maxlength="200" required
                               class="form-control @error('title') is-invalid @enderror"
                               value="{{ old('title', $announcement->title) }}"
                               placeholder="e.g. Payroll cut-off moves to Thursday">
                        @error('title') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Title (Arabic)</label>
                        <input type="text" name="title_ar" maxlength="200" dir="rtl"
                               class="form-control @error('title_ar') is-invalid @enderror"
                               value="{{ old('title_ar', $announcement->title_ar) }}">
                        @error('title_ar') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Body <span class="text-danger">*</span></label>
                        <textarea name="body" rows="8" maxlength="20000" required
                                  class="form-control @error('body') is-invalid @enderror">{{ old('body', $announcement->body) }}</textarea>
                        <div class="form-text">
                            Plain text. The slider shows the first ~220 characters, so lead with the point.
                        </div>
                        @error('body') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Body (Arabic)</label>
                        <textarea name="body_ar" rows="6" maxlength="20000" dir="rtl"
                                  class="form-control @error('body_ar') is-invalid @enderror">{{ old('body_ar', $announcement->body_ar) }}</textarea>
                        @error('body_ar') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Link (optional)</label>
                            <input type="url" name="link_url" maxlength="500"
                                   class="form-control @error('link_url') is-invalid @enderror"
                                   value="{{ old('link_url', $announcement->link_url) }}"
                                   placeholder="https://…">
                            @error('link_url') <span class="invalid-feedback">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Link label</label>
                            <input type="text" name="link_label" maxlength="80"
                                   class="form-control"
                                   value="{{ old('link_label', $announcement->link_label) }}"
                                   placeholder="Read more">
                        </div>
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
                               {{ old('is_published', $announcement->is_published) ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="is_published">Published</label>
                        <div class="form-text">Unpublished announcements are drafts and appear to nobody.</div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="pinned" name="pinned" value="1"
                               {{ old('pinned', $announcement->pinned) ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="pinned">Pin to the top</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Severity</label>
                        <select name="severity" class="form-select">
                            @foreach(\App\Models\Announcement::SEVERITIES as $sev)
                                <option value="{{ $sev }}"
                                        @selected(old('severity', $announcement->severity) === $sev)>
                                    {{ ucfirst($sev) }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">Urgent gets the red treatment in the slider.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Publish at</label>
                        <input type="datetime-local" name="published_at"
                               class="form-control @error('published_at') is-invalid @enderror"
                               value="{{ old('published_at', $announcement->published_at?->format('Y-m-d\TH:i')) }}">
                        <div class="form-text">Leave blank to publish immediately. A future date schedules it.</div>
                        @error('published_at') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="form-label fw-semibold">Expires at</label>
                        <input type="datetime-local" name="expires_at"
                               class="form-control @error('expires_at') is-invalid @enderror"
                               value="{{ old('expires_at', $announcement->expires_at?->format('Y-m-d\TH:i')) }}">
                        <div class="form-text">Blank means it never expires. Set one and it drops off on its own.</div>
                        @error('expires_at') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-people me-1 text-primary"></i>Audience
                </div>
                <div class="card-body">
                    <select name="audience" id="audienceSelect" class="form-select mb-3">
                        <option value="all" @selected(old('audience', $announcement->audience) === 'all')>Everyone</option>
                        <option value="branch" @selected(old('audience', $announcement->audience) === 'branch')>One branch</option>
                        <option value="department" @selected(old('audience', $announcement->audience) === 'department')>One department</option>
                    </select>

                    <div id="branchWrap" class="mb-3" hidden>
                        <label class="form-label fw-semibold">Branch</label>
                        <select name="audience_branch_id" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}"
                                        @selected((string) old('audience_branch_id', $announcement->audience_branch_id) === (string) $branch->id)>
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
                                        @selected((string) old('audience_department_id', $announcement->audience_department_id) === (string) $dept->id)>
                                    {{ $dept->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-text mt-2">
                        People with no branch or department on their HR record always see
                        &ldquo;Everyone&rdquo; announcements, so a missing record never
                        leaves someone with a blank noticeboard.
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-save me-1"></i>{{ $isEdit ? 'Save Changes' : 'Create Announcement' }}
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
