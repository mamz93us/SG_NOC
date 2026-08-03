@extends('layouts.admin')
@section('title', 'Add Group Mapping')

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center">
    <div class="col-lg-7">

      <div class="d-flex align-items-center mb-4">
        <a href="{{ '/admin/identity/group-mappings' }}" class="btn btn-sm btn-outline-secondary me-3">
          <i class="bi bi-arrow-left"></i>
        </a>
        <div>
          <h4 class="mb-0 fw-bold">Add Group Mapping</h4>
          <small class="text-muted">Define which Azure AD group to assign when a new user matches a branch and/or department.</small>
        </div>
      </div>

      @if($errors->any())
        <div class="alert alert-danger shadow-sm">
          <ul class="mb-0 ps-3">
            @foreach($errors->all() as $e)
              <li>{{ $e }}</li>
            @endforeach
          </ul>
        </div>
      @endif

      <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
          <form method="POST" action="/admin/identity/group-mappings">
            @csrf

            <div class="mb-4">
              <label class="form-label fw-semibold">
                Branch
                <span class="text-muted fw-normal small ms-1">(leave blank = applies to all branches)</span>
              </label>
              <select name="branch_id" id="branchSelect" class="form-select">
                <option value="">Any Branch</option>
                @foreach($branches as $b)
                  <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                @endforeach
              </select>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">
                Department
                <span class="text-muted fw-normal small ms-1">(leave blank = applies to all departments)</span>
              </label>
              <select name="department_id" class="form-select">
                <option value="">Any Department</option>
                @foreach($departments as $d)
                  <option value="{{ $d->id }}" @selected(old('department_id') == $d->id)>{{ $d->name }}</option>
                @endforeach
              </select>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">
                Floor
                <span class="text-muted fw-normal small ms-1">(leave blank = applies to all floors)</span>
              </label>
              <select name="floor_id" id="floorSelect" class="form-select">
                <option value="">Any Floor</option>
                @foreach($floors as $f)
                  <option value="{{ $f->id }}" data-branch="{{ $f->branch_id }}"
                          @selected(old('floor_id') == $f->id)>
                    {{ $f->branch?->name ? $f->branch->name.' — ' : '' }}{{ $f->name }}
                  </option>
                @endforeach
              </select>
              <div class="form-text" id="floorHelp">
                Use this for per-floor groups such as printer access. The floor is chosen by the
                manager on the onboarding form, so a floor-specific mapping is applied
                <strong>after</strong> the manager replies — not at account creation.
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">
                Gender
                <span class="text-muted fw-normal small ms-1">(leave blank = applies to everyone)</span>
              </label>
              <select name="gender" class="form-select">
                <option value="">Any Gender</option>
                <option value="female" @selected(old('gender') === 'female')>Female only</option>
                <option value="male" @selected(old('gender') === 'male')>Male only</option>
              </select>
              <div class="form-text">
                Matched against the gender HR records on the onboarding form. An employee
                with no gender on file only ever matches “Any Gender” mappings.
              </div>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">
                Azure Group <span class="text-danger">*</span>
              </label>
              <select name="identity_group_id" class="form-select" required>
                <option value="">— Select a group —</option>
                @foreach($groups as $g)
                  <option value="{{ $g->id }}" @selected(old('identity_group_id') == $g->id)>
                    {{ $g->display_name }}
                    @if($g->group_type === 'Unified') (M365)
                    @elseif($g->security_enabled) (Security)
                    @else (Distribution)
                    @endif
                  </option>
                @endforeach
              </select>
              <div class="form-text">Groups are synced from Azure AD. If a group is missing, run the identity sync.</div>
            </div>

            <div class="mb-4 d-flex align-items-center gap-3">
              <div class="form-check form-switch">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
                       @checked(old('is_active', '1') == '1')>
                <label class="form-check-label" for="isActive">Active</label>
              </div>
              <small class="text-muted">Inactive mappings are skipped during provisioning.</small>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">Notes</label>
              <input type="text" name="notes" class="form-control" maxlength="500"
                     placeholder="Optional description, e.g. 'All Cairo Sales staff'" value="{{ old('notes') }}">
            </div>

            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-lg me-1"></i>Save Mapping
              </button>
              <a href="{{ '/admin/identity/group-mappings' }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-3 bg-light">
        <div class="card-body py-3 px-4">
          <p class="mb-1 small fw-semibold text-muted">How this works</p>
          <p class="mb-0 small text-muted">
            When a new user is provisioned via a workflow, the system checks all active mappings.
            A mapping applies when <em>branch</em>, <em>department</em>, <em>floor</em> and
            <em>gender</em> all match — a field left as <em>Any</em> matches everyone.
            Multiple mappings can match; all matching groups are assigned.
            Floor is the exception on timing: it is only known once the manager returns the
            onboarding form, so floor-specific groups are applied at that point rather than
            at account creation.
          </p>
        </div>
      </div>

    </div>
  </div>
</div>

@push('scripts')
<script>
// Floors belong to a branch, so only offer the ones that can actually occur
// with the selected branch. Picking a floor from another branch would create a
// mapping that can never match anything.
(function () {
    const branchSelect = document.getElementById('branchSelect');
    const floorSelect  = document.getElementById('floorSelect');
    const floorHelp    = document.getElementById('floorHelp');
    if (!branchSelect || !floorSelect) return;

    // Keep the full list so options can be restored when the branch changes.
    const allOptions = Array.from(floorSelect.options).map(o => ({
        value: o.value,
        text: o.textContent.trim(),
        branch: o.dataset.branch || '',
    }));
    const defaultHelp = floorHelp.innerHTML;

    function rebuild() {
        const branchId = branchSelect.value;
        const previous = floorSelect.value;

        floorSelect.innerHTML = '';

        allOptions
            // Always keep "Any Floor"; otherwise keep floors of the chosen
            // branch. With no branch chosen, every floor is still valid.
            .filter(o => o.value === '' || !branchId || o.branch === branchId)
            .forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.value;
                opt.dataset.branch = o.branch;
                // The branch prefix is noise once the list is already filtered.
                opt.textContent = (branchId && o.value !== '' && o.text.includes(' — '))
                    ? o.text.split(' — ').slice(1).join(' — ')
                    : o.text;
                floorSelect.appendChild(opt);
            });

        // Keep the previous choice if it survived the filter, else fall back
        // to "Any Floor" rather than silently selecting someone else's floor.
        floorSelect.value = Array.from(floorSelect.options).some(o => o.value === previous)
            ? previous
            : '';

        const floorCount = floorSelect.options.length - 1;
        if (branchId && floorCount === 0) {
            floorHelp.innerHTML = '<span class="text-warning">' +
                '<i class="bi bi-exclamation-triangle me-1"></i>' +
                'This branch has no floors defined yet — add them under Network → Floors.</span>';
            floorSelect.disabled = true;
        } else {
            floorHelp.innerHTML = defaultHelp;
            floorSelect.disabled = false;
        }
    }

    branchSelect.addEventListener('change', rebuild);
    rebuild();
})();
</script>
@endpush
@endsection
