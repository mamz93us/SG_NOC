@extends('layouts.admin')

@section('title', 'AI Assistant Instructions')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-card-text me-2 text-primary"></i>AI Assistant Instructions</h4>
        <small class="text-muted">
            How the assistant is told to behave. For what it can search and cite, see
            <a href="{{ route('admin.ai-assistant.knowledge.index') }}">AI Assistant Knowledge</a>.
        </small>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success d-flex gap-2 align-items-start">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div>{{ session('success') }}</div>
    </div>
@endif

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header">
        <h6 class="mb-0 fw-semibold">Base instructions <span class="text-muted fw-normal">(fixed, not editable here)</span></h6>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            These rules ship with the app — things like "always search the knowledge base before
            answering", "never invent a policy", and "only draft a ticket after troubleshooting has
            failed" are enforced this way, and in code, so they can't be switched off by a stray edit.
            Changing them means changing <code>lang/en/home_ai.php</code> and
            <code>lang/ar/home_ai.php</code>, not this page.
        </p>
        <ul class="nav nav-tabs nav-tabs-sm" role="tablist">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#base-en" type="button">English</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#base-ar" type="button">العربية</button>
            </li>
        </ul>
        <div class="tab-content border border-top-0 rounded-bottom p-3 bg-light">
            <div class="tab-pane fade show active" id="base-en">
                <pre class="mb-0 small" style="white-space: pre-wrap;">{{ $basePromptEn }}</pre>
            </div>
            <div class="tab-pane fade" id="base-ar">
                <pre class="mb-0 small" dir="rtl" style="white-space: pre-wrap;">{{ $basePromptAr }}</pre>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header">
        <h6 class="mb-0 fw-semibold">Knowledge search sensitivity</h6>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            How closely a question has to match an article before the assistant treats it as a real
            answer instead of reporting "not found" and logging a gap. Lower = finds more, but risks
            citing a loosely related article; higher = safer, but sends more genuinely answered
            questions into a ticket draft. <strong>0.40</strong> is the current default — measured
            directly against real employee questions and this knowledge base: correct matches scored
            0.43-0.46, unrelated articles scored under 0.19. Retune this if the
            <a href="{{ route('admin.ai-assistant.usage') }}">Usage &amp; Gaps</a> log keeps showing
            questions that a published article already answers.
        </p>

        <form method="POST" action="{{ route('admin.ai-assistant.instructions.match-threshold') }}" class="d-flex align-items-end gap-2">
            @csrf
            <div>
                <label class="form-label small mb-1">Match threshold (0-1)</label>
                <input type="number" name="knowledge_match_threshold" class="form-control form-control-sm @error('knowledge_match_threshold') is-invalid @enderror"
                       style="width:120px" step="0.01" min="0" max="1"
                       value="{{ old('knowledge_match_threshold', $settings->knowledge_match_threshold) }}">
                @error('knowledge_match_threshold')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-save me-1"></i>Save Threshold
            </button>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header">
        <h6 class="mb-0 fw-semibold">Additional instructions</h6>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Appended to the base instructions above on every conversation — for things that change
            more often than the app's code does: a seasonal notice, a temporary escalation path, a
            reminder about a specific policy. Kept short and specific; this is not the place to paste
            a policy document — that belongs in
            <a href="{{ route('admin.ai-assistant.knowledge.index') }}">AI Assistant Knowledge</a>
            so it can be searched and cited instead of silently steering every answer.
        </p>

        <form method="POST" action="{{ route('admin.ai-assistant.instructions.update') }}">
            @csrf
            <textarea name="system_prompt_extra" class="form-control @error('system_prompt_extra') is-invalid @enderror"
                      rows="8" maxlength="4000"
                      placeholder="Optional — e.g. &quot;Ramadan working hours are 9am-3pm this month.&quot;">{{ old('system_prompt_extra', $settings->system_prompt_extra) }}</textarea>
            @error('system_prompt_extra')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror

            <button type="submit" class="btn btn-primary btn-sm mt-3">
                <i class="bi bi-save me-1"></i>Save Instructions
            </button>
        </form>
    </div>
</div>
@endsection
