<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Employee Setup Form</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  body { background: #f4f6f9; min-height: 100vh; display: flex; align-items: flex-start; justify-content: center; padding: 32px 16px; }
  .card { max-width: 680px; width: 100%; border: none; box-shadow: 0 4px 20px rgba(0,0,0,.10); }
  .header-bar { background: #0d6efd; color: #fff; border-radius: 8px 8px 0 0; padding: 24px 28px; }
  .section-title { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #6c757d; margin-bottom: 12px; }
  .select2-group { height: auto !important; }
</style>
</head>
<body>
@php
  $displayName = $payload['display_name'] ?? 'New Employee';
  $upn         = $payload['upn'] ?? '—';
  $hrRef       = $payload['hr_reference'] ?? '';
  $startDate   = $payload['start_date'] ?? null;
  $managerEmail= $tokenRecord->manager_email ?? '—';
  $managerName = $tokenRecord->manager_name ?? 'Manager';
@endphp

<div class="card">
  <div class="header-bar">
    <h4 class="mb-1 fw-bold"><i class="bi bi-person-plus-fill me-2"></i>New Employee Setup Form</h4>
    <small class="opacity-75">Please fill in the required information to complete IT setup</small>
  </div>
  <div class="card-body p-4">

    {{-- Employee Info (read-only) --}}
    <div class="mb-4">
      <div class="section-title"><i class="bi bi-person me-1"></i>Employee Details</div>
      <table class="table table-bordered table-sm mb-0">
        <tbody>
          <tr>
            <th class="text-muted bg-light" style="width:160px">Employee Name</th>
            <td><strong>{{ $displayName }}</strong></td>
          </tr>
          <tr>
            <th class="text-muted bg-light">Email (UPN)</th>
            <td><code>{{ $upn }}</code></td>
          </tr>
          @if($startDate)
          <tr>
            <th class="text-muted bg-light">Start Date</th>
            <td class="text-success fw-semibold">{{ $startDate }}</td>
          </tr>
          @endif
          @if($hrRef)
          <tr>
            <th class="text-muted bg-light">HR Reference</th>
            <td>{{ $hrRef }}</td>
          </tr>
          @endif
          <tr>
            <th class="text-muted bg-light">Manager</th>
            <td>{{ $managerName }} &lt;{{ $managerEmail }}&gt;</td>
          </tr>
        </tbody>
      </table>
    </div>

    @if($errors->any())
    <div class="alert alert-danger py-2">
      <ul class="mb-0 small ps-3">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
    @endif

    <form method="POST" action="{{ url('/onboarding/form/' . $tokenRecord->token) }}">
      @csrf

      {{-- Laptop --}}
      <div class="mb-4">
        <div class="section-title"><i class="bi bi-laptop me-1"></i>Laptop Assignment</div>
        <label class="form-label fw-semibold">Laptop Status <span class="text-danger">*</span></label>
        <div class="d-flex gap-3 flex-wrap">
          @foreach(['new' => 'New Laptop', 'used' => 'Used / Refurbished Laptop', 'none' => 'No Laptop Needed'] as $val => $label)
          <div class="form-check">
            <input class="form-check-input" type="radio" name="laptop_status" id="ls_{{ $val }}"
                   value="{{ $val }}" {{ old('laptop_status') === $val ? 'checked' : '' }} required>
            <label class="form-check-label" for="ls_{{ $val }}">{{ $label }}</label>
          </div>
          @endforeach
        </div>
      </div>

      {{-- IP Phone Extension --}}
      <div class="mb-4">
        <div class="section-title"><i class="bi bi-telephone me-1"></i>IP Phone / Extension</div>
        <label class="form-label fw-semibold">Does this employee need an IP phone extension? <span class="text-danger">*</span></label>
        <div class="d-flex gap-3">
          <div class="form-check">
            <input class="form-check-input" type="radio" name="needs_extension" id="ext_yes"
                   value="yes" {{ old('needs_extension') === 'yes' ? 'checked' : '' }} required>
            <label class="form-check-label" for="ext_yes">Yes — assign extension</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="needs_extension" id="ext_no"
                   value="no" {{ old('needs_extension') === 'no' ? 'checked' : '' }}>
            <label class="form-check-label" for="ext_no">No — skip extension</label>
          </div>
        </div>
      </div>

      {{-- Floor / Location --}}
      <div class="mb-4">
        <div class="section-title"><i class="bi bi-building me-1"></i>Floor / Location</div>
        <label class="form-label fw-semibold">Employee's Floor <span class="text-muted fw-normal">(determines extension range &amp; printer)</span></label>
        <select name="floor_id" class="form-select">
          <option value="">— No specific floor —</option>
          @foreach($floors as $floor)
          <option value="{{ $floor->id }}" {{ old('floor_id') == $floor->id ? 'selected' : '' }}>
            {{ $floor->name }}
            @if($floor->ext_range_start && $floor->ext_range_end)
              (ext {{ $floor->ext_range_start }}–{{ $floor->ext_range_end }})
            @endif
          </option>
          @endforeach
        </select>
        <div class="form-text">Choose the floor where the employee will be working.</div>
      </div>

      {{-- Internet Level --}}
      <div class="mb-4">
        <div class="section-title"><i class="bi bi-wifi me-1"></i>Internet Access Level</div>
        <label class="form-label fw-semibold">Internet Level <span class="text-danger">*</span></label>
        <div class="row g-2">
          @foreach([
            'business' => ['label' => 'Business', 'desc' => 'Standard business access', 'color' => 'secondary'],
            'site'     => ['label' => 'Site', 'desc' => 'Site-wide access', 'color' => 'info'],
            'high'     => ['label' => 'High', 'desc' => 'Elevated access', 'color' => 'warning'],
            'vip'      => ['label' => 'VIP', 'desc' => 'Unrestricted access', 'color' => 'danger'],
          ] as $val => $item)
          <div class="col-6 col-md-3">
            <label class="d-block border rounded p-2 cursor-pointer {{ old('internet_level') === $val ? 'border-primary bg-light' : '' }}" style="cursor:pointer">
              <input class="form-check-input me-1" type="radio" name="internet_level" value="{{ $val }}"
                     {{ old('internet_level') === $val ? 'checked' : '' }} required>
              <span class="badge bg-{{ $item['color'] }} me-1">{{ $item['label'] }}</span>
              <small class="text-muted d-block mt-1" style="font-size:.75rem">{{ $item['desc'] }}</small>
            </label>
          </div>
          @endforeach
        </div>
      </div>

      {{-- Groups --}}
      <div class="mb-4">
        <div class="section-title"><i class="bi bi-people me-1"></i>Group Memberships</div>
        <label class="form-label fw-semibold">Azure / Identity Groups <span class="text-muted fw-normal">(optional — auto-provisioning groups are also applied)</span></label>
        <select name="selected_groups[]" class="form-select" multiple size="8" id="groupsSelect">
          @foreach($groups as $group)
          <option value="{{ $group->id }}"
                  {{ in_array($group->id, old('selected_groups', [])) ? 'selected' : '' }}>
            {{ $group->display_name }}
            @if($group->type) ({{ $group->type }}) @endif
          </option>
          @endforeach
        </select>
        <div class="form-text">Hold <kbd>Ctrl</kbd> (or <kbd>Cmd</kbd> on Mac) to select multiple groups.</div>
      </div>

      {{-- Comments --}}
      <div class="mb-4">
        <div class="section-title"><i class="bi bi-chat-text me-1"></i>Additional Comments</div>
        <label for="manager_comments" class="form-label fw-semibold">Comments / Special Instructions <span class="text-muted fw-normal">(optional)</span></label>
        <textarea id="manager_comments" name="manager_comments" rows="4" class="form-control"
          placeholder="Any special requirements, preferred setup notes, or additional information…">{{ old('manager_comments') }}</textarea>
      </div>

      <div class="d-grid">
        <button type="submit" class="btn btn-primary btn-lg fw-bold">
          <i class="bi bi-check-circle me-2"></i>Submit Setup Form
        </button>
      </div>
    </form>

    <p class="text-muted small mt-3 mb-0">
      This link expires on <strong>{{ $tokenRecord->expires_at?->format('d M Y, H:i') }}</strong>.
      @if($hrRef) For questions, contact IT quoting reference: <strong>{{ $hrRef }}</strong>. @endif
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
