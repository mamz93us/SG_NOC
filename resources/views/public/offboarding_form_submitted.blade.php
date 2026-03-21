<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Offboarding — Response Recorded</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  body { background: #f4f6f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { max-width: 520px; width: 100%; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.10); border-radius: 10px; }
</style>
</head>
<body>
<div class="card p-5 text-center">
  @if(!empty($error) && $error)
    <div style="font-size:3rem;">⚠️</div>
    <h4 class="mt-3 mb-2 text-danger fw-bold">Invalid or Expired Link</h4>
    <p class="text-muted">{{ $message ?? 'This link is invalid or has already been used.' }}</p>
    <p class="small text-muted">Please contact HR or IT if you believe this is an error.</p>
  @elseif(!empty($decision) && $decision === 'approved')
    <div style="font-size:3rem;">✅</div>
    <h4 class="mt-3 mb-2 text-success fw-bold">Offboarding Approved</h4>
    @php $displayName = $payload['display_name'] ?? 'the employee'; @endphp
    <p class="text-muted">
      Your approval has been recorded. IT will now proceed with the account deactivation
      process for <strong>{{ $displayName }}</strong>.
    </p>
    <p class="small text-muted">A confirmation email will be sent upon completion.</p>
  @else
    <div style="font-size:3rem;">🚫</div>
    <h4 class="mt-3 mb-2 text-warning fw-bold">Offboarding Rejected</h4>
    @php $displayName = $payload['display_name'] ?? 'the employee'; @endphp
    <p class="text-muted">
      Your rejection has been recorded. The offboarding workflow for
      <strong>{{ $displayName }}</strong> has been cancelled. HR has been notified.
    </p>
  @endif

  <hr class="my-4">
  <p class="text-muted small mb-0">SG NOC IT Management System</p>
</div>
</body>
</html>
