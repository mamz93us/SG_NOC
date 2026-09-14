@extends('layouts.admin')

@section('title', 'Answer a Question')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Answer a question</h4>
        <small class="text-muted">
            Published as a knowledge article. The assistant finds an article by how close its text is to what employees ask, so answer the question as they asked it.
        </small>
    </div>
    <a href="{{ route('admin.ai-assistant.knowledge-gaps.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Knowledge gaps
    </a>
</div>

@if ($errors->any())
    <div class="alert alert-danger d-flex gap-2 align-items-start">
        <i class="bi bi-x-circle-fill fs-5"></i>
        <ul class="mb-0 ps-3">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('admin.ai-assistant.knowledge-gaps.answer') }}" id="answerForm">
    @csrf
    @foreach($gaps as $gap)
        <input type="hidden" name="gap_ids[]" value="{{ $gap->id }}">
    @endforeach

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-chat-left-quote me-1 text-primary"></i>What employees asked
                </div>
                <ul class="list-group list-group-flush">
                    @foreach($gaps as $gap)
                        <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                            <span @if($gap->locale === 'ar') dir="rtl" @endif>{{ $gap->query_sample }}</span>
                            <span class="small text-muted text-nowrap">
                                {{ $gap->hit_count }}× · {{ $gap->last_seen_at?->diffForHumans() }}
                                @can('view-ai-conversations')
                                    @if($gap->last_conversation_id)
                                        · <a href="{{ route('admin.ai-assistant.conversations.show', $gap->last_conversation_id) }}" target="_blank" rel="noopener">conversation</a>
                                    @endif
                                @endcan
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-card-text me-1 text-primary"></i>The answer
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="title" class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" id="title" name="title" maxlength="200" required class="form-control"
                                   value="{{ old('title', Str::ucfirst($lead->locale === 'ar' ? '' : $lead->query_sample)) }}">
                        </div>
                        <div class="col-md-6">
                            <label for="title_ar" class="form-label fw-semibold">Title (Arabic)</label>
                            <input type="text" id="title_ar" name="title_ar" maxlength="200" dir="rtl" class="form-control"
                                   value="{{ old('title_ar', $lead->locale === 'ar' ? $lead->query_sample : '') }}">
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-end mb-1">
                            <label for="body" class="form-label fw-semibold mb-0">Answer (English) <span class="text-danger">*</span></label>
                            @if($canTranslate)
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-translate data-from="body_ar" data-to="body" data-lang="en">
                                    <i class="bi bi-translate me-1"></i>Translate from Arabic
                                </button>
                            @endif
                        </div>
                        <textarea id="body" name="body" rows="9" class="form-control">{{ old('body') }}</textarea>
                        <div class="form-text" data-translate-status="body">Markdown works: <code>## Headings</code> split a long answer into parts the assistant can find separately.</div>
                    </div>

                    <div>
                        <div class="d-flex justify-content-between align-items-end mb-1">
                            <label for="body_ar" class="form-label fw-semibold mb-0">Answer (Arabic)</label>
                            @if($canTranslate)
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-translate data-from="body" data-to="body_ar" data-lang="ar">
                                    <i class="bi bi-translate me-1"></i>Translate from English
                                </button>
                            @endif
                        </div>
                        <textarea id="body_ar" name="body_ar" rows="9" dir="rtl" class="form-control">{{ old('body_ar') }}</textarea>
                        <div class="form-text" data-translate-status="body_ar">Both languages are searched, so an Arabic question finds the Arabic answer.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-sliders me-1 text-primary"></i>Publishing</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="category" class="form-label fw-semibold">Category</label>
                        <input type="text" id="category" name="category" maxlength="50" class="form-control" value="{{ old('category') }}" placeholder="Left empty: the AI chooses one">
                    </div>

                    <label for="audienceSelect" class="form-label fw-semibold">Audience</label>
                    <select name="audience" id="audienceSelect" class="form-select mb-3">
                        <option value="all" @selected(old('audience', 'all') === 'all')>Everyone</option>
                        <option value="branch" @selected(old('audience') === 'branch')>One branch</option>
                        <option value="department" @selected(old('audience') === 'department')>One department</option>
                    </select>

                    <div id="branchWrap" class="mb-3" hidden>
                        <label for="branch" class="form-label fw-semibold">Branch</label>
                        <select name="audience_branch_id" id="branch" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('audience_branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div id="deptWrap" class="mb-3" hidden>
                        <label for="department" class="form-label fw-semibold">Department</label>
                        <select name="audience_department_id" id="department" class="form-select">
                            <option value="">Choose…</option>
                            @foreach($departments as $department)
                                <option value="{{ $department->id }}" @selected((string) old('audience_department_id') === (string) $department->id)>{{ $department->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-send-check me-1"></i>Publish the answer
                    </button>
                    <div class="small text-muted mt-2">
                        Closes the {{ $gaps->count() === 1 ? 'question' : $gaps->count().' wordings' }} above and shows how well the assistant now finds the answer for each.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
(function () {
    const form = document.getElementById('answerForm');

    const audience = document.getElementById('audienceSelect');
    const branchWrap = document.getElementById('branchWrap');
    const deptWrap = document.getElementById('deptWrap');
    function syncAudience() {
        branchWrap.hidden = audience.value !== 'branch';
        deptWrap.hidden = audience.value !== 'department';
    }
    audience.addEventListener('change', syncAudience);
    syncAudience();

    const url = @json(route('admin.ai-assistant.knowledge-gaps.translate'));
    const token = form.querySelector('input[name="_token"]').value;

    form.querySelectorAll('[data-translate]').forEach(function (button) {
        button.addEventListener('click', function () {
            const from = form.querySelector('[name="' + button.dataset.from + '"]');
            const to = form.querySelector('[name="' + button.dataset.to + '"]');
            const status = form.querySelector('[data-translate-status="' + button.dataset.to + '"]');

            if (!from.value.trim()) {
                status.textContent = 'Write the answer in the other language first.';
                return;
            }
            if (to.value.trim() && !confirm('Replace what is already written here?')) {
                return;
            }

            button.disabled = true;
            status.textContent = 'Translating…';

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({ text: from.value, to: button.dataset.lang }),
            })
                .then(function (response) {
                    return response.json().then(function (data) { return { ok: response.ok, data: data }; });
                })
                .then(function (result) {
                    if (!result.ok) {
                        throw new Error(result.data.message || 'The translation could not be made.');
                    }
                    to.value = result.data.text;
                    status.textContent = 'Translated. Read it through before publishing.';
                })
                .catch(function (error) {
                    status.textContent = error.message;
                })
                .finally(function () {
                    button.disabled = false;
                });
        });
    });
})();
</script>
@endpush
@endsection
