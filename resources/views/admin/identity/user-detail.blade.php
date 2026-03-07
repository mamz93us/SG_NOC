@extends('layouts.admin')
@section('content')

{{-- ── Page Header ──────────────────────────────────────────────────────── --}}
<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="d-flex align-items-center gap-3">
        <a href="{{ route('admin.identity.users') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold"
             style="width:52px;height:52px;font-size:1.1rem;flex-shrink:0">
            {{ $user->initials() }}
        </div>
        <div>
            <h4 class="mb-0 fw-bold">{{ $user->display_name }}</h4>
            <div class="text-muted small">{{ $user->user_principal_name }}</div>
            @if($user->job_title || $user->department)
            <div class="text-muted small">
                {{ implode(' · ', array_filter([$user->job_title, $user->department])) }}
            </div>
            @endif
        </div>
        <span class="badge {{ $user->statusBadgeClass() }} ms-1">{{ $user->statusLabel() }}</span>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @can('manage-identity')
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editProfileModal">
            <i class="bi bi-pencil me-1"></i>Edit Profile
        </button>
        <form method="POST" action="{{ route('admin.identity.user.toggle', $user->azure_id) }}" class="d-inline">
            @csrf @method('PATCH')
            <button type="submit"
                    class="btn btn-sm btn-outline-{{ $user->account_enabled ? 'warning' : 'success' }}"
                    onclick="return confirm('{{ $user->account_enabled ? 'Disable' : 'Enable' }} this user?')">
                <i class="bi bi-{{ $user->account_enabled ? 'lock' : 'unlock' }} me-1"></i>
                {{ $user->account_enabled ? 'Disable' : 'Enable' }}
            </button>
        </form>
        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#resetPwModal">
            <i class="bi bi-key me-1"></i>Reset Password
        </button>
        @endcan
    </div>
</div>

{{-- ── Flash Messages ───────────────────────────────────────────────────── --}}
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2">
    <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2">
    <i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- ── Row 1: Profile + Contact ─────────────────────────────────────────── --}}
