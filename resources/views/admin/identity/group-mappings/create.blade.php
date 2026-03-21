@extends('layouts.admin')
@section('title', 'Add Group Mapping')

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center">
    <div class="col-lg-7">

      <div class="d-flex align-items-center mb-4">
        <a href="{{ route('admin.identity.group-mappings.index') }}" class="btn btn-sm btn-outline-secondary me-3">
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
          <form method="POST" action="{{ route('admin.identity.group-mappings.store') }}">
            @csrf

            <div class="mb-4">
              <label class="form-label fw-semibold">
                Branch
                <span class="text-muted fw-normal small ms-1">(leave blank = applies to all branches)</span>
              </label>
              <select name="branch_id" class="form-select">
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
              <a href="{{ route('admin.identity.group-mappings.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>

      <div class="card border-0 shadow-sm mt-3 bg-light">
        <div class="card-body py-3 px-4">
          <p class="mb-1 small fw-semibold text-muted">How this works</p>
          <p class="mb-0 small text-muted">
            When a new user is provisioned via a workflow, the system checks all active mappings.
            If both <em>branch</em> and <em>department</em> match (or are set to <em>Any</em>),
            the user is automatically added to that Azure AD group.
            Multiple mappings can match — all matching groups will be assigned.
          </p>
        </div>
      </div>

    </div>
  </div>
</div>
@endsection
