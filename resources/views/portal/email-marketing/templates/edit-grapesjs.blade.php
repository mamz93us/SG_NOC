@extends('layouts.portal')

@section('title', $template->exists ? 'Edit template (GrapesJS)' : 'New template (GrapesJS)')

@section('content')
{{-- Break out of the portal padding so the editor canvas fills the viewport. --}}
<style>
    .em-editor-fullbleed {
        margin-left: calc(-1 * (var(--bs-gutter-x, 1.5rem) * 0.5 + 1rem));
        margin-right: calc(-1 * (var(--bs-gutter-x, 1.5rem) * 0.5 + 1rem));
        margin-top: -1rem;
        margin-bottom: -2rem;
    }
    @media (min-width: 992px) {
        .em-editor-fullbleed {
            margin-left:  calc(-1 * (var(--bs-gutter-x, 1.5rem) * 0.5 + 1.5rem));
            margin-right: calc(-1 * (var(--bs-gutter-x, 1.5rem) * 0.5 + 1.5rem));
        }
    }
    #gjs-editor {
        height: calc(100vh - 280px);
        min-height: 600px;
    }
    /* GrapesJS default dark theme reads OK on white background; we tone it down. */
    .gjs-pn-views-container, .gjs-pn-views { background-color: #f8f9fa !important; }
</style>

<link rel="stylesheet" href="https://unpkg.com/grapesjs/dist/css/grapes.min.css">
<style>
    /* Make sure GrapesJS panels (blocks + style + canvas) actually have room. */
    #gjs-editor .gjs-pn-views-container { width: 280px !important; }
    #gjs-editor .gjs-blocks-c { padding: 5px !important; }
    #gjs-editor .gjs-block { width: calc(50% - 10px) !important; }
</style>

<div class="em-editor-fullbleed">
    <div class="px-3 px-lg-4 pt-4">
        <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
        @include('portal.email-marketing._nav')

        @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>Couldn't save:</strong>
                <ul class="mb-0">
                    @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                </ul>
            </div>
        @endif
        <div id="save-error" class="alert alert-danger d-none"></div>

        <div class="alert alert-info py-2 d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-info-circle me-1"></i>
                Building with <strong>GrapesJS + MJML</strong>. Switch to Unlayer:
                <a href="{{ route('portal.marketing.templates.create', ['editor' => 'unlayer']) }}" class="alert-link">new Unlayer template</a>.
            </div>
            <small class="text-muted">Editor type: <code>grapesjs</code></small>
        </div>

        <form id="template-form" method="POST"
              action="{{ $template->exists ? route('portal.marketing.templates.update', $template) : route('portal.marketing.templates.store') }}">
            @csrf
            @if ($template->exists) @method('PUT') @endif
            <input type="hidden" name="editor_type" value="grapesjs">

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Template name</label>
                            <input type="text" name="name" class="form-control" required value="{{ old('name', $template->name) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Preview text (inbox snippet)</label>
                            <input type="text" name="preview_text" class="form-control" value="{{ old('preview_text', $template->preview_text) }}">
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Available merge tags reference (click to copy) ──── --}}
            <div class="card shadow-sm mb-3 border-info">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center flex-wrap gap-3">
                        <strong class="text-info"><i class="bi bi-braces me-1"></i>Available variables</strong>
                        @php
                            $tag = fn (string $name) => '{'.'{'.$name.'}'.'}';
                            $vars = [
                                $tag('first_name')      => 'First name',
                                $tag('last_name')       => 'Last name',
                                $tag('email')           => 'Email address',
                                $tag('unsubscribe_url') => 'Unsubscribe link (required)',
                            ];
                        @endphp
                        @foreach ($vars as $literal => $desc)
                            <span class="badge bg-light text-dark border copy-tag" role="button"
                                  data-tag="{{ $literal }}" title="Click to copy"
                                  style="cursor: pointer;">
                                <code class="text-info">{{ $literal }}</code>
                                <small class="text-muted ms-1">{{ $desc }}</small>
                            </span>
                        @endforeach
                        <span id="copy-feedback" class="text-success d-none ms-2">
                            <i class="bi bi-check-circle me-1"></i>Copied
                        </span>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div id="gjs-editor"></div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <button type="button" id="preview-btn" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-eye me-1"></i>Preview HTML
                    </button>
                    <button type="button" id="save-btn" class="btn btn-primary">
                        <i class="bi bi-check2-circle me-1"></i>Save template
                    </button>
                </div>
            </div>

            <input type="hidden" name="design_json"   id="design_json"   value="{{ old('design_json', $template->design_json) }}">
            <input type="hidden" name="rendered_html" id="rendered_html" value="{{ old('rendered_html', $template->rendered_html) }}">
        </form>
    </div>
