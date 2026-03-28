<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $form->name }}</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
body { background:#f4f6f9; min-height:100vh; display:flex; align-items:center; justify-content:center; }
.card { max-width:520px; width:100%; border:none; box-shadow:0 4px 20px rgba(0,0,0,.08); border-radius:12px; }
</style>
</head>
<body>
<div class="card text-center p-5">
    @if($error ?? false)
    <div class="display-1 mb-3 text-danger"><i class="bi bi-exclamation-circle"></i></div>
    <h4 class="fw-bold text-danger">Unable to Process</h4>
    <p class="text-muted">{{ $message ?? 'An error occurred. Please try again or contact support.' }}</p>
    @else
    <div class="display-1 mb-3 text-success"><i class="bi bi-check-circle-fill"></i></div>
    <h4 class="fw-bold">{{ $form->name }}</h4>
    <p class="text-muted">{{ $message ?? 'Thank you! Your response has been recorded.' }}</p>
    @endif
</div>
</body>
</html>
