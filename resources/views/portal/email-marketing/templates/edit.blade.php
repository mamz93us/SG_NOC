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

            {{-- ── Available merge tags reference (click to copy) ────
                 NOTE: We hand-build each badge so the literal {{ }} pairs
                 are never adjacent in the source — Blade would otherwise
                 compile them into <?php echo e(name) ?> tokens and the
                 user would copy PHP into their email body. --}}
            <div class="card shadow-sm mb-3 border-info">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center flex-wrap gap-3">
                        <strong class="text-info"><i class="bi bi-braces me-1"></i>Available variables</strong>
                        @php
                            // Build the literal merge-tag strings without ever putting
                            // `{` `{` adjacent in this source file (Blade scans for that).
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
                                  data-tag="{{ $literal }}" title="Click to copy {{ $literal }}"
                                  style="cursor: pointer;">
                                <code class="text-info">{{ $literal }}</code>
                                <small class="text-muted ms-1">{{ $desc }}</small>
                            </span>
                        @endforeach
                        <span id="copy-feedback" class="text-success d-none ms-2">
                            <i class="bi bi-check-circle me-1"></i>Copied
                        </span>
                    </div>
                    <small class="text-muted d-block mt-1">
                        Click any variable to copy it to your clipboard, then paste into the email body or subject.
                        The Unlayer editor also has a <strong>Merge Tags</strong> picker in any text block's toolbar.
                    </small>
                </div>
            </div>

            {{-- ── SAMIR icon library (click to copy an HTML snippet) ───
                 Paste the copied snippet into an Unlayer HTML block to drop
                 a colored, scalable icon into the email. --}}
            @php
                // Inline SVG path data — keep small (24×24 viewBox).
                $samirIcons = [
                    'star'      => 'M12 2 L15 9 L22 9 L17 14 L19 21 L12 17 L5 21 L7 14 L2 9 L9 9 Z',
                    'envelope'  => 'M2 4 H22 V20 H2 Z M2 4 L12 13 L22 4',
                    'check'     => 'M4 12 L10 18 L20 6',
                    'phone'     => 'M3 5 C3 4 4 3 5 3 H7 L9 8 L7 10 C8 13 11 16 14 17 L16 15 L21 17 V19 C21 20 20 21 19 21 C10 21 3 14 3 5 Z',
                    'globe'     => 'M12 2 A10 10 0 1 0 12 22 A10 10 0 1 0 12 2 Z M2 12 H22 M12 2 C8 8 8 16 12 22 M12 2 C16 8 16 16 12 22',
                    'shield'    => 'M12 2 L4 6 V12 C4 17 8 21 12 22 C16 21 20 17 20 12 V6 Z',
                    'lightning' => 'M13 2 L4 14 H11 L9 22 L20 10 H13 Z',
                    'heart'     => 'M12 21 C12 21 3 14 3 8 A5 5 0 0 1 12 5 A5 5 0 0 1 21 8 C21 14 12 21 12 21 Z',
                    'gear'      => 'M12 8 A4 4 0 1 0 12 16 A4 4 0 1 0 12 8 Z M19 12 L21 11 L20 8 L17 9 L15 7 L16 4 L12 3 L11 6 L9 7 L6 5 L4 8 L6 10 L5 12 L3 14 L5 16 L7 15 L9 17 L8 20 L12 21 L13 18 L15 17 L18 19 L20 16 L18 14 L19 12 Z',
                    'bell'      => 'M12 3 A6 6 0 0 0 6 9 V14 L4 17 H20 L18 14 V9 A6 6 0 0 0 12 3 Z M10 19 A2 2 0 0 0 14 19',
                    'trophy'    => 'M8 21 H16 M12 17 V21 M5 4 H19 V8 A5 5 0 0 1 14 13 H10 A5 5 0 0 1 5 8 Z M5 6 H2 V8 A3 3 0 0 0 5 11 M19 6 H22 V8 A3 3 0 0 0 19 11',
                    'crown'     => 'M3 9 L7 12 L12 6 L17 12 L21 9 L19 19 H5 Z',
                    'calendar'  => 'M3 6 H21 V20 H3 Z M3 10 H21 M8 3 V8 M16 3 V8',
                ];
            @endphp
            <div class="card shadow-sm mb-3 border-warning">
                <div class="card-body py-2">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <strong class="text-warning"><i class="bi bi-stars me-1"></i>SAMIR icon library</strong>
                        <small class="text-muted me-2">Click an icon to copy a snippet — paste into an HTML block.</small>
                        @foreach ($samirIcons as $name => $path)
                            @php
                                $snippet = '<div style="text-align:center; padding:8px;">'
                                    . '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" '
                                    . 'stroke="#dc3545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
                                    . '<path d="'.$path.'"/></svg></div>';
                            @endphp
                            <button type="button" class="btn btn-sm btn-outline-secondary copy-icon"
                                    data-snippet="{{ $snippet }}" title="Click to copy {{ $name }} icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                                     stroke="#dc3545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="{{ $path }}"/>
                                </svg>
                                <small class="ms-1">{{ ucfirst($name) }}</small>
                            </button>
                        @endforeach
                        <span id="icon-copy-feedback" class="text-success d-none ms-2">
                            <i class="bi bi-check-circle me-1"></i>Snippet copied
                        </span>
                    </div>
                    <small class="text-muted d-block mt-1">
                        After pasting, change <code>stroke="#dc3545"</code> in the SVG to any hex color.
                        <code>width="48"</code> / <code>height="48"</code> control the size.
                    </small>
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

    // Click-to-copy for SAMIR icon library
    document.querySelectorAll('.copy-icon').forEach(function (el) {
        el.addEventListener('click', function () {
            const snippet = el.getAttribute('data-snippet');
            const fb = document.getElementById('icon-copy-feedback');
            const reveal = function () {
                fb.classList.remove('d-none');
                clearTimeout(window.__iconCopyTimer);
                window.__iconCopyTimer = setTimeout(function () { fb.classList.add('d-none'); }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(snippet).then(reveal).catch(function () {});
            }
        });
    });

    // Click-to-copy for the merge-tag reference badges
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
                navigator.clipboard.writeText(tag).then(reveal).catch(function () {
                    // Fallback for older browsers / non-HTTPS
                    const ta = document.createElement('textarea');
                    ta.value = tag;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); reveal(); } catch (e) {}
                    document.body.removeChild(ta);
                });
            }
        });
    });
})();
</script>
@endsection
