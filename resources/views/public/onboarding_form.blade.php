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

  /* Group picker */
  .group-list {
    max-height: 280px;
    overflow-y: auto;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 4px 0;
  }
  .group-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 14px;
    cursor: pointer;
    border-radius: 4px;
    transition: background 0.12s;
    user-select: none;
  }
  .group-item:hover { background: #eef2ff; }
  .group-item input[type=checkbox] { flex-shrink: 0; width: 17px; height: 17px; cursor: pointer; }
  .group-item .group-name { flex: 1; font-size: .9rem; color: #212529; }
  .group-item.hidden { display: none; }
  .group-empty { padding: 14px; text-align: center; color: #6c757d; font-size: .875rem; display: none; }
  #groupSearch:focus { box-shadow: 0 0 0 .2rem rgba(13,110,253,.25); border-color: #86b7fe; }
  .selected-count { font-size: .8rem; color: #6c757d; margin-top: 6px; }
  .selected-count strong { color: #0d6efd; }
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
          @foreach($internetLevels as $level)
          <div class="col-6 col-md-3">
            <label class="d-block border rounded p-2 {{ old('internet_level_id') == $level->id ? 'border-primary bg-light' : '' }}" style="cursor:pointer">
              <input class="form-check-input me-1" type="radio" name="internet_level_id" value="{{ $level->id }}"
                     {{ old('internet_level_id') == $level->id || ($level->is_default && !old('internet_level_id')) ? 'checked' : '' }} required>
              <span class="badge bg-primary me-1">{{ $level->label }}</span>
              <small class="text-muted d-block mt-1" style="font-size:.75rem">{{ $level->description }}</small>
            </label>
          </div>
          @endforeach
        </div>
      </div>

      {{-- Groups (searchable checkbox list — security groups excluded by controller) --}}
      <div class="mb-4">
        <div class="section-title"><i class="bi bi-people me-1"></i>Group Memberships</div>
        <label class="form-label fw-semibold">
          Azure / Identity Groups
          <span class="text-muted fw-normal">(optional — auto-provisioning groups are also applied)</span>
        </label>

        @if($groups->isNotEmpty())
          {{-- Search input --}}
          <input type="text" id="groupSearch" class="form-control form-control-sm mb-2"
                 placeholder="Search groups…" autocomplete="off">

          {{-- Scrollable checkbox list --}}
          <div class="group-list" id="groupList">
            @foreach($groups as $group)
            <label class="group-item" data-name="{{ strtolower($group->display_name) }}">
              <input type="checkbox"
                     name="selected_groups[]"
                     value="{{ $group->id }}"
                     {{ in_array($group->id, old('selected_groups', [])) ? 'checked' : '' }}>
              <span class="group-name">{{ $group->display_name }}</span>
              @if($group->group_type === 'Unified')
                <span class="badge bg-primary" style="font-size:.65rem">M365</span>
              @else
                <span class="badge bg-secondary" style="font-size:.65rem">Distribution</span>
              @endif
            </label>
            @endforeach
            <div class="group-empty" id="groupEmpty">No groups match your search.</div>
          </div>

          <div class="selected-count">
            <strong id="groupCount">0</strong> group(s) selected
          </div>
        @else
          <p class="text-muted small">No groups available to assign.</p>
        @endif
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
<script>
(function () {
  const searchInput = document.getElementById('groupSearch');
  const groupList   = document.getElementById('groupList');
  const groupEmpty  = document.getElementById('groupEmpty');
  const groupCount  = document.getElementById('groupCount');

  if (!searchInput) return; // groups section not rendered

  // Live search filter
  searchInput.addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('.group-item').forEach(function (item) {
      const match = !q || item.dataset.name.includes(q);
      item.classList.toggle('hidden', !match);
      if (match) visible++;
    });
    groupEmpty.style.display = visible === 0 ? 'block' : 'none';
  });

  // Live selected count
  function updateCount() {
    const n = document.querySelectorAll('input[name="selected_groups[]"]:checked').length;
    groupCount.textContent = n;
  }

  document.querySelectorAll('input[name="selected_groups[]"]').forEach(function (cb) {
    cb.addEventListener('change', updateCount);
  });

  updateCount(); // initialise on page load (handles old() values)
})();
</script>
</body>
</html>
