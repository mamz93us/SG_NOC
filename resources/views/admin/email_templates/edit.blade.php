@extends('layouts.admin')

@section('title', 'Email Template — '.$meta['label'])

@push('head')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/codemirror.min.css">
<style>
    .CodeMirror { height: 520px; font-size: 12.5px; border: 1px solid #dee2e6; border-radius: .375rem; }
    [data-bs-theme="dark"] .CodeMirror { border-color: #373b3e; }
    .token-chip { cursor: pointer; font-family: var(--bs-font-monospace); font-size: 11.5px; }
    #previewFrame { width: 100%; height: 520px; border: 1px solid #dee2e6; border-radius: .375rem; background: #fff; }
</style>
@endpush

@section('content')
<div class="container-fluid py-4">

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <a href="{{ route('admin.email-templates.index') }}" class="text-decoration-none small text-muted">
                <i class="bi bi-arrow-left me-1"></i>All email templates
            </a>
            <h4 class="mb-1 mt-1">{{ $meta['label'] }}</h4>
            <p class="text-muted mb-0 small">{{ $meta['description'] }}</p>
        </div>
        <div class="text-end small text-muted">
            Sends as <strong>{{ $senderLabel }}</strong><br>
            <span class="font-monospace">{{ $sender['address'] ?? '—' }}</span>
        </div>
    </div>

    @if(session('success'))
    <div class="alert alert-success py-2">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger py-2">{{ session('error') }}</div>
    @endif
    @if($renderError)
    <div class="alert alert-warning py-2 small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Could not render this template with sample data, so the preview and the "Load original design"
        button are unavailable. Placeholders below were read from the template file instead.
        <div class="font-monospace mt-1">{{ $renderError }}</div>
    </div>
    @endif

    <form method="POST" action="{{ route('admin.email-templates.update', $key) }}" id="templateForm">
        @csrf
        @method('PUT')

        <div class="row g-3">
            {{-- ── Editor ──────────────────────────────────────────── --}}
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span class="fw-semibold">Template</span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="loadOriginal"
                                    @disabled($renderError !== null)>
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Load original design
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="refreshPreview"
                                    @disabled($renderError !== null)>
                                <i class="bi bi-eye me-1"></i>Refresh preview
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Subject</label>
                            <input type="text" name="subject" class="form-control"
                                   value="{{ old('subject', $override->subject ?? '') }}"
                                   placeholder="{{ $codedSubject ?: 'Leave blank to keep the original subject' }}">
                            <div class="form-text">
                                Blank keeps the coded subject, currently:
                                <span class="font-monospace">{{ $codedSubject ?: '—' }}</span>.
                                Placeholders work here too and are flattened to plain text.
                            </div>
                        </div>

                        <label class="form-label fw-semibold">Body (HTML)</label>
                        <textarea name="body_html" id="bodyEditor" class="form-control">{{ old('body_html', $override->body_html ?? '') }}</textarea>
                        <div class="form-text">
                            Blank sends the original design. Start from it with <em>Load original design</em>,
                            then edit freely — this is a plain HTML document, not a Blade template, so nothing
                            you type here can run code.
                        </div>

                        <div class="form-check form-switch mt-3">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                   id="isActive" {{ old('is_active', $override?->is_active ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="isActive">
                                Use this template
                                <span class="text-muted small">— switch off to park the edit and send the original</span>
                            </label>
                        </div>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2 justify-content-between">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save
                        </button>
                        @if($override)
                        <button type="button" class="btn btn-outline-danger btn-sm"
                                onclick="document.getElementById('resetForm').submit()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Restore original & delete override
                        </button>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ── Preview ─────────────────────────────────────────── --}}
            <div class="col-lg-5">
                <div class="card mb-3">
                    <div class="card-header fw-semibold">Preview <span class="text-muted small fw-normal">— sample data</span></div>
                    <div class="card-body p-2">
                        <iframe id="previewFrame" name="previewFrame" title="Email preview"></iframe>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header fw-semibold">
                        Placeholders
                        <span class="text-muted small fw-normal">— click to insert at the cursor</span>
                    </div>
                    <div class="card-body">
                        @if(empty($fields))
                        <p class="text-muted small mb-0">
                            This email has no marked fields yet, so only the subject can be customised.
                        </p>
                        @else
                        <div class="d-flex flex-wrap gap-1">
                            @foreach($fields as $field)
                            @php $tok = '{'.'{ '.$field.' }'.'}'; @endphp
                            <span class="badge bg-secondary-subtle text-secondary-emphasis token-chip"
                                  data-token="{{ $tok }}">{{ $tok }}</span>
                            @endforeach
                        </div>
                        <p class="form-text mb-0 mt-2">
                            Each one is filled in from live data at send time. A placeholder for something
                            the email hides — an extension for someone who has none, a leaving reason that
                            was not given — comes through empty, so keep its label beside it rather than in
                            a separate fixed row. For the same reason, the original design loaded above only
                            shows the sections the sample data triggers; placeholders listed here but missing
                            from it can still be inserted.
                        </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- ── Test send ───────────────────────────────────────────────── --}}
    <div class="card mt-3">
        <div class="card-body">
            <form method="POST" action="{{ route('admin.email-templates.test', $key) }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-4">
                    <label class="form-label fw-semibold mb-1">Send a test</label>
                    <input type="email" name="test_email" class="form-control" required
                           placeholder="you@samirgroup.com" value="{{ auth()->user()->email ?? '' }}">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-send me-1"></i>Send test
                    </button>
                </div>
                <div class="col-md">
                    <div class="form-text mb-0">
                        Sends the <strong>saved</strong> version through the real sender address, with sample
                        data. Save first if you want to test what is on screen.
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Out-of-form helpers --}}
<form method="POST" action="{{ route('admin.email-templates.reset', $key) }}" id="resetForm" class="d-none">
    @csrf
    @method('DELETE')
</form>

<form method="POST" action="{{ route('admin.email-templates.preview', $key) }}"
      id="previewForm" target="previewFrame" class="d-none">
    @csrf
    <textarea name="body_html" id="previewBody"></textarea>
</form>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/mode/htmlmixed/htmlmixed.min.js"></script>
<script>
const SEED = @json($seedBody);

const editor = CodeMirror.fromTextArea(document.getElementById('bodyEditor'), {
    mode: 'htmlmixed',
    lineNumbers: true,
    lineWrapping: true,
    tabSize: 2,
});

// Keep the textarea in sync so a plain form submit carries the edits.
document.getElementById('templateForm').addEventListener('submit', () => editor.save());

document.getElementById('loadOriginal')?.addEventListener('click', () => {
    if (editor.getValue().trim() && !confirm('Replace the editor contents with the original design?')) return;
    editor.setValue(SEED);
    refreshPreview();
});

function refreshPreview() {
    document.getElementById('previewBody').value = editor.getValue();
    document.getElementById('previewForm').submit();
}
document.getElementById('refreshPreview')?.addEventListener('click', refreshPreview);

document.querySelectorAll('.token-chip').forEach(chip => {
    chip.addEventListener('click', () => {
        editor.replaceSelection(chip.dataset.token);
        editor.focus();
    });
});

// Show the current state as soon as the page settles.
@if($renderError === null)
refreshPreview();
@endif
</script>
@endpush
