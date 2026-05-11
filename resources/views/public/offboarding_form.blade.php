<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Employee Offboarding · Manager Form</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  body { background:#f4f6f9; }
  .container-narrow { max-width: 820px; }
  .header-bar { background:#dc3545; color:#fff; border-radius:8px 8px 0 0; padding:24px 28px; }
  .step-card { border:none; box-shadow:0 2px 12px rgba(0,0,0,.05); margin-bottom:18px; }
  .step-card .card-header { background:#f8f9fa; font-weight:600; }
  .footprint-pill { background:#e7f1ff; color:#084298; padding:4px 10px; border-radius:14px; font-size:.82rem; display:inline-block; }
  .group-chip { background:#fffaf0; border:1px solid #ffecb5; padding:2px 8px; border-radius:12px; font-size:.78rem; display:inline-block; margin:2px 4px 2px 0; }
  .hint { font-size:.82rem; color:#6c757d; }
  .conditional-block { display:none; margin-top:12px; padding:12px; border-radius:6px; background:#f8f9fa; border:1px dashed #ced4da; }
  .conditional-block.visible { display:block; }
  .asset-row { border:1px solid #dee2e6; border-radius:6px; padding:10px 14px; margin-bottom:8px; display:flex; align-items:center; gap:12px; }
  .asset-row .meta { flex:1; }
  .asset-row .meta strong { display:block; }
</style>
</head>
<body class="py-4">

@php
    $payload     = $token->payload ?? [];
    $displayName = $payload['display_name'] ?? 'Employee';
    $upn         = $payload['upn']          ?? '—';
    $lastDay     = $payload['last_day']     ?? null;
    $reason      = $payload['reason']       ?? null;
    $hrRef       = $payload['hr_reference'] ?? $token->workflow_id;
    $managerName = $token->manager_name     ?? 'Manager';
    $live        = $payload['live_graph_data'] ?? [];
    $mailbox     = $live['mailbox']  ?? [];
    $onedrive    = $live['onedrive'] ?? [];
    $groups      = $live['groups']   ?? [];
    $assets      = $assets ?? collect();          // passed in by controller
    $activeEmps  = $activeEmployees ?? collect(); // passed in by controller

    $humanSize = function ($bytes) {
        if (! $bytes) return '—';
        $units = ['B','KB','MB','GB','TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) { $size /= 1024; $i++; }
        return sprintf('%.2f %s', $size, $units[$i]);
    };
@endphp

<div class="container container-narrow">
  <div class="header-bar">
    <h4 class="mb-1 fw-bold"><i class="bi bi-person-x-fill me-2"></i>Employee Offboarding · Manager Form</h4>
    <small class="opacity-75">HR Reference: {{ $hrRef }}</small>
  </div>

  <div class="card border-0 rounded-bottom shadow-sm">
    <div class="card-body p-4">
      <p class="mb-2">Dear <strong>{{ $managerName }}</strong>,</p>

      <div class="alert alert-info py-2 small mb-3">
        <i class="bi bi-info-circle-fill me-1"></i>
        <strong>Mailbox and OneDrive will be backed up to the NOC archive automatically.</strong>
        You will receive secure download links once each backup is ready (valid for 5 days).
      </div>

      <table class="table table-bordered table-sm mb-3">
        <tbody>
          <tr><th class="text-muted bg-light" style="width:180px">Employee</th><td><strong>{{ $displayName }}</strong></td></tr>
          <tr><th class="text-muted bg-light">Login (UPN)</th><td><code>{{ $upn }}</code></td></tr>
          @if($lastDay)<tr><th class="text-muted bg-light">Last Working Day</th><td class="text-danger fw-semibold">{{ $lastDay }}</td></tr>@endif
          @if($reason)<tr><th class="text-muted bg-light">Reason</th><td>{{ ucfirst($reason) }}</td></tr>@endif
          <tr>
            <th class="text-muted bg-light">Mailbox / OneDrive</th>
            <td>
              <span class="footprint-pill"><i class="bi bi-envelope me-1"></i>Mailbox: {{ $humanSize($mailbox['size_bytes'] ?? null) }}</span>
              <span class="footprint-pill"><i class="bi bi-cloud me-1"></i>OneDrive: {{ $humanSize($onedrive['size_bytes'] ?? null) }}</span>
            </td>
          </tr>
          @if(!empty($groups))
          <tr>
            <th class="text-muted bg-light">Groups</th>
            <td>
              @foreach($groups as $g)
                <span class="group-chip">{{ $g['display_name'] ?? '(unnamed)' }}</span>
              @endforeach
              <div class="hint mt-1">User will be removed from all groups during deprovisioning (security groups not shown).</div>
            </td>
          </tr>
          @endif
        </tbody>
      </table>

      <form method="POST" action="{{ url('/offboarding/respond') }}" id="offboardingForm">
        @csrf
        <input type="hidden" name="token" value="{{ $token->token }}">

        {{-- ── Q1: Email handling ── --}}
        <div class="card step-card">
          <div class="card-header">1 · Email — after the mandatory backup, what should happen?</div>
          <div class="card-body">
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" name="email_action" id="ea_delete" value="delete" required>
              <label class="form-check-label" for="ea_delete"><strong>Delete</strong> — remove the mailbox and unassign all licenses.</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="email_action" id="ea_forward" value="forward">
              <label class="form-check-label" for="ea_forward"><strong>Forward</strong> — auto-redirect incoming mail to other employee(s) for a set period.</label>
            </div>

            <div class="conditional-block" id="forwardBlock">
              <div class="mb-3">
                <label class="form-label fw-semibold">Forward to (one or more email addresses)</label>
                <input type="text" name="forward_emails" id="forwardEmailsInput" class="form-control"
                       placeholder="alice@samirgroup.com, bob@samirgroup.com">
                <div class="hint">Comma-separated. Each address must already exist in your tenant.</div>
              </div>
              <div class="mb-1">
                <label class="form-label fw-semibold">Duration</label>
                <select name="forward_duration_days" class="form-select">
                  <option value="1">1 day</option>
                  <option value="7">7 days</option>
                  <option value="14" selected>14 days</option>
                  <option value="30">30 days</option>
                  <option value="60">60 days</option>
                  <option value="90">90 days (maximum)</option>
                </select>
                <div class="hint">The forwarding rule is removed automatically at the end of this period, and the Azure user is deleted thereafter.</div>
              </div>
            </div>
          </div>
        </div>

        {{-- ── Q2: Laptop data ── --}}
        <div class="card step-card">
          <div class="card-header">2 · Laptop data — what should IT do before wiping the device?</div>
          <div class="card-body">
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" name="laptop_action" id="la_backup" value="backup" required>
              <label class="form-check-label" for="la_backup"><strong>Back up</strong> — IT will extract data from the laptop and upload it. You'll receive a download link.</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="laptop_action" id="la_delete" value="delete">
              <label class="form-check-label" for="la_delete"><strong>Delete</strong> — no extraction; the device is wiped via Intune.</label>
            </div>
            <div class="hint mt-2">If you pick "backup", the Intune wipe is held until IT confirms the upload.</div>
          </div>
        </div>

        {{-- ── Q3: Assets ── --}}
        <div class="card step-card">
          <div class="card-header">3 · Assigned assets — where should they go?</div>
          <div class="card-body">
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" name="asset_action" id="aa_it" value="return_to_it" required>
              <label class="form-check-label" for="aa_it"><strong>Return to IT inventory</strong></label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="asset_action" id="aa_transfer" value="transfer">
              <label class="form-check-label" for="aa_transfer"><strong>Transfer to another active employee</strong></label>
            </div>

            <div class="conditional-block" id="transferBlock">
              <label class="form-label fw-semibold">Target employee</label>
              <select name="asset_target_employee_id" class="form-select">
                <option value="">— Select an active employee —</option>
                @foreach($activeEmps as $emp)
                  <option value="{{ $emp->id }}">{{ $emp->name }} ({{ $emp->email }})</option>
                @endforeach
              </select>
              <div class="hint">All currently-assigned assets will transfer to this employee.</div>
            </div>
          </div>
        </div>

        {{-- ── Q4: Retrieval tasks ── --}}
        @if($assets->isNotEmpty())
        <div class="card step-card">
          <div class="card-header">4 · Retrieval tasks — IT will create a task for each "Yes"</div>
          <div class="card-body">
            <div class="hint mb-2">Default: laptop and IP phone are toggled on.</div>
            @foreach($assets as $a)
              @php
                $isLaptop = stripos($a->device?->asset_code ?? '', '-LAP-') !== false
                          || stripos($a->device?->device_type ?? '', 'laptop') !== false;
                $isPhone  = stripos($a->device?->asset_code ?? '', '-PHN-') !== false
                          || stripos($a->device?->device_type ?? '', 'phone') !== false;
                $default  = $isLaptop || $isPhone ? 'checked' : '';
              @endphp
              <div class="asset-row">
                <div class="meta">
                  <strong>{{ $a->device?->asset_code ?? 'Asset' }}</strong>
                  <span class="text-muted small">
                    {{ $a->device?->device_type ?? '' }}
                    @if($a->device?->serial_number) · {{ $a->device->serial_number }} @endif
                  </span>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox"
                         name="retrieve[{{ $a->id }}]" value="1" id="ret_{{ $a->id }}" {{ $default }}>
                  <label class="form-check-label" for="ret_{{ $a->id }}">Create task</label>
                </div>
              </div>
            @endforeach
          </div>
        </div>
        @endif

        {{-- ── Notes + Submit ── --}}
        <div class="card step-card">
          <div class="card-header">5 · Additional notes <small class="text-muted fw-normal">(optional)</small></div>
          <div class="card-body">
            <textarea name="notes" rows="3" class="form-control" maxlength="2000"
                      placeholder="Anything IT should know — special instructions, escalation contacts, etc."></textarea>
          </div>
        </div>

        <div class="d-flex justify-content-between gap-2">
          <button type="submit" name="decision" value="rejected" class="btn btn-outline-secondary"
                  onclick="return confirm('Reject this offboarding? This will cancel the workflow.');">
            <i class="bi bi-x-circle me-1"></i>Reject / Do Not Proceed
          </button>
          <button type="submit" name="decision" value="approved" class="btn btn-danger btn-lg fw-bold">
            <i class="bi bi-check-circle me-1"></i>Approve & Start Offboarding
          </button>
        </div>

        <p class="hint mt-3">
          This link expires on <strong>{{ $token->expires_at?->format('d M Y, H:i') }}</strong>.
        </p>
      </form>
    </div>
  </div>
</div>

<script>
function toggleBlock(radioName, block) {
  document.querySelectorAll(`input[name="${radioName}"]`).forEach(r => {
    r.addEventListener('change', () => {
      const blockEl = document.getElementById(block);
      if (! blockEl) return;
      const show = r.checked && r.dataset.target === 'show';
      blockEl.classList.toggle('visible', show);
    });
  });
}

document.querySelector('#ea_forward').dataset.target = 'show';
document.querySelector('#ea_delete').dataset.target  = 'hide';
toggleBlock('email_action', 'forwardBlock');

document.querySelector('#aa_transfer').dataset.target = 'show';
document.querySelector('#aa_it').dataset.target       = 'hide';
toggleBlock('asset_action', 'transferBlock');
</script>
</body>
</html>
