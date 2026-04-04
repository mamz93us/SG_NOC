<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentation — {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .doc-card { transition: transform .15s, box-shadow .15s; cursor: pointer; text-decoration: none; color: inherit; }
        .doc-card:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(0,0,0,.12) !important; }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="/">
                <i class="bi bi-house-fill me-1"></i>{{ config('app.name', 'SG NOC') }}
            </a>
            @auth
            <a href="{{ route('admin.documentation.index') }}" class="btn btn-sm btn-outline-light">
                <i class="bi bi-gear me-1"></i>Manage
            </a>
            @endauth
        </div>
    </nav>

    <div class="container py-5">
        <div class="mb-4">
            <h2 class="fw-bold"><i class="bi bi-book-fill me-2 text-primary"></i>Documentation</h2>
            <p class="text-muted">Public documents and reports</p>
        </div>

        @if($files->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-folder2-open" style="font-size:3rem"></i>
                <p class="mt-3">No public documents available yet.</p>
            </div>
        @else
            <div class="row g-4">
                @foreach($files as $doc)
                <div class="col-md-4 col-sm-6">
                    <a href="{{ route('public.documentation.show', $doc['name']) }}"
                       class="card shadow-sm doc-card h-100">
                        <div class="card-body">
                            <div class="d-flex gap-3 align-items-start">
                                <div class="bg-primary bg-opacity-10 rounded p-2" style="flex-shrink:0">
                                    <i class="bi bi-file-earmark-code-fill text-primary" style="font-size:1.6rem"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-1">{{ $doc['title'] ?: $doc['name'] }}</h6>
                                    @if($doc['description'])
                                        <p class="text-muted small mb-2">{{ $doc['description'] }}</p>
                                    @endif
                                    <small class="text-muted">
                                        {{ \Carbon\Carbon::createFromTimestamp($doc['modified'])->diffForHumans() }}
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-top-0 pt-0">
                            <span class="text-primary small fw-semibold">
                                <i class="bi bi-arrow-right-circle me-1"></i>Open document
                            </span>
                        </div>
                    </a>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</body>
</html>
