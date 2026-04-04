<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $filename }} — {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        html, body { height: 100%; margin: 0; display: flex; flex-direction: column; }
        .toolbar { flex-shrink: 0; }
        #docFrame { flex: 1; border: none; width: 100%; }
    </style>
</head>
<body>
    <div class="toolbar navbar navbar-dark bg-dark px-3 gap-2">
        <a href="{{ route('public.documentation.index') }}" class="btn btn-sm btn-outline-light">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <span class="text-white fw-semibold text-truncate flex-grow-1">{{ $filename }}</span>
        <a href="{{ route('public.documentation.show', $filename) }}" target="_blank"
           class="btn btn-sm btn-outline-light" title="Open in new tab">
            <i class="bi bi-box-arrow-up-right"></i>
        </a>
    </div>

    <iframe id="docFrame"
            sandbox="allow-same-origin allow-scripts allow-popups"
            srcdoc="{{ htmlspecialchars($html, ENT_QUOTES, 'UTF-8') }}">
    </iframe>
</body>
</html>
