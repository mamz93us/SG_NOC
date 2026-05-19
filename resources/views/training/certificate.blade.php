<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $certificate->course?->name ?? 'Certificate' }} — Certificate</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body { background:#f4f6f8; }
        .cert-frame { width:100%; height:75vh; border:1px solid #dee2e6; border-radius:.5rem; background:#fff; }
        .cert-img   { max-width:100%; max-height:75vh; box-shadow:0 4px 16px rgba(0,0,0,.08); border-radius:.5rem; background:#fff; }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">{{ $certificate->course?->name ?? 'Certificate' }}</h4>
            @if ($certificate->employee?->name)
                <div class="text-muted small">Awarded to {{ $certificate->employee->name }}</div>
            @endif
        </div>
        <div>
            <a href="{{ route('certificates.download', ['token' => $certificate->token]) }}?download=1"
               class="btn btn-primary">
                <i class="bi bi-download me-1"></i>Download
            </a>
        </div>
    </div>

    <div class="text-center mb-4">
        @if ($isPdf)
            <iframe class="cert-frame"
                    src="{{ route('certificates.download', ['token' => $certificate->token]) }}"
                    title="Certificate"></iframe>
        @else
            <img class="cert-img"
                 src="{{ route('certificates.download', ['token' => $certificate->token]) }}"
                 alt="Certificate">
        @endif
    </div>

    <p class="text-muted small text-center mb-0">
        This is your personal access link. Bookmark it — it never expires.
        If you weren't expecting this, simply ignore the message.
    </p>
</div>
</body>
</html>
