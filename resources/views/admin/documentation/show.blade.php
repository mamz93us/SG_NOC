@extends('layouts.admin')

@section('title', $filename . ' — Documentation')

@push('styles')
<style>
    .doc-viewer-bar {
        background: var(--card-bg, #fff);
        border-bottom: 1px solid rgba(0,0,0,.12);
        padding: .5rem 1rem;
        display: flex;
        align-items: center;
        gap: .5rem;
        flex-wrap: wrap;
    }
    .doc-viewer-bar .doc-title {
        font-weight: 600;
        font-size: .95rem;
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    #docIframe {
        width: 100%;
        border: none;
        /* full viewport minus top nav + viewer bar */
        height: calc(100vh - 112px);
        display: block;
    }
    /* fullscreen mode */
    body.iframe-fullscreen .content-wrapper { padding: 0; margin: 0; }
    body.iframe-fullscreen .main-header,
    body.iframe-fullscreen .main-sidebar,
    body.iframe-fullscreen .main-footer,
    body.iframe-fullscreen .content-header { display: none !important; }
    body.iframe-fullscreen #docIframe { height: 100vh; }
    body.iframe-fullscreen .doc-viewer-bar {
        position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
    }
    body.iframe-fullscreen .content-wrapper {
        padding-top: 50px;
    }
</style>
@endpush

@section('content')
<div class="content-wrapper" style="padding:0">

    {{-- ── Viewer Toolbar ──────────────────────────────────────── --}}
    <div class="doc-viewer-bar">
        <a href="{{ route('admin.documentation.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>

        <span class="doc-title">
            <i class="fas fa-file-code text-primary mr-1"></i>
            {{ $filename }}
        </span>

        <a href="{{ route('admin.documentation.raw', $filename) }}" target="_blank"
           class="btn btn-sm btn-outline-primary" title="Open raw file in new tab">
            <i class="fas fa-external-link-alt"></i> New Tab
        </a>

        <button id="toggleFullscreen" class="btn btn-sm btn-outline-secondary" title="Toggle fullscreen">
            <i class="fas fa-expand"></i>
        </button>

        @can('manage-documentation')
        <form action="{{ route('admin.documentation.destroy', $filename) }}" method="POST" class="d-inline"
              onsubmit="return confirm('Delete {{ $filename }}?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete this document">
                <i class="fas fa-trash"></i> Delete
            </button>
        </form>
        @endcan
    </div>

    {{-- ── Sandboxed iframe ────────────────────────────────────── --}}
    {{--
        sandbox="allow-same-origin allow-scripts" lets the HTML file run its
        own scripts (needed for reports with charts / interactive content)
        while still isolating it from the parent window DOM.
    --}}
    <iframe id="docIframe"
            srcdoc="{{ htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') }}"
            sandbox="allow-same-origin allow-scripts allow-forms"
            title="{{ $filename }}"
            loading="lazy"
    ></iframe>

</div>
@endsection

@push('scripts')
<script>
document.getElementById('toggleFullscreen')?.addEventListener('click', function () {
    const body = document.body;
    const isFs = body.classList.toggle('iframe-fullscreen');
    this.innerHTML = isFs
        ? '<i class="fas fa-compress"></i>'
        : '<i class="fas fa-expand"></i>';
    this.title = isFs ? 'Exit fullscreen' : 'Toggle fullscreen';

    // Recalculate iframe height
    const iframe = document.getElementById('docIframe');
    iframe.style.height = isFs ? '100vh' : 'calc(100vh - 112px)';
});

// Escape key exits fullscreen
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.body.classList.contains('iframe-fullscreen')) {
        document.getElementById('toggleFullscreen')?.click();
    }
});
</script>
@endpush
