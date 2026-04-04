@extends('layouts.admin')

@section('title', $filename . ' — Documentation')

@push('styles')
<style>
    .doc-viewer-bar {
        background: var(--bs-body-bg, #fff);
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
        height: calc(100vh - 112px);
        display: block;
    }
    body.iframe-fullscreen #docIframe { height: 100vh; }
    body.iframe-fullscreen .doc-viewer-bar {
        position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
    }
    body.iframe-fullscreen .content-wrapper { padding-top: 50px; }
</style>
@endpush

@section('content')
<div class="content-wrapper" style="padding:0">

    {{-- ── Viewer Toolbar ──────────────────────────────────────── --}}
    <div class="doc-viewer-bar">
        <a href="{{ route('admin.documentation.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>

        <span class="doc-title">
            <i class="bi bi-file-earmark-code text-primary me-1"></i>
            {{ $title ?: $filename }}
            @if($description)
                <small class="text-muted fw-normal ms-2">{{ $description }}</small>
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
        {{-- Toggle public --}}
        <form action="{{ route('admin.documentation.toggle-public', $filename) }}" method="POST" class="d-inline">
            @csrf
            <button type="submit"
                    class="btn btn-sm {{ $is_public ? 'btn-success' : 'btn-outline-success' }}"
                    title="{{ $is_public ? 'Make Private' : 'Make Public' }}">
                <i class="bi bi-globe"></i> {{ $is_public ? 'Public' : 'Make Public' }}
            </button>
        </form>

        <form action="{{ route('admin.documentation.destroy', $filename) }}" method="POST" class="d-inline"
              onsubmit="return confirm('Delete {{ $filename }}?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete this document">
                <i class="bi bi-trash"></i> Delete
            </button>
        </form>
        @endcan
    </div>

    {{-- ── Sandboxed iframe ────────────────────────────────────── --}}
    <iframe id="docIframe"
            srcdoc="{{ $html }}"
            sandbox="allow-same-origin allow-scripts allow-forms"
            title="{{ $filename }}"
            loading="lazy"
    ></iframe>

</div>
@endsection

@push('scripts')
<script>
document.getElementById('toggleFullscreen')?.addEventListener('click', function () {
    const isFs = document.body.classList.toggle('iframe-fullscreen');
    this.querySelector('i').className = isFs ? 'bi bi-fullscreen-exit' : 'bi bi-fullscreen';
    this.title = isFs ? 'Exit fullscreen' : 'Toggle fullscreen';
    document.getElementById('docIframe').style.height = isFs ? '100vh' : 'calc(100vh - 112px)';
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.body.classList.contains('iframe-fullscreen')) {
        document.getElementById('toggleFullscreen')?.click();
    }
});
</script>
@endpush