<div class="row g-3 mb-3">

    {{-- Profile --}}
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header py-2">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-person me-2 text-primary"></i>Profile</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless small mb-0">
                    <tr>
                        <th class="text-muted" style="width:38%">Display Name</th>
                        <td class="fw-semibold">{{ $user->display_name }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Job Title</th>
                        <td>{{ $user->job_title ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Department</th>
                        <td>{{ $user->department ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Company</th>
                        <td>{{ $user->company_name ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Usage Location</th>
                        <td>{{ $user->usage_location ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Account Status</th>
                        <td><span class="badge {{ $user->statusBadgeClass() }}">{{ $user->statusLabel() }}</span></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Last Synced</th>
                        <td class="text-muted">{{ $user->updated_at->format('d M Y H:i') }}</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    {{-- Contact & Location --}}
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header py-2">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-telephone me-2 text-primary"></i>Contact &amp; Location</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless small mb-0">
                    <tr>
                        <th class="text-muted" style="width:38%">Email</th>
                        <td>
                            @if($user->mail)
                            <a href="mailto:{{ $user->mail }}" class="text-decoration-none">{{ $user->mail }}</a>
                            @else
                            —
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted">UPN</th>
                        <td class="font-monospace text-muted small">{{ $user->user_principal_name }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Business Phone</th>
                        <td>
                            @if($user->phone_number)
                            <a href="tel:{{ $user->phone_number }}" class="text-decoration-none">{{ $user->phone_number }}</a>
                            @else
                            —
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted">Mobile</th>
                        <td>
                            @if($user->mobile_phone)
                            <a href="tel:{{ $user->mobile_phone }}" class="text-decoration-none">{{ $user->mobile_phone }}</a>
                            @else
                            —
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted">Office</th>
                        <td>{{ $user->office_location ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Address</th>
                        <td>
                            @php
                                $addr = implode(', ', array_filter([
                                    $user->street_address,
                                    $user->city,
                                    $user->postal_code,
                                    $user->country,
                                ]));
                            @endphp
                            {{ $addr ?: '—' }}
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ── Row 2: Licenses + Groups ────────────────────────────────────────── --}}
<div class="row g-3">

    {{-- Licenses --}}
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-patch-check me-2 text-primary"></i>Licenses ({{ $licenses->count() }})</h6>
                @can('manage-identity')
                <button type="button" class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#addLicenseModal">
                    <i class="bi bi-plus-lg"></i>
                </button>
                @endcan
            </div>
            <div class="card-body p-0">
                @if($licenses->isEmpty())
                <div class="text-center py-4 text-muted small">
                    <i class="bi bi-patch-check display-6 d-block mb-1 opacity-25"></i>
                    No licenses assigned.
                </div>
                @else
                <ul class="list-group list-group-flush small">
                    @foreach($licenses as $lic)
                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <div>
                            <div class="fw-semibold">{{ $lic->friendlyName() }}</div>
                            <div class="text-muted" style="font-size:.72rem">{{ $lic->sku_part_number }}</div>
                        </div>
                        @can('manage-identity')
                        <form method="POST"
                              action="{{ route('admin.identity.user.remove-license', $user->azure_id) }}"
                              class="d-inline">
                            @csrf @method('DELETE')
                            <input type="hidden" name="sku_id" value="{{ $lic->sku_id }}">
                            <button type="submit" class="btn btn-sm btn-link text-danger p-0"
                                    onclick="return confirm('Remove this license?')">
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </form>
                        @endcan
                    </li>
                    @endforeach
                </ul>
                @endif
            </div>
        </div>
    </div>

    {{-- Groups --}}
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-collection me-2 text-primary"></i>Groups ({{ $groups->count() }})</h6>
                @can('manage-identity')
                <button type="button" class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="modal" data-bs-target="#addGroupModal">
                    <i class="bi bi-plus-lg"></i>
                </button>
                @endcan
            </div>
            <div class="card-body p-0">
                @if($groups->isEmpty())
                <div class="text-center py-4 text-muted small">
                    <i class="bi bi-collection display-6 d-block mb-1 opacity-25"></i>
                    No group memberships found.
                    @if(!$user->member_of || count($user->member_of) === 0)
                    <div class="mt-1">Run a sync to refresh memberships from Entra ID.</div>
                    @endif
                </div>
                @else
                <ul class="list-group list-group-flush small">
                    @foreach($groups as $grp)
                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <div>
                            <div class="fw-semibold">{{ $grp->display_name }}</div>
                            <span class="badge {{ $grp->typeBadgeClass() }}" style="font-size:.7rem">{{ $grp->typeLabel() }}</span>
                        </div>
                        @can('manage-identity')
                        <form method="POST"
                              action="{{ route('admin.identity.user.remove-group', $user->azure_id) }}"
                              class="d-inline">
                            @csrf @method('DELETE')
                            <input type="hidden" name="group_id" value="{{ $grp->azure_id }}">
                            <button type="submit" class="btn btn-sm btn-link text-danger p-0"
                                    onclick="return confirm('Remove from this group?')">
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </form>
                        @endcan
                    </li>
                    @endforeach
                </ul>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ─── Modals ───────────────────────────────────────────────────────────── --}}
@can('manage-identity')

{{-- ── Edit Profile Modal ─────────────────────────────────────────────── --}}
<div class="modal fade" id="editProfileModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.identity.user.update-profile', $user->azure_id) }}">
                @csrf @method('PATCH')
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">
                        <i class="bi bi-pencil-square me-2 text-primary"></i>Edit Profile — {{ $user->display_name }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    {{-- ── Identity ── --}}
                    <h6 class="text-muted text-uppercase fw-semibold mb-3" style="font-size:.72rem;letter-spacing:.08em">
                        Identity
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Display Name <span class="text-danger">*</span></label>
                            <input type="text" name="display_name" class="form-control"
                                   value="{{ old('display_name', $user->display_name) }}" required maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Job Title</label>
                            <input type="text" name="job_title" class="form-control"
                                   value="{{ old('job_title', $user->job_title) }}" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Department</label>
                            <input type="text" name="department" class="form-control"
                                   value="{{ old('department', $user->department) }}" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Company</label>
                            <input type="text" name="company_name" class="form-control"
                                   value="{{ old('company_name', $user->company_name) }}" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Office Location</label>
                            <input type="text" name="office_location" class="form-control"
                                   value="{{ old('office_location', $user->office_location) }}" maxlength="100"
                                   placeholder="e.g. Building A, Floor 2">
                        </div>
                    </div>

                    <hr class="my-1">

                    {{-- ── Contact ── --}}
                    <h6 class="text-muted text-uppercase fw-semibold mb-3 mt-3" style="font-size:.72rem;letter-spacing:.08em">
                        Contact
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Business Phone</label>
                            <input type="text" name="phone_number" class="form-control"
                                   value="{{ old('phone_number', $user->phone_number) }}" maxlength="50"
                                   placeholder="+1 555 000 0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Mobile Phone</label>
                            <input type="text" name="mobile_phone" class="form-control"
                                   value="{{ old('mobile_phone', $user->mobile_phone) }}" maxlength="50"
                                   placeholder="+1 555 000 0000">
                        </div>
                    </div>

                    <hr class="my-1">

                    {{-- ── Address ── --}}
                    <h6 class="text-muted text-uppercase fw-semibold mb-3 mt-3" style="font-size:.72rem;letter-spacing:.08em">
                        Address
                    </h6>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Street Address</label>
                            <input type="text" name="street_address" class="form-control"
                                   value="{{ old('street_address', $user->street_address) }}" maxlength="255">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">City</label>
                            <input type="text" name="city" class="form-control"
                                   value="{{ old('city', $user->city) }}" maxlength="100">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Postal Code</label>
                            <input type="text" name="postal_code" class="form-control"
                                   value="{{ old('postal_code', $user->postal_code) }}" maxlength="20">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Country</label>
                            <input type="text" name="country" class="form-control"
                                   value="{{ old('country', $user->country) }}" maxlength="100"
                                   placeholder="e.g. SA, US, AE">
                        </div>
                    </div>

                </div>
                <div class="modal-footer">
                    <small class="text-muted me-auto">Changes are saved to Azure AD and the local database.</small>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ── Reset Password Modal ───────────────────────────────────────────── --}}
<div class="modal fade" id="resetPwModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.identity.user.reset-password', $user->azure_id) }}">
                @csrf @method('PATCH')
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-key me-2 text-danger"></i>Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                        <input type="password" name="new_password" class="form-control" required minlength="8"
                               placeholder="Minimum 8 characters">
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="force_change" value="1" id="forceChange"
                               class="form-check-input" checked>
                        <label class="form-check-label" for="forceChange">
                            Require password change on next sign-in
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="bi bi-key me-1"></i>Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ── Assign License Modal ───────────────────────────────────────────── --}}
<div class="modal fade" id="addLicenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.identity.user.assign-license', $user->azure_id) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-patch-check me-2 text-primary"></i>Assign License</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">License <span class="text-danger">*</span></label>
                    <select name="sku_id" class="form-select" required>
                        <option value="">— Select a license —</option>
                        @foreach($allLicenses as $lic)
                        @unless(in_array($lic->sku_id, $user->assigned_licenses ?? []))
                        <option value="{{ $lic->sku_id }}">
                            {{ $lic->friendlyName() }} ({{ $lic->available }} available)
                        </option>
                        @endunless
                        @endforeach
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ── Add to Group Modal ─────────────────────────────────────────────── --}}
<div class="modal fade" id="addGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.identity.user.add-group', $user->azure_id) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-collection me-2 text-primary"></i>Add to Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Group <span class="text-danger">*</span></label>
                    <select name="group_id" class="form-select" required>
                        <option value="">— Select a group —</option>
                        @foreach($allGroups as $grp)
                        @unless(in_array($grp->azure_id, $user->member_of ?? []))
                        <option value="{{ $grp->azure_id }}">{{ $grp->display_name }}</option>
                        @endunless
                        @endforeach
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Add to Group</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endcan

{{-- ── Re-open modal on validation error ──────────────────────────────── --}}
@if($errors->any())
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('editProfileModal')).show();
});
</script>
@endpush
@endif

@endsection
