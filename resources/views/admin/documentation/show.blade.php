@extends('layouts.admin')

@section('title', ($title ?: $filename) . ' — Documentation')

@push('styles')
<style>
    /* Hide body scroll — viewer fills everything below the navbar */
    body { overflow: hidden; }

    /* Full-viewport viewer anchored just below the navbar */
    #doc-viewer {
        position: fixed;
        top: 0; /* JS will push this down to navbar bottom */
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        flex-direction: column;
        background: var(--bs-body-bg, #fff);
        z-index: 99;
    }

    .doc-viewer-bar {
        flex-shrink: 0;
        display: flex;
        align-items: center;
        gap: .5rem;
        flex-wrap: wrap;
        padding: .45rem .75rem;
        border-bottom: 1px solid rgba(0,0,0,.12);
        background: var(--bs-body-bg, #fff);
    }
    .doc-viewer-bar .doc-title {
        font-weight: 600;
        font-size: .9rem;
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .doc-viewer-bar .doc-desc {
        font-size: .78rem;
        opacity: .65;
        flex-basis: 100%;
        padding-left: calc(2rem + .5rem); /* indent past buttons */
        display: none;
    }

    #docIframe {
        flex: 1;
        border: none;
        width: 100%;
    }
</style>
@endpush

@section('content')
{{-- intentionally empty — viewer is rendered via fixed overlay below --}}
@endsection

@push('scripts')
<script>
// Position the viewer flush under the actual navbar
(function () {
    const nav = document.querySelector('nav.navbar');
    const viewer = document.getElementById('doc-viewer');
    if (nav && viewer) {
        viewer.style.top = nav.offsetHeight + 'px';
    }
})();

// Fullscreen toggle
document.getElementById('toggleFullscreen')?.addEventListener('click', function () {
    const viewer = document.getElementById('doc-viewer');
    const isFs   = viewer.classList.toggle('fs-mode');

    if (isFs) {
        viewer.style.top = '0';
    } else {
        const nav = document.querySelector('nav.navbar');
        viewer.style.top = (nav ? nav.offsetHeight : 56) + 'px';
    }

    this.querySelector('i').className = isFs ? 'bi bi-fullscreen-exit' : 'bi bi-fullscreen';
    this.title = isFs ? 'Exit fullscreen' : 'Toggle fullscreen';
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('doc-viewer')?.classList.contains('fs-mode')) {
        document.getElementById('toggleFullscreen')?.click();
    }
});
</script>
@endpush

{{-- ── Fixed viewer (outside the container-fluid wrapper) ────────── --}}
<div id="doc-viewer">

    <div class="doc-viewer-bar">
        <a href="{{ route('admin.documentation.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>

        <span class="doc-title">
            <i class="bi bi-file-earmark-code text-primary me-1"></i>
            {{ $title ?: $filename }}
            @if($description)
                <small class="text-muted fw-normal ms-2" style="font-size:.8rem">— {{ $description }}</small>
            @endif
        </span>

        <a href="{{ route('admin.documentation.raw', $filename) }}" target="_blank"
           class="btn btn-sm btn-outline-primary" title="Open raw file in new tab">
            <i class="bi bi-box-arrow-up-right"></i> New Tab
        </a>

        <button id="toggleFullscreen" class="btn btn-sm btn-outline-secondary" title="Toggle fullscreen">
            <i class="bi bi-fullscreen"></i>
        </button>

        @can('manage-documentation')
        <form action="{{ route('admin.documentation.toggle-public', $filename) }}" method="POST" class="d-inline">
            @csrf
            <button type="submit"
                    class="btn btn-sm {{ $is_public ? 'btn-success' : 'btn-outline-success' }}"
                    title="{{ $is_public ? 'Make Private' : 'Make Public' }}">
                <i class="bi bi-globe"></i> {{ $is_public ? 'Public' : 'Make Public' }}
            </button>
        </form>

        <form action="{{ route('admin.documentation.destroy', $filename) }}" method="POST" class="d-inline"
              onsubmit="return confirm('Delete {{ addslashes($filename) }}?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete this document">
                <i class="bi bi-trash"></i> Delete
            </button>
        </form>
        @endcan
    </div>

    <iframe id="docIframe"
            srcdoc="{{ $html }}"
            sandbox="allow-same-origin allow-scripts allow-forms"
            title="{{ $title ?: $filename }}"
            loading="lazy"
    ></iframe>

</div>
