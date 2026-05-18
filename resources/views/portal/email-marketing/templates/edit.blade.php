@extends('layouts.portal')

@section('title', $template->exists ? 'Edit template' : 'New template')

@section('content')
{{-- Break out of the portal's container padding so Unlayer gets the full viewport width.
     The portal layout wraps content in `container-fluid px-3 px-lg-4 mt-3 mb-5` — we offset
     it on this page only with negative margins. --}}
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
    /* Unlayer sizes its own iframe — we pass `minHeight` to init() below.
       This wrapper just guarantees full width and a sensible CSS fallback height. */
    .em-editor-canvas {
        height: calc(100vh - 280px);
        min-height: 600px;
        width: 100%;
    }
    .em-editor-canvas iframe {
        width: 100% !important;
        height: 100% !important;
        border: 0 !important;
    }
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

        <form id="template-form"
              method="POST"
              action="{{ $template->exists ? route('portal.marketing.templates.update', $template) : route('portal.marketing.templates.store') }}">
            @csrf
            @if ($template->exists) @method('PUT') @endif

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

            {{-- Unlayer editor embedded — design + HTML are exported on save. --}}
            <div class="card shadow-sm">
                <div class="card-body p-0">
                    <div id="unlayer-editor" class="em-editor-canvas"></div>
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

            <input type="hidden" name="design_json" id="design_json" value="{{ old('design_json', $template->design_json) }}">
            <input type="hidden" name="rendered_html" id="rendered_html" value="{{ old('rendered_html', $template->rendered_html) }}">
        </form>
    </div>
</div>

<script src="//editor.unlayer.com/embed.js"></script>
<script>
(function () {
    function computeEditorHeight() {
        // Top chrome (portal navbar + page header + tab nav + form card) is ~260px;
        // footer (action buttons + page margin) is ~80px. Leaves the rest for the editor,
        // with a 600px floor so it stays usable on short viewports.
        return Math.max(600, window.innerHeight - 300) + 'px';
    }

    function initUnlayer() {
        if (typeof unlayer === 'undefined') {
            return setTimeout(initUnlayer, 200);
        }
        unlayer.init({
            id: 'unlayer-editor',
            projectId: null,
            displayMode: 'email',
            minHeight: computeEditorHeight(),
            @verbatim
            mergeTags: {
                first_name:      { name: 'First name',      value: '{{first_name}}' },
                last_name:       { name: 'Last name',       value: '{{last_name}}' },
                email:           { name: 'Email',           value: '{{email}}' },
                unsubscribe_url: { name: 'Unsubscribe URL', value: '{{unsubscribe_url}}' }
            }
            @endverbatim
        });

        // Resize the iframe when the viewport changes so the editor follows.
        let resizeTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                const iframe = document.querySelector('#unlayer-editor iframe');
                if (iframe) iframe.style.height = computeEditorHeight();
            }, 150);
        });

        @if ($template->design_json)
            try {
                unlayer.loadDesign(JSON.parse(document.getElementById('design_json').value || '{}'));
            } catch (e) { console.error('Failed to load design', e); }
        @endif

        const saveBtn = document.getElementById('save-btn');
        const previewBtn = document.getElementById('preview-btn');
        const form = document.getElementById('template-form');
        const errorBox = document.getElementById('save-error');

        function showError(msg) {
            errorBox.textContent = msg;
            errorBox.classList.remove('d-none');
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Save template';
        }
        function clearError() { errorBox.classList.add('d-none'); }

        saveBtn.addEventListener('click', function () {
            clearError();
            const nameInput = form.querySelector('input[name=name]');
            if (!nameInput.value.trim()) {
                showError('Template name is required.');
                nameInput.focus();
                return;
            }

            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

            // Safety timeout: if Unlayer's exportHtml callback never fires
            // (script blocked, iframe broken, etc.) we surface the failure
            // instead of leaving the user staring at a spinner forever.
            const timeout = setTimeout(function () {
                showError('Editor did not respond. Refresh the page and try again — check the browser console for errors.');
            }, 15000);

            try {
                unlayer.exportHtml(function (data) {
                    clearTimeout(timeout);
                    try {
                        document.getElementById('design_json').value   = JSON.stringify(data.design || {});
                        document.getElementById('rendered_html').value = data.html || '';
                        console.log('Template export OK. HTML size:',
                            (data.html || '').length, 'bytes. Submitting…');
                        form.submit();
                    } catch (e) {
                        console.error('Export callback error', e);
                        showError('Failed to serialize template: ' + e.message);
                    }
                });
            } catch (e) {
                clearTimeout(timeout);
                console.error('exportHtml threw', e);
                showError('Editor error: ' + e.message);
            }
        });

        previewBtn.addEventListener('click', function () {
            try {
                unlayer.exportHtml(function (data) {
                    const w = window.open('', '_blank');
                    if (!w) {
                        showError('Popup blocked — allow popups for this site to preview.');
                        return;
                    }
                    w.document.write(data.html || '<p>No content yet.</p>');
                    w.document.close();
                });
            } catch (e) {
                console.error('Preview failed', e);
                showError('Preview failed: ' + e.message);
            }
        });
    }
    initUnlayer();
})();
</script>
@endsection
