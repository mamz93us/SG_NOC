<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Offboarding Confirmation</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  body { background: #f4f6f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .card { max-width: 620px; width: 100%; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.10); }
  .header-bar { background: #dc3545; color: #fff; border-radius: 8px 8px 0 0; padding: 24px 28px; }
  .badge-field { background: #fff5f5; border: 1px solid #f5c2c7; color: #842029; padding: 4px 10px; border-radius: 20px; font-size: .82rem; }
</style>
</head>
<body>
@php
  $displayName = $payload['display_name'] ?? 'Employee';
  $upn         = $payload['upn'] ?? '—';
  $lastDay     = $payload['last_day'] ?? null;
  $reason      = $payload['reason'] ?? null;
  $hrRef       = $payload['hr_reference'] ?? '';
  $managerName = $token->manager_name ?? 'Manager';
@endphp

<div class="card">
  <div class="header-bar">
    <h4 class="mb-1 fw-bold">Employee Offboarding Confirmation</h4>
    <small class="opacity-75">Please review and confirm the following offboarding request</small>
  </div>
  <div class="card-body p-4">
    <p class="mb-3">Dear <strong>{{ $managerName }}</strong>,</p>
    <p class="text-muted mb-4">
      HR has submitted an offboarding request for the employee below.
      Your decision will determine whether IT proceeds with account deactivation.
    </p>

    <!-- Employee details -->
    <table class="table table-bordered table-sm mb-4">
      <tbody>
        <tr><th class="text-muted bg-light" style="width:160px">Employee</th><td><strong>{{ $displayName }}</strong></td></tr>
        <tr><th class="text-muted bg-light">Login (UPN)</th><td><code>{{ $upn }}</code></td></tr>
        @if($lastDay)<tr><th class="text-muted bg-light">Last Working Day</th><td class="text-danger fw-semibold">{{ $lastDay }}</td></tr>@endif
        @if($reason)<tr><th class="text-muted bg-light">Reason</th><td>{{ ucfirst($reason) }}</td></tr>@endif
        @if($hrRef)<tr><th class="text-muted bg-light">HR Reference</th><td>{{ $hrRef }}</td></tr>@endif
      </tbody>
    </table>

    <!-- Form -->
    <form method="POST" action="{{ url('/offboarding/respond') }}">
      @csrf
      <input type="hidden" name="token" value="{{ $token->token }}">

      <div class="mb-3">
        <label class="form-label fw-semibold">Your Decision <span class="text-danger">*</span></label>
        <div class="d-flex gap-3">
          <div class="form-check">
            <input class="form-check-input" type="radio" name="decision" id="dec_approved" value="approved" required>
            <label class="form-check-label text-success fw-semibold" for="dec_approved">Approve Offboarding</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="decision" id="dec_rejected" value="rejected">
            <label class="form-check-label text-danger fw-semibold" for="dec_rejected">Reject / Do Not Proceed</label>
          </div>
        </div>
      </div>

      <div class="mb-4">
        <label for="notes" class="form-label fw-semibold">Notes / Comments <span class="text-muted fw-normal">(optional)</span></label>
        <textarea id="notes" name="notes" rows="4" class="form-control"
          placeholder="Any special instructions, equipment to collect, forwarding address, etc."></textarea>
      </div>

      <div class="d-grid">
        <button type="submit" class="btn btn-danger btn-lg fw-bold">Submit Confirmation</button>
      </div>
    </form>

    <p class="text-muted small mt-3 mb-0">
      This link expires on <strong>{{ $token->expires_at?->format('d M Y, H:i') }}</strong>.
      For questions, contact HR quoting reference: <strong>{{ $hrRef }}</strong>.
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
