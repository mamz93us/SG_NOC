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

        document.getElementById('save-btn').addEventListener('click', function () {
            unlayer.exportHtml(function (data) {
                document.getElementById('design_json').value   = JSON.stringify(data.design);
                document.getElementById('rendered_html').value = data.html;
                document.getElementById('template-form').submit();
            });
        });

        document.getElementById('preview-btn').addEventListener('click', function () {
            unlayer.exportHtml(function (data) {
                const w = window.open('', '_blank');
                w.document.write(data.html);
                w.document.close();
            });
        });
    }
    initUnlayer();
})();
</script>
@endsection
