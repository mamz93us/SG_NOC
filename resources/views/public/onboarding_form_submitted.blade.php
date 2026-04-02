<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Form Submitted — Thank You</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background: #f4f6f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { max-width: 540px; width: 100%; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.10); }
</style>
</head>
<body>
@php
  $payload     = $workflow?->payload ?? [];
  $displayName = $payload['display_name'] ?? 'the new employee';
  $hrRef       = $payload['hr_reference'] ?? '';
@endphp

<div class="card text-center">
  <div class="card-body p-5">
    <div class="mb-4">
      <i class="bi bi-check-circle-fill text-success" style="font-size:4rem"></i>
    </div>
    <h3 class="fw-bold mb-2">Form Submitted!</h3>
    <p class="text-muted mb-4">
      Thank you. The IT team will now complete the setup for <strong>{{ $displayName }}</strong>
      based on the information you provided.
    </p>

    <div class="alert alert-light border text-start small mb-4 p-3">
      <div class="fw-semibold mb-2">What happens next:</div>
      <ul class="mb-0 ps-3">
        <li>IT will create the user account and phone extension</li>
        <li>Group memberships and internet access will be configured</li>
        @if(in_array($tokenRecord->laptop_status ?? '', ['new', 'used']))
        <li>A laptop assignment task will be created for your new employee</li>
        @endif
        @if($tokenRecord->needs_extension)
        <li>An IP phone configuration task will be created</li>
        @endif
        <li>You will be notified once everything is ready</li>
      </ul>
    </div>

    @if($hrRef)
    <p class="text-muted small mb-0">HR Reference: <strong>{{ $hrRef }}</strong></p>
    @endif
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
