@extends('layouts.admin')

@push('head')
{{-- CodeMirror 5 --}}
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/theme/dracula.min.css">
{{-- Google Fonts — for the editor + web mail clients only. NOTE: Outlook desktop
     ignores webfonts, so each entry in font_family_formats has a web-safe fallback. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&family=Lato:wght@400;700&family=Montserrat:wght@400;600&family=Open+Sans:wght@400;600;700&family=Poppins:wght@400;600&family=Roboto:wght@400;500;700&display=swap">
{{-- Uploaded (self-hosted) signature fonts — @font-face generated from the Assets manager --}}
<link rel="stylesheet" href="{{ route('admin.signatures.fonts-css') }}">
<style>
    .CodeMirror { height: 400px; font-size: 13px; border: 1px solid #dee2e6; border-radius: 0 0 .375rem .375rem; }
    [data-bs-theme="dark"] .CodeMirror { border-color: #373b3e; }
    .cm-tab-bar { display:flex; border: 1px solid #dee2e6; border-bottom:none; border-radius: .375rem .375rem 0 0; overflow:hidden; }
    [data-bs-theme="dark"] .cm-tab-bar { border-color: #373b3e; }
    .cm-tab-btn { padding: 6px 14px; font-size: 12px; border: none; background: transparent; cursor: pointer; color: inherit; font-weight: 500; }
    .cm-tab-btn.active { background: rgba(var(--bs-primary-rgb),.1); color: var(--bs-primary); border-bottom: 2px solid var(--bs-primary); }
    #previewPane { min-height: 120px; }
    .var-chip { font-family: monospace; font-size: 11px; cursor: pointer; margin: 2px; border: 1px solid #dee2e6 !important; }
    .var-chip:hover { background: #d81f2a !important; color: white !important; border-color: #d81f2a !important; }
    .sticky-actions { position: sticky; bottom: 0; background: var(--bs-body-bg); border-top: 1px solid var(--bs-border-color); padding: 12px 0; margin-top: 24px; z-index: 10; }
</style>
@endpush

@section('content')

@php
    $isEdit = $template !== null;
    $formAction = $isEdit
        ? route('admin.signatures.update', $template)
        : route('admin.signatures.store');
@endphp

<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">{{ $isEdit ? 'Edit Signature Template' : 'New Signature Template' }}</h1>
        <small class="text-muted">{{ $isEdit ? $template->name : 'Design and save a branded HTML email signature' }}</small>
    </div>
    <div class="ms-auto">
        <a href="{{ route('admin.signatures.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

<form method="POST" action="{{ $formAction }}" id="sigForm">
    @csrf
    @if($isEdit) @method('PUT') @endif

    {{-- ── Top metadata row ── --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Template Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name', $template?->name) }}" placeholder="e.g. SamirGroup — New Email" required>
                    @error('name') <span class="invalid-feedback">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Domain</label>
                    <input type="text" name="domain" list="domainList" autocomplete="off"
                           class="form-control @error('domain') is-invalid @enderror"
                           value="{{ old('domain', $template?->domain) }}"
                           placeholder="All domains (catch-all)">
                    <datalist id="domainList">
                        @foreach($domains as $d)
                            <option value="{{ $d->domain }}">{{ $d->is_primary ? '(primary)' : '' }}</option>
                        @endforeach
                    </datalist>
                    @error('domain') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    <div class="form-text">
                        Sender domain, e.g. <code>samirgroup.com</code> or <code>sssegypt.com</code>. Blank = all domains.
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Signature Slot <span class="text-danger">*</span></label>
                    <select name="type" class="form-select @error('type') is-invalid @enderror" required>
                        <option value="new_email" {{ old('type', $template?->type) === 'new_email' ? 'selected' : '' }}>New email (main)</option>
                        <option value="reply"     {{ old('type', $template?->type) === 'reply'     ? 'selected' : '' }}>Reply / forward</option>
                        <option value="all"       {{ old('type', $template?->type ?? 'all') === 'all' ? 'selected' : '' }}>Both slots</option>
                    </select>
                    @error('type') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    <div class="form-text">Which Outlook slot this fills</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Gender</label>
                    <select name="gender" class="form-select @error('gender') is-invalid @enderror" required>
                        <option value="all"    {{ old('gender', $template?->gender ?? 'all') === 'all'    ? 'selected' : '' }}>All / any</option>
                        <option value="male"   {{ old('gender', $template?->gender) === 'male'   ? 'selected' : '' }}>Male</option>
                        <option value="female" {{ old('gender', $template?->gender) === 'female' ? 'selected' : '' }}>Female</option>
                    </select>
                    @error('gender') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    <div class="form-text">Applies to this gender</div>
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-semibold">Sort</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="{{ old('sort_order', $template?->sort_order ?? 0) }}" min="0" max="9999">
                </div>
                <div class="col-md-1 d-flex flex-column">
                    <label class="form-label fw-semibold">Active</label>
                    <div class="form-check form-switch mt-1">
                        {{-- Hidden 0 fallback so unchecking actually posts a value (see SignatureController::validated) --}}
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
                               {{ old('is_active', $template?->is_active ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="isActive"></label>
                    </div>
                </div>

                {{-- Row 2 --}}
                <div class="col-md-7">
                    <label class="form-label fw-semibold">Logo URL</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-image"></i></span>
                        <input type="url" name="logo_url" id="logoUrl" class="form-control @error('logo_url') is-invalid @enderror"
                               value="{{ old('logo_url', $template?->logo_url) }}"
                               placeholder="https://…/logo.png">
                        <button class="btn btn-outline-secondary" type="button" id="logoPickBtn" title="Choose from uploaded images">
                            <i class="bi bi-images"></i>
                        </button>
                        <button class="btn btn-outline-secondary" type="button" onclick="testLogoUrl()" title="Open URL in new tab">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </button>
                    </div>
                    @error('logo_url') <span class="invalid-feedback">{{ $message }}</span> @enderror
                    <div class="form-text">Inserted wherever you place <code class="text-danger">&#123;&#123;logo_url&#125;&#125;</code> in the template</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Brand Colour</label>
                    <div class="input-group">
                        <input type="color" name="primary_color" id="primaryColor" class="form-control form-control-color"
                               value="{{ old('primary_color', $template?->primary_color ?? '#d81f2a') }}"
                               style="width:54px; flex: 0 0 54px;">
                        <input type="text" id="primaryColorHex" class="form-control font-monospace"
                               value="{{ old('primary_color', $template?->primary_color ?? '#d81f2a') }}"
                               placeholder="#d81f2a" maxlength="7">
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="d-flex gap-2 w-100">
                        <input type="text" id="previewUpnInput" class="form-control form-control-sm"
                               placeholder="UPN or email (blank = sample)" title="Preview with a real employee">
                        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" onclick="runPreview()">
                            <i class="bi bi-eye me-1"></i>Preview
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Editor + preview columns ── --}}
    <div class="row g-4">

        {{-- Left: editor --}}
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 pb-0">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="fw-semibold">Template Editor</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="formatHtml()">
                            <i class="bi bi-magic me-1"></i>Format
                        </button>
                    </div>

                    {{-- Variable inserter chips --}}
                    <div class="mb-3">
                        <div class="d-flex flex-wrap gap-1 mb-1 align-items-center">
                            <span class="text-muted" style="font-size:11px; min-width:58px;">Employee:</span>
                            @foreach(['name','first_name','job_title','department','company','email','phone','mobile','extension'] as $v)
                            <button type="button" class="badge var-chip bg-light text-dark"
                                    data-var="{{ $v }}" onclick="insertVarBtn(this)">&#123;&#123;{{ $v }}&#125;&#125;</button>
                            @endforeach
                        </div>
                        <div class="d-flex flex-wrap gap-1 mb-1 align-items-center">
                            <span class="text-muted" style="font-size:11px; min-width:58px;">Branch:</span>
                            @foreach(['branch_name','branch_city','branch_address','branch_phone'] as $v)
                            <button type="button" class="badge var-chip bg-light text-dark"
                                    data-var="{{ $v }}" onclick="insertVarBtn(this)">&#123;&#123;{{ $v }}&#125;&#125;</button>
                            @endforeach
                        </div>
                        <div class="d-flex flex-wrap gap-1 align-items-center">
                            <span class="text-muted" style="font-size:11px; min-width:58px;">Meta:</span>
                            @foreach(['logo_url','primary_color','year'] as $v)
                            <button type="button" class="badge var-chip bg-light text-dark"
                                    data-var="{{ $v }}" onclick="insertVarBtn(this)">&#123;&#123;{{ $v }}&#125;&#125;</button>
                            @endforeach
                            <button type="button" class="badge bg-warning text-dark var-chip"
                                    onclick="insertIfBlock()" style="border:1px solid #dee2e6!important;">
                                &#123;&#123;#if …&#125;&#125;&nbsp;block
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-body pt-0 pb-0">
                    {{-- Tab bar --}}
                    <div class="cm-tab-bar">
                        <button type="button" class="cm-tab-btn active" onclick="switchTab('visual', this)">
                            <i class="bi bi-brush me-1"></i>Visual
                        </button>
                        <button type="button" class="cm-tab-btn" onclick="switchTab('html', this)">
                            <i class="bi bi-code-slash me-1"></i>HTML
                        </button>
                        <button type="button" class="cm-tab-btn" onclick="switchTab('plain', this)">Plain Text</button>
                    </div>

                    {{-- Visual (WYSIWYG) — seeded from the same HTML, kept in sync with the HTML tab --}}
                    <div id="cmVisualPane">
                        <textarea id="visualEditor">{{ old('html_body', $template?->html_body ?? $default) }}</textarea>
                    </div>
                    <div id="cmHtmlPane" style="display:none;">
                        <textarea id="htmlEditor" name="html_body">{{ old('html_body', $template?->html_body ?? $default) }}</textarea>
                    </div>
                    <div id="cmPlainPane" style="display:none;">
                        <textarea id="plainEditor" name="plain_text_body">{{ old('plain_text_body', $template?->plain_text_body) }}</textarea>
                    </div>
                </div>
                <div class="card-footer bg-transparent border-0 pt-1 pb-2">
                    <small class="text-muted">
                        <kbd>Tab</kbd> indent &nbsp;|&nbsp;
                        <kbd>Ctrl+Z</kbd> undo &nbsp;|&nbsp;
                        Click a chip above to insert a variable at the cursor
                    </small>
                </div>
            </div>
        </div>

        {{-- Right: live preview --}}
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span class="fw-semibold">Live Preview</span>
                    <div class="d-flex gap-2 align-items-center">
                        {{-- Which Outlook slot to preview --}}
                        <div class="btn-group btn-group-sm" role="group" aria-label="Preview slot">
                            <input type="radio" class="btn-check" name="previewSlot" id="slotNew" value="new_email" checked
                                   onchange="setPreviewSlot('new_email')">
                            <label class="btn btn-outline-primary" for="slotNew">New email</label>
                            <input type="radio" class="btn-check" name="previewSlot" id="slotReply" value="reply"
                                   onchange="setPreviewSlot('reply')">
                            <label class="btn btn-outline-primary" for="slotReply">Reply</label>
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="autoPreview" checked>
                            <label class="form-check-label small" for="autoPreview">Auto-refresh</label>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="runPreview()">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    {{-- Simulated email frame --}}
                    <div style="border:1px solid #e0e0e0; border-radius:6px; overflow:hidden; background:#fff;">
                        <div style="background:#f5f5f5; padding:10px 16px; font-size:12px; color:#888; border-bottom:1px solid #e0e0e0;">
                            <strong style="color:#333;">To:</strong> customer@example.com &nbsp;&nbsp;
                            <strong style="color:#333;">Subject:</strong> Meeting follow-up
                        </div>
                        <div style="padding: 18px 20px; font-size:13px; color:#333; line-height:1.6;">
                            <p style="margin:0 0 18px;">Dear Ahmed,<br><br>
                            Thank you for your time today. Please find the summary attached.<br><br>
                            Best regards,</p>
                            {{-- Rendered signature injected here --}}
                            <div id="previewPane">
                                <span class="text-muted small">Click <strong>Preview</strong> or start typing to see the rendered signature.</span>
                            </div>
                        </div>
                    </div>

                    <div id="previewBadges" class="mt-2 d-none">
                        <span id="previewBadge" class="badge bg-secondary"></span>
                    </div>
                </div>
            </div>

            {{-- Reference card (collapsed by default) --}}
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-transparent border-0 py-2 d-flex align-items-center justify-content-between"
                     role="button" data-bs-toggle="collapse" data-bs-target="#varRefCard" aria-expanded="false">
                    <span class="small fw-semibold text-muted"><i class="bi bi-question-circle me-1"></i>Template Syntax Reference</span>
                    <i class="bi bi-chevron-down text-muted small"></i>
                </div>
                <div class="collapse" id="varRefCard">
                    <div class="card-body pt-0" style="font-size:12px;">
                        <p class="mb-1"><strong>Simple substitution:</strong> <code class="text-danger">&#123;&#123;name&#125;&#125;</code> → employee's display name</p>
                        <p class="mb-1"><strong>Conditional block:</strong></p>
                        <pre class="bg-light p-2 rounded mb-1" style="font-size:11px;">&#123;&#123;#if mobile&#125;&#125;
  &lt;span&gt;&#123;&#123;mobile&#125;&#125;&lt;/span&gt;
&#123;&#123;/if&#125;&#125;</pre>
                        <p class="mb-0 text-muted">Block is removed entirely when the variable is empty — no blank lines in output.</p>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- ── Sticky save bar ── --}}
    <div class="sticky-actions">
        <div class="d-flex gap-2 align-items-center">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-circle me-1"></i>{{ $isEdit ? 'Update Template' : 'Save Template' }}
            </button>
            <a href="{{ route('admin.signatures.index') }}" class="btn btn-outline-secondary">Cancel</a>
            @if($isEdit)
            <div class="ms-auto">
                <button type="button" class="btn btn-outline-danger btn-sm"
                        onclick="if(confirm('Delete this template?')) document.getElementById('deleteForm').submit()">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
            </div>
            @endif
        </div>
    </div>
</form>

@if($isEdit)
<form id="deleteForm" method="POST" action="{{ route('admin.signatures.destroy', $template) }}" class="d-none">
    @csrf @method('DELETE')
</form>
@endif

{{-- ── Image library picker (choose from uploaded images) ── --}}
<div class="modal fade" id="imgLibModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-images me-2"></i>Choose an uploaded image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="imgLibGrid" class="row g-3">
                    <div class="col-12 text-muted small">Loading…</div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <a href="{{ route('admin.signatures.assets') }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-upload me-1"></i>Upload more…
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/addon/edit/closetag.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.17/addon/selection/active-line.min.js"></script>

{{-- TinyMCE community build (GPL, no API key) for the Visual editor --}}
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js"></script>

<script>
// ── Colour picker sync ──────────────────────────────────────────────────
const colPicker = document.getElementById('primaryColor');
const colHex    = document.getElementById('primaryColorHex');
colPicker.addEventListener('input', () => { colHex.value = colPicker.value; });
colHex.addEventListener('input', () => {
    if (/^#[0-9a-fA-F]{6}$/.test(colHex.value)) colPicker.value = colHex.value;
});

// ── CodeMirror HTML editor ──────────────────────────────────────────────
const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';

const cmEditor = CodeMirror.fromTextArea(document.getElementById('htmlEditor'), {
    mode: 'htmlmixed',
    theme: isDark ? 'dracula' : 'default',
    lineNumbers: true,
    autoCloseTags: true,
    styleActiveLine: true,
    lineWrapping: true,
    tabSize: 2,
    indentWithTabs: false,
});

const cmPlain = CodeMirror.fromTextArea(document.getElementById('plainEditor'), {
    mode: 'text/plain',
    theme: isDark ? 'dracula' : 'default',
    lineNumbers: true,
    lineWrapping: true,
});

// ── Visual editor (TinyMCE) ─────────────────────────────────────────────
// The canonical HTML source is cmEditor (textarea name="html_body"). The Visual
// editor mirrors it; we sync on every tab switch, on preview, and on submit.
let currentTab = 'visual';
let tinyReady  = false;

tinymce.init({
    selector: '#visualEditor',
    license_key: 'gpl',
    promotion: false,
    branding: false,
    height: 400,
    menubar: false,
    statusbar: false,
    skin: isDark ? 'oxide-dark' : 'oxide',
    // Load the Google Fonts stylesheet inside the editor iframe too, so the chosen
    // font actually previews in the Visual editor (not just the toolbar dropdown).
    content_css: [
        isDark ? 'dark' : 'default',
        'https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&family=Lato:wght@400;700&family=Montserrat:wght@400;600&family=Open+Sans:wght@400;600;700&family=Poppins:wght@400;600&family=Roboto:wght@400;500;700&display=swap',
        '{{ route('admin.signatures.fonts-css') }}',
    ],
    // Image button / paste / drag-drop upload straight to the NOC asset host.
    automatic_uploads: true,
    paste_data_images: true,
    file_picker_types: 'image',
    images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append('file', blobInfo.blob(), blobInfo.filename());
        fetch('{{ route('admin.signatures.assets.image') }}', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            body: fd,
        })
        .then(r => r.ok ? r.json() : r.json().then(e => Promise.reject(e)))
        .then(d => resolve(d.location))
        .catch(err => reject({ message: (err && err.message) || 'Upload failed', remove: true }));
    }),
    plugins: 'link image table lists code autolink',
    toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline forecolor backcolor | '
           + 'alignleft aligncenter alignright | bullist numlist | link image imagelibrary table | code',
    toolbar_mode: 'wrap',
    // Image dialog's "Source" field gets a browse button that opens the uploads library.
    file_picker_callback: (cb, value, meta) => {
        if (meta.filetype === 'image') {
            openImageLibrary().then(url => { if (url) cb(url, { alt: '' }); });
        }
    },
    // Font list: uploaded (self-hosted) fonts first, then web-safe stacks (render
    // everywhere, incl. Outlook desktop), then Google Fonts (render in the editor +
    // web mail; Outlook falls back to the web-safe font at the end of each stack).
    // Size list is pixel-based.
    font_family_formats:
@foreach(($uploadedFonts ?? []) as $uf)
        "{{ addslashes($uf) }}='{{ addslashes($uf) }}',arial,sans-serif;" +
@endforeach
        'Roboto=roboto,arial,helvetica,sans-serif;'
        + 'Open Sans=\'open sans\',arial,sans-serif;'
        + 'Lato=lato,\'segoe ui\',arial,sans-serif;'
        + 'Montserrat=montserrat,\'segoe ui\',arial,sans-serif;'
        + 'Poppins=poppins,\'segoe ui\',arial,sans-serif;'
        + 'Cairo (Arabic)=cairo,\'segoe ui\',tahoma,arial,sans-serif;'
        + 'Arial=arial,helvetica,sans-serif;'
        + 'Helvetica=helvetica,arial,sans-serif;'
        + 'Calibri=calibri,candara,segoe,\'segoe ui\',optima,sans-serif;'
        + 'Segoe UI=\'segoe ui\',tahoma,geneva,verdana,sans-serif;'
        + 'Tahoma=tahoma,verdana,segoe,sans-serif;'
        + 'Verdana=verdana,geneva,sans-serif;'
        + 'Georgia=georgia,palatino,serif;'
        + 'Times New Roman=\'times new roman\',times,serif;'
        + 'Trebuchet MS=\'trebuchet ms\',geneva,sans-serif;'
        + 'Courier New=\'courier new\',courier,monospace',
    font_size_formats: '8px 9px 10px 11px 12px 13px 14px 15px 16px 18px 20px 24px 28px 32px',
    // Preserve email-safe inline styles, tables, and template placeholder tokens verbatim
    entity_encoding: 'raw',
    valid_elements: '*[*]',
    extended_valid_elements: '*[*]',
    verify_html: false,
    content_style: 'body{font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#333;}',
    setup(editor) {
        editor.on('init', () => { tinyReady = true; });
        editor.on('input change keyup SetContent ExecCommand', () => {
            if (currentTab === 'visual') scheduleAutoPreview();
        });
        // Toolbar button: pick an already-uploaded image and insert it.
        editor.ui.registry.addButton('imagelibrary', {
            icon: 'gallery',
            tooltip: 'Insert from uploaded images',
            onAction: () => {
                openImageLibrary().then(url => {
                    if (url) editor.insertContent(`<img src="${url}" alt="" style="max-width:100%;height:auto;">`);
                });
            },
        });
    },
});

// Returns the current HTML from whichever editor is active.
function currentHtml() {
    if (currentTab === 'visual' && tinyReady) {
        return tinymce.get('visualEditor').getContent();
    }
    return cmEditor.getValue();
}

// Push the active editor's content into the canonical html_body textarea.
function syncToSource() {
    if (currentTab === 'visual' && tinyReady) {
        cmEditor.setValue(tinymce.get('visualEditor').getContent());
    }
    cmEditor.save();
}

cmEditor.on('change', () => { if (currentTab === 'html') scheduleAutoPreview(); });

document.getElementById('sigForm').addEventListener('submit', () => {
    syncToSource();   // ensure html_body reflects visual edits
    cmEditor.save();
    cmPlain.save();
});

// ── Tab switching ───────────────────────────────────────────────────────
function switchTab(tab, btn) {
    // Copy the outgoing editor's content into the incoming one before showing it.
    if (currentTab === 'visual' && tab !== 'visual' && tinyReady) {
        cmEditor.setValue(tinymce.get('visualEditor').getContent());
    } else if (currentTab === 'html' && tab === 'visual' && tinyReady) {
        tinymce.get('visualEditor').setContent(cmEditor.getValue());
    }

    currentTab = tab;
    document.querySelectorAll('.cm-tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    document.getElementById('cmVisualPane').style.display = tab === 'visual' ? '' : 'none';
    document.getElementById('cmHtmlPane').style.display   = tab === 'html'   ? '' : 'none';
    document.getElementById('cmPlainPane').style.display  = tab === 'plain'  ? '' : 'none';

    if (tab === 'html')  cmEditor.refresh();
    if (tab === 'plain') cmPlain.refresh();
}

// ── Variable inserter (targets the active editor) ───────────────────────
function insertVarBtn(btn) {
    const varName = btn.getAttribute('data-var');
    const text = '@{{' + varName + '}}';
    if (currentTab === 'visual' && tinyReady) {
        tinymce.get('visualEditor').insertContent(text);
    } else {
        const cursor = cmEditor.getCursor();
        cmEditor.replaceRange(text, cursor);
        cmEditor.focus();
    }
}

function insertIfBlock() {
    const snippet = '@{{#if variable}}\n  \n@{{/if}}';
    if (currentTab === 'visual' && tinyReady) {
        tinymce.get('visualEditor').insertContent(snippet);
    } else {
        const cursor = cmEditor.getCursor();
        cmEditor.replaceRange(snippet, cursor);
        cmEditor.setCursor({ line: cursor.line, ch: cursor.ch + 6 });
        cmEditor.focus();
    }
}

// ── HTML auto-format ────────────────────────────────────────────────────
function formatHtml() {
    try {
        const raw = cmEditor.getValue();
        // Preserve template variable placeholders through an innerHTML round-trip
        const result = raw
            .replace(/><(?!\/)/g, '>\n<')
            .split('\n')
            .map(l => l.trim())
            .filter(l => l !== '')
            .join('\n');
        cmEditor.setValue(result);
    } catch (_) { /* formatting is cosmetic only */ }
}

// ── Live preview ────────────────────────────────────────────────────────
let _previewTimer = null;
let previewSlot   = 'new_email';                 // which Outlook slot to preview
const editingId   = @json($template?->id);       // exclude self when resolving counterpart

function setPreviewSlot(slot) {
    previewSlot = slot;
    runPreview();
}

function scheduleAutoPreview() {
    if (!document.getElementById('autoPreview').checked) return;
    clearTimeout(_previewTimer);
    _previewTimer = setTimeout(runPreview, 850);
}

function runPreview() {
    const upn = (document.getElementById('previewUpnInput').value || '').trim();
    document.getElementById('previewPane').innerHTML =
        '<span class="text-muted small"><i class="bi bi-hourglass-split me-1"></i>Rendering…</span>';

    fetch('{{ route('admin.signatures.preview') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({
            html_body:     currentHtml(),
            primary_color: colPicker.value,
            logo_url:      document.getElementById('logoUrl').value.trim(),
            upn:           upn || null,
            type:          previewSlot,
            editing_id:    editingId,
        }),
    })
    .then(r => r.json())
    .then(d => {
        document.getElementById('previewPane').innerHTML =
            d.html || '<span class="text-muted small">Empty output.</span>';
        const badge    = document.getElementById('previewBadge');
        const badgeCtr = document.getElementById('previewBadges');

        const slotLabel = previewSlot === 'reply' ? 'Reply' : 'New email';
        if (d.source === 'saved') {
            // Showing a different saved template for the other slot
            badge.className = 'badge bg-info text-dark';
            badge.textContent = `${slotLabel} slot → saved template “${d.name}”`;
        } else if (upn) {
            badge.className = 'badge bg-success';
            badge.textContent = `${slotLabel} · rendered for ${upn}`;
        } else {
            badge.className = 'badge bg-secondary';
            badge.textContent = `${slotLabel} · sample data`;
        }
        badgeCtr.classList.remove('d-none');
    })
    .catch(() => {
        document.getElementById('previewPane').innerHTML =
            '<span class="text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>Preview failed.</span>';
    });
}

// Trigger preview on colour / logo changes
colPicker.addEventListener('change', scheduleAutoPreview);
colHex.addEventListener('change', scheduleAutoPreview);
document.getElementById('logoUrl').addEventListener('blur', scheduleAutoPreview);

// Initial render after a short delay
setTimeout(runPreview, 700);

// ── Logo URL test ───────────────────────────────────────────────────────
function testLogoUrl() {
    const url = document.getElementById('logoUrl').value.trim();
    if (url) window.open(url, '_blank', 'noopener noreferrer');
}

// ── Image library picker ────────────────────────────────────────────────
// Fetches uploaded images and shows them in a modal. Resolves to the chosen
// image URL, or null if the dialog is dismissed. Used by the editor's gallery
// button, the Image dialog's browse button, and the "Choose image" logo button.
let _imgLibModal = null;
function openImageLibrary() {
    return new Promise((resolve) => {
        const grid   = document.getElementById('imgLibGrid');
        const modalEl = document.getElementById('imgLibModal');
        _imgLibModal = _imgLibModal || new bootstrap.Modal(modalEl);
        let picked = null;

        grid.innerHTML = '<div class="col-12 text-muted small"><i class="bi bi-hourglass-split me-1"></i>Loading…</div>';

        const onHide = () => { modalEl.removeEventListener('hidden.bs.modal', onHide); resolve(picked); };
        modalEl.addEventListener('hidden.bs.modal', onHide);

        fetch('{{ route('admin.signatures.assets.list') }}', {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        })
        .then(r => r.json())
        .then(d => {
            const imgs = (d && d.images) || [];
            if (!imgs.length) {
                grid.innerHTML = '<div class="col-12 text-center text-muted py-4">'
                    + 'No images uploaded yet.<br><a href="{{ route('admin.signatures.assets') }}" target="_blank">Upload some on the Assets page</a>.</div>';
                return;
            }
            grid.innerHTML = '';
            imgs.forEach(img => {
                const col = document.createElement('div');
                col.className = 'col-6 col-md-3';
                col.innerHTML =
                    '<button type="button" class="btn btn-outline-secondary p-1 w-100 h-100 d-flex flex-column align-items-center">'
                    + '<span style="height:80px;display:flex;align-items:center;justify-content:center;overflow:hidden;">'
                    + '<img src="' + img.url + '" alt="" style="max-width:100%;max-height:80px;object-fit:contain;"></span>'
                    + '<span class="small text-truncate w-100 mt-1" title="' + img.name + '">' + img.name + '</span></button>';
                col.querySelector('button').addEventListener('click', () => {
                    picked = img.url;
                    _imgLibModal.hide();
                });
                grid.appendChild(col);
            });
        })
        .catch(() => {
            grid.innerHTML = '<div class="col-12 text-danger small">Could not load images.</div>';
        });

        _imgLibModal.show();
    });
}

// Logo field: "Choose image" button opens the same library.
const _logoPickBtn = document.getElementById('logoPickBtn');
if (_logoPickBtn) {
    _logoPickBtn.addEventListener('click', () => {
        openImageLibrary().then(url => {
            if (url) {
                document.getElementById('logoUrl').value = url;
                scheduleAutoPreview();
            }
        });
    });
}
</script>
@endpush