</div>

<script src="https://unpkg.com/grapesjs"></script>
<script src="https://unpkg.com/grapesjs-mjml"></script>
<script>
(function () {
    const saveBtn    = document.getElementById('save-btn');
    const previewBtn = document.getElementById('preview-btn');
    const form       = document.getElementById('template-form');
    const errorBox   = document.getElementById('save-error');
    const designIn   = document.getElementById('design_json');
    const htmlIn     = document.getElementById('rendered_html');

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.classList.remove('d-none');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Save template';
    }
    function clearError() { errorBox.classList.add('d-none'); }

    // Sanity-check the libs actually loaded
    if (typeof grapesjs === 'undefined') {
        showError('GrapesJS failed to load from unpkg. Check the browser console — likely a network block or CSP.');
        return;
    }
    if (typeof grapesjsMjml === 'undefined' && typeof window['grapesjs-mjml'] === 'undefined') {
        showError('GrapesJS MJML plugin failed to load from unpkg. Falling back to plain HTML editor.');
    }

    // GrapesJS init with the MJML preset.
    // fromElement: false → don't try to parse the container's HTML as a design.
    // width: '100%'      → MJML plugin's left blocks panel needs the real width
    //                       to lay out; 'auto' collapsed it to nothing.
    let editor;
    try {
        editor = grapesjs.init({
            container: '#gjs-editor',
            fromElement: false,
            height: '100%',
            width: '100%',
            storageManager: false,
            plugins: ['grapesjs-mjml'],
            pluginsOpts: {
                'grapesjs-mjml': {},
            },
        });
        console.log('GrapesJS + MJML initialized:', editor);
    } catch (e) {
        console.error('GrapesJS init failed', e);
        showError('Editor failed to initialize: ' + e.message);
        return;
    }

    // Load previous design (stored as MJML markup) if editing
    const existing = @json($template->design_json);
    if (existing) {
        try { editor.setComponents(existing); } catch (e) { console.error('Failed to load MJML', e); }
    }

    saveBtn.addEventListener('click', function () {
        clearError();
        const name = form.querySelector('input[name=name]').value.trim();
        if (!name) {
            showError('Template name is required.');
            form.querySelector('input[name=name]').focus();
            return;
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

        try {
            // Export MJML markup as design_json (so we can re-edit later)
            const mjml = editor.getHtml();
            designIn.value = mjml;

            // Export compiled HTML for actual sending — grapesjs-mjml compiles
            // via mjml-browser bundled in the plugin. The command runs sync.
            const result = editor.runCommand('mjml-get-code');
            htmlIn.value = (result && result.html) ? result.html : mjml;

            form.submit();
        } catch (e) {
            console.error('GrapesJS save failed', e);
            showError('Save failed: ' + e.message);
        }
    });

    previewBtn.addEventListener('click', function () {
        try {
            const result = editor.runCommand('mjml-get-code');
            const html = (result && result.html) ? result.html : editor.getHtml();
            const w = window.open('', '_blank');
            if (!w) {
                showError('Popup blocked — allow popups for this site to preview.');
                return;
            }
            w.document.write(html || '<p>No content yet.</p>');
            w.document.close();
        } catch (e) {
            console.error('Preview failed', e);
            showError('Preview failed: ' + e.message);
        }
    });

    // Merge-tag click-to-copy (same UX as Unlayer view)
    document.querySelectorAll('.copy-tag').forEach(function (el) {
        el.addEventListener('click', function () {
            const tag = el.getAttribute('data-tag');
            const fb  = document.getElementById('copy-feedback');
            const reveal = function () {
                fb.classList.remove('d-none');
                clearTimeout(window.__copyTimer);
                window.__copyTimer = setTimeout(function () { fb.classList.add('d-none'); }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(tag).then(reveal).catch(function () {});
            }
        });
    });
})();
</script>
@endsection
