@extends('layouts.portal')

@section('title', $template->exists ? 'Edit template (GrapesJS)' : 'New template (GrapesJS)')

@section('content')
<link rel="stylesheet" href="https://unpkg.com/grapesjs/dist/css/grapes.min.css">
<link rel="stylesheet" href="https://unpkg.com/grapesjs-preset-newsletter/dist/grapesjs-preset-newsletter.css">
<style>
    /* GrapesJS panels are absolutely positioned inside the editor container.
       The container needs:
         - position: relative   (so absolute children anchor to it)
         - explicit pixel height
         - NO overflow:hidden parents that would clip the panels
       So we render the editor as a direct child of the page body (not inside
       a Bootstrap card) and give it a fixed height. */
    #gjs-editor {
        position: relative;
        height: calc(100vh - 280px);
        min-height: 600px;
        border: 1px solid #dee2e6;
        overflow: visible;
    }
    /* Bring panel surfaces above any other portal chrome that might overlap. */
    #gjs-editor .gjs-pn-panel { z-index: 5; }
</style>

<div class="container-fluid py-4">
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
            Building with <strong>GrapesJS</strong> (newsletter preset). Switch to Unlayer:
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

        {{-- Editor lives directly in the page flow (no card wrapper) so GrapesJS'
             absolute-positioned panels aren't clipped by Bootstrap's overflow. --}}
        <div id="gjs-editor"></div>

        <div class="d-flex justify-content-between align-items-center mt-3 mb-3 gap-2 flex-wrap">
            <button type="button" id="fullscreen-btn" class="btn btn-outline-info">
                <i class="bi bi-arrows-fullscreen me-1"></i>Open editor fullscreen
            </button>
            <div class="d-flex gap-2">
                <button type="button" id="preview-btn" class="btn btn-outline-secondary">
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

<script src="https://unpkg.com/grapesjs"></script>
<script src="https://unpkg.com/grapesjs-preset-newsletter"></script>
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
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Save template';
        }
    }
    function clearError() { errorBox.classList.add('d-none'); }

    if (typeof grapesjs === 'undefined') {
        showError('GrapesJS failed to load from unpkg. Check browser console (DevTools).');
        return;
    }

    // GrapesJS newsletter preset — well-tested, ships its own panels (blocks
    // left, canvas centre, styles right), produces inline-styled email HTML.
    let editor;
    try {
        editor = grapesjs.init({
            container: '#gjs-editor',
            fromElement: false,
            height: '100%',
            width: 'auto',
            storageManager: false,
            plugins: ['grapesjs-preset-newsletter'],
            pluginsOpts: {
                'grapesjs-preset-newsletter': {
                    modalLabelImport: 'Paste your HTML/CSS here',
                    modalLabelExport: 'Copy this HTML',
                    codeViewerTheme: 'material',
                    importPlaceholder: '<div class="el">Hello world!</div>',
                    cellStyle: {
                        'font-size': '14px',
                        'font-weight': 300,
                        'vertical-align': 'top',
                        color: '#000',
                        margin: 0,
                        padding: 0,
                    },
                },
            },
        });
        console.log('GrapesJS newsletter preset initialized:', editor);
    } catch (e) {
        console.error('GrapesJS init failed', e);
        showError('Editor failed to initialize: ' + e.message);
        return;
    }

    // Load previous design (stored as raw HTML for the newsletter preset)
    const existing = @json($template->design_json);
    if (existing) {
        try { editor.setComponents(existing); } catch (e) { console.error('Failed to load design', e); }
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
            // For the newsletter preset we save HTML as both design (for
            // re-edit via setComponents) and rendered_html (for send).
            const html  = editor.getHtml();
            const css   = editor.getCss();
            const full  = '<!doctype html><html><head><meta charset="utf-8"><style>'+ (css || '') +'</style></head><body>'+ html +'</body></html>';
            designIn.value = full;
            htmlIn.value   = full;
            form.submit();
        } catch (e) {
            console.error('GrapesJS save failed', e);
            showError('Save failed: ' + e.message);
        }
    });

    previewBtn.addEventListener('click', function () {
        try {
            const html = editor.getHtml();
            const css  = editor.getCss();
            const w = window.open('', '_blank');
            if (!w) { showError('Popup blocked — allow popups for this site to preview.'); return; }
            w.document.write('<!doctype html><html><head><meta charset="utf-8"><style>'+ (css || '') +'</style></head><body>'+ html +'</body></html>');
            w.document.close();
        } catch (e) {
            console.error('Preview failed', e);
            showError('Preview failed: ' + e.message);
        }
    });

    // Fullscreen — uses GrapesJS' built-in fullscreen command. Gives the
    // editor the entire browser window so panels have unambiguous room.
    document.getElementById('fullscreen-btn').addEventListener('click', function () {
        try { editor.runCommand('fullscreen'); }
        catch (e) { console.error('Fullscreen failed', e); }
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
