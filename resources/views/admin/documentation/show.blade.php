<!DOCTYPE html>
<html lang="en" data-bs-theme="{{ Auth::check() && Auth::user()->dark_mode ? 'dark' : 'light' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?: $filename }} — Documentation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .toolbar {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: .4rem;
            flex-wrap: wrap;
            padding: .4rem .75rem;
            border-bottom: 1px solid rgba(0,0,0,.15);
            background: var(--bs-body-bg);
        }
        .doc-title {
            font-weight: 600;
            font-size: .9rem;
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #docIframe {
            flex: 1;
            border: none;
            width: 100%;
            display: block;
        }
    </style>
</head>
<body>

    <div class="toolbar">
        <a href="{{ route('admin.documentation.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back
        </a>

        <span class="doc-title">
            <i class="bi bi-file-earmark-code text-primary me-1"></i>
            {{ $title ?: $filename }}
            @if($description)
                <small class="text-muted fw-normal ms-2" style="font-size:.78rem">— {{ $description }}</small>
            @endif
        </span>

        <a href="{{ route('admin.documentation.raw', $filename) }}" target="_blank"
           class="btn btn-sm btn-outline-primary">
            <i class="bi bi-box-arrow-up-right"></i> New Tab
        </a>

        <button id="toggleFullscreen" class="btn btn-sm btn-outline-secondary" title="Toggle fullscreen">
            <i class="bi bi-fullscreen"></i>
        </button>

        @can('manage-documentation')
        <form action="{{ route('admin.documentation.toggle-public', $filename) }}" method="POST" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm {{ $is_public ? 'btn-success' : 'btn-outline-success' }}">
                <i class="bi bi-globe"></i> {{ $is_public ? 'Public' : 'Make Public' }}
            </button>
        </form>

        <form action="{{ route('admin.documentation.destroy', $filename) }}" method="POST" class="d-inline"
              onsubmit="return confirm('Delete {{ addslashes($filename) }}?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-sm btn-outline-danger">
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

    <script>
    document.getElementById('toggleFullscreen')?.addEventListener('click', function () {
        const toolbar = document.querySelector('.toolbar');
        const isFs    = toolbar.classList.toggle('d-none');
        this.closest('.toolbar') && (toolbar.classList.remove('d-none'));
        // Toggle properly: hide toolbar, iframe fills full viewport
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            document.documentElement.requestFullscreen().catch(() => {});
        }
    });

    document.addEventListener('fullscreenchange', function () {
        const btn = document.getElementById('toggleFullscreen');
        if (!btn) return;
        btn.querySelector('i').className = document.fullscreenElement
            ? 'bi bi-fullscreen-exit'
            : 'bi bi-fullscreen';
    });
    </script>

</body>
</html>
