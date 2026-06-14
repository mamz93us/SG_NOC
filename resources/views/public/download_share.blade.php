<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $file ? $file->title : 'Download Unavailable' }}</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  body { background: #f4f6f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { max-width: 520px; width: 100%; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.10); border-radius: 10px; }
</style>
</head>
<body>
<div class="card p-5 text-center">
  @if(! $file)
    <div style="font-size:3rem;">⚠️</div>
    <h4 class="mt-3 mb-2 text-danger fw-bold">Link Unavailable</h4>
    <p class="text-muted">This download link is invalid or has expired.</p>
    <p class="small text-muted">Please contact the person who shared it for a new link.</p>
  @else
    <i class="bi bi-file-earmark-arrow-down text-primary" style="font-size:3rem;"></i>
    <h4 class="mt-3 mb-1 fw-bold">{{ $file->title }}</h4>
    <p class="text-muted mb-1">{{ $file->original_filename }}</p>
    <p class="small text-muted">{{ $file->humanSize() }}</p>
    <a href="{{ route('downloads.share.download', $file->public_token) }}"
       class="btn btn-primary btn-lg mt-2">
      <i class="bi bi-download me-2"></i>Download
    </a>
    @if($file->public_expires_at)
      <p class="small text-muted mt-3 mb-0">Link expires {{ $file->public_expires_at->format('Y-m-d H:i') }}</p>
    @endif
  @endif

  <hr class="my-4">
  <p class="text-muted small mb-0">SG NOC IT Management System</p>
</div>
</body>
</html>
