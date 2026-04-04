<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?: $filename }} — {{ config('app.name') }}</title>
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
            background: #212529;
        }
        .doc-title {
            font-weight: 600;
            font-size: .9rem;
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #fff;
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
        <a href="{{ route('public.documentation.index') }}" class="btn btn-sm btn-outline-light">
            <i class="bi bi-arrow-left"></i> Back
        </a>

        <span class="doc-title">
            <i class="bi bi-file-earmark-code me-1" style="opacity:.7"></i>
            {{ $title ?: $filename }}
            @if($description)
                <small style="opacity:.6; font-size:.78rem"> — {{ $description }}</small>
            @endif
        </span>

        <a href="{{ route('public.documentation.show', $filename) }}" target="_blank"
           class="btn btn-sm btn-outline-light">
            <i class="bi bi-box-arrow-up-right"></i>
        </a>
    </div>

    <iframe id="docIframe"
            srcdoc="{{ $html }}"
            sandbox="allow-same-origin allow-scripts allow-popups"
            title="{{ $title ?: $filename }}"
            loading="lazy"
    ></iframe>

</body>
</html>
