@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-person-badge-fill me-2 text-primary"></i>{{ $employee->name }}</h4>
        <small class="text-muted">Employee Profile</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-info btn-sm" onclick="openShareCard()" title="Share Digital Business Card">
            <i class="bi bi-person-vcard me-1"></i>Share Card
        </button>
        <a href="{{ route('admin.employees.report', $employee) }}" class="btn btn-outline-success btn-sm" target="_blank" title="Print Asset Report">
            <i class="bi bi-printer me-1"></i>Print Report
        </a>
        <a href="{{ route('admin.employees.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
        @can('manage-employees')
        <a href="{{ route('admin.employees.edit', $employee->id) }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        @endcan
    </div>
</div>

{{-- ── Azure Account Lifecycle Alerts ─────────────────────────────────────── --}}
@php
    $azureCurrentlyDisabled = $employee->identityUser && ! $employee->identityUser->account_enabled;
@endphp

@if($employee->azure_removed_at)
<div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
    <i class="bi bi-person-x-fill fs-5 mt-1 flex-shrink-0"></i>
    <div>
        <strong>Azure AD Account Permanently Removed</strong>
        — Deleted from directory on <strong>{{ $employee->azure_removed_at->format('d M Y H:i') }}</strong>.
        Employee status has been set to <span class="badge bg-danger">Terminated</span>.
    </div>
</div>
@elseif($employee->azure_disabled_at || $azureCurrentlyDisabled)
@php $disabledActiveAssets = $employee->activeAssets; @endphp
<div class="alert alert-warning d-flex align-items-start gap-2 mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
    <div>
        <strong>Azure AD Account Disabled</strong>
        @if($employee->azure_disabled_at)
        — Account was disabled on <strong>{{ $employee->azure_disabled_at->format('d M Y H:i') }}</strong>.
        @else
        — Account is currently disabled in Azure AD.
        @endif
        @if($disabledActiveAssets->isNotEmpty())
        This employee has <strong>{{ $disabledActiveAssets->count() }} active device assignment(s)</strong> that require return.
        Please arrange device collection with the employee or their manager.
        @endif
    </div>
</div>
@endif
{{-- ─────────────────────────────────────────────────────────────────────── --}}


<div class="row g-4 mb-4">

    {{-- LEFT SIDEBAR --}}
    <div class="col-12 col-lg-4">

        <div class="card shadow-sm border-0 text-center mb-3">
            <div class="card-body py-4">
                <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold mx-auto mb-3"
                     style="width:72px;height:72px;font-size:1.5rem">
                    {{ $employee->initials() }}
                </div>
                <h5 class="fw-bold mb-1">{{ $employee->name }}</h5>
                <div class="text-muted small mb-2">{{ $employee->job_title ?? 'No title' }}</div>
                <span class="badge {{ $employee->statusBadgeClass() }} px-3 py-1">{{ ucfirst(str_replace('_', ' ', $employee->status)) }}</span>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-transparent"><strong><i class="bi bi-info-circle me-1"></i>Details</strong></div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7">{{ $employee->email ?? '—' }}</dd>

                    @if($employee->job_title)
                    <dt class="col-5 text-muted">Job Title</dt>
                    <dd class="col-7">{{ $employee->job_title }}</dd>
                    @endif

                    @if($employee->oracle_emp_no)
                    <dt class="col-5 text-muted">Employee No</dt>
                    <dd class="col-7"><span class="badge bg-light text-dark border">{{ $employee->oracle_emp_no }}</span></dd>
                    @endif

                    @if($employee->mobile_phone)
                    <dt class="col-5 text-muted">Mobile</dt>
                    <dd class="col-7">{{ $employee->mobile_phone }}</dd>
                    @endif

                    <dt class="col-5 text-muted">Branch</dt>
                    <dd class="col-7">{{ $employee->branch?->name ?? '—' }}</dd>

                    <dt class="col-5 text-muted">Department</dt>
                    <dd class="col-7">{{ $employee->department?->name ?? '—' }}</dd>

                    @if($employee->oracle_synced_at)
                    <dt class="col-5 text-muted">HR Synced</dt>
                    <dd class="col-7"><span title="Last synced from Oracle HRMS import">{{ $employee->oracle_synced_at->format('d M Y') }}</span></dd>
                    @endif

                    <dt class="col-5 text-muted">Manager</dt>
                    <dd class="col-7">
                        @if($employee->manager)
                        <a href="{{ route('admin.employees.show', $employee->manager_id) }}" class="text-decoration-none">
                            <i class="bi bi-person me-1"></i>{{ $employee->manager->name }}
                        </a>
                        @else
                        —
                        @endif
                    </dd>

                    <dt class="col-5 text-muted">Hired</dt>
                    <dd class="col-7">{{ $employee->hired_date?->format('d M Y') ?? '—' }}</dd>

                    @if($employee->terminated_date)
                    <dt class="col-5 text-muted">Terminated</dt>
                    <dd class="col-7">{{ $employee->terminated_date->format('d M Y') }}</dd>
                    @endif

                    @if($employee->azure_disabled_at)
                    <dt class="col-5 text-muted">Azure Disabled</dt>
                    <dd class="col-7">
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-person-slash me-1"></i>{{ $employee->azure_disabled_at->format('d M Y') }}
                        </span>
                    </dd>
                    @endif

                    @if($employee->azure_removed_at)
                    <dt class="col-5 text-muted">Azure Removed</dt>
                    <dd class="col-7">
                        <span class="badge bg-danger">
                            <i class="bi bi-person-x me-1"></i>{{ $employee->azure_removed_at->format('d M Y') }}
                        </span>
                    </dd>
                    @endif

                    @if($employee->work_phone ?: $employee->identityUser?->phone_number)
                    <dt class="col-5 text-muted">Business Ph.</dt>
                    <dd class="col-7">{{ $employee->work_phone ?: $employee->identityUser->phone_number }}</dd>
                    @endif

                    @if($employee->company)
                    <dt class="col-5 text-muted">Company</dt>
                    <dd class="col-7">{{ $employee->company }}</dd>
                    @endif

                    @if($employee->office_location ?: $employee->identityUser?->office_location)
                    <dt class="col-5 text-muted">Office</dt>
                    <dd class="col-7">{{ $employee->office_location ?: $employee->identityUser->office_location }}</dd>
                    @endif

                    @if($employee->city ?: $employee->street_address)
                    <dt class="col-5 text-muted">Address</dt>
                    <dd class="col-7">{{ collect([$employee->street_address, $employee->city])->filter()->implode(', ') }}</dd>
                    @endif

                    @if($employee->azure_id)
                    <dt class="col-5 text-muted">Azure ID</dt>
                    <dd class="col-7"><code class="small">{{ Str::limit($employee->azure_id, 20) }}</code></dd>
                    @endif
                </dl>
            </div>
        </div>

        {{-- Linked Contact --}}
        <div class="card shadow-sm border-0 mb-3" style="border-left:4px solid #6f42c1!important">
            <div class="card-header bg-transparent py-2 d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-person-lines-fill me-1 text-purple"></i>Linked Contact</strong>
                @can('manage-employees')
                @if($employee->contact)
                <form method="POST" action="{{ route('admin.employees.unlink-contact', $employee) }}" class="d-inline">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Unlink contact">
                        <i class="bi bi-x-lg" style="font-size:.7rem"></i>
                    </button>
                </form>
                @endif
                @endcan
            </div>
            <div class="card-body small">
                @if($employee->contact)
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Name</dt>
                    <dd class="col-7 fw-semibold">{{ $employee->contact->first_name }} {{ $employee->contact->last_name }}</dd>
                    @if($employee->contact->phone)
                    <dt class="col-5 text-muted">Phone/Ext</dt>
                    <dd class="col-7"><code>{{ $employee->contact->phone }}</code></dd>
                    @endif
                    @if($employee->contact->email)
                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7">{{ $employee->contact->email }}</dd>
                    @endif
                    @if($employee->contact->branch)
                    <dt class="col-5 text-muted">Branch</dt>
                    <dd class="col-7">{{ $employee->contact->branch->name }}</dd>
                    @endif
                </dl>
                @else
                <p class="text-muted mb-2">No contact linked yet.</p>
                @can('manage-employees')
                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#linkContactModal">
                    <i class="bi bi-link-45deg me-1"></i>Link Contact
                </button>
                @endcan
                @endif
            </div>
        </div>

        @if($employee->extension_number || ($employee->contact && $employee->contact->phone))
        <div class="card shadow-sm border-0 mb-3" style="border-left:4px solid #0d6efd!important">
            <div class="card-header bg-transparent"><strong><i class="bi bi-telephone-fill me-1 text-primary"></i>Extension</strong></div>
            <div class="card-body small">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
                         style="width:44px;height:44px;flex-shrink:0">
                        <i class="bi bi-telephone-fill text-primary fs-5"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-4 lh-1">{{ $employee->extension_number ?: $employee->contact?->phone }}
                            @if(!$employee->extension_number && $employee->contact?->phone)
                            <span class="badge bg-light text-muted fw-normal" style="font-size:.6rem">from contact</span>
                            @endif
                        </div>
                        @php $ucm = $employee->branch?->ucmServer ?? $employee->ucmServer ?? null; @endphp
                        @if($ucm)
                        <div class="text-muted mt-1"><i class="bi bi-server me-1"></i>{{ $ucm->name }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endif

        @if(!empty($phoneInfo))
        <div class="card shadow-sm border-0 mb-3" style="border-left:4px solid #198754!important">
            <div class="card-header bg-transparent py-2">
                <strong><i class="bi bi-phone me-1 text-success"></i>Phone Device</strong>
                @if($phoneInfo['source'] ?? false)
                <span class="badge bg-light text-muted float-end small">{{ $phoneInfo['source'] }}</span>
                @endif
            </div>
            <div class="card-body small">
                <dl class="row mb-0">
                    @if($phoneInfo['mac'] ?? false)
                    <dt class="col-5 text-muted">MAC</dt>
                    <dd class="col-7 font-monospace">{{ strtoupper(implode(':', str_split($phoneInfo['mac'], 2))) }}</dd>
                    @endif

                    @if(!empty($phoneInfo['device']))
                    <dt class="col-5 text-muted">Device</dt>
                    <dd class="col-7">
                        <a href="{{ route('admin.devices.show', $phoneInfo['device']->id) }}" class="text-decoration-none fw-semibold">
                            {{ $phoneInfo['device']->name }}
                        </a>
                    </dd>
                    @endif

                    @if($phoneInfo['model'] ?? false)
                    <dt class="col-5 text-muted">Model</dt>
                    <dd class="col-7">{{ $phoneInfo['model'] }}</dd>
                    @endif

                    @if($phoneInfo['ip'] ?? false)
                    <dt class="col-5 text-muted">IP Address</dt>
                    <dd class="col-7">
                        <a href="https://{{ $phoneInfo['ip'] }}" target="_blank" class="text-decoration-none" title="Open phone web settings">
                            {{ $phoneInfo['ip'] }} <i class="bi bi-box-arrow-up-right small"></i>
                        </a>
                    </dd>
                    @endif

                    @if($phoneInfo['status'] ?? false)
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7">
                        @php
                            $st = strtolower($phoneInfo['status']);
                            $cls = match(true) {
                                in_array($st, ['registered', 'idle']) => 'bg-success',
                                in_array($st, ['inuse', 'busy', 'ringing']) => 'bg-warning text-dark',
                                default => 'bg-secondary',
                            };
                        @endphp
                        <span class="badge {{ $cls }}">{{ ucfirst($phoneInfo['status']) }}</span>
                    </dd>
                    @endif

                    @if($phoneInfo['switch_location'] ?? false)
                    <dt class="col-5 text-muted">Switch Port</dt>
                    <dd class="col-7">{{ $phoneInfo['switch_location'] }}</dd>
                    @endif
                </dl>
            </div>
        </div>
        @endif

        @if($employee->identityUser)
        <div class="card shadow-sm border-0"
             style="{{ $employee->azure_removed_at ? 'border-left:4px solid #dc3545!important' : (($employee->azure_disabled_at || $azureCurrentlyDisabled) ? 'border-left:4px solid #ffc107!important' : '') }}">
            <div class="card-header bg-transparent"><strong><i class="bi bi-microsoft me-1"></i>Azure AD</strong></div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Account</dt>
                    <dd class="col-7">
                        <span class="badge {{ $employee->identityUser->account_enabled ? 'bg-success' : 'bg-danger' }}">
                            {{ $employee->identityUser->account_enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                        @if(!$employee->identityUser->account_enabled)
                            @if($employee->azure_disabled_at)
                            <span class="text-muted ms-1" style="font-size:.7rem">since {{ $employee->azure_disabled_at->format('d M Y') }}</span>
                            @endif
                        @endif
                    </dd>
                    <dt class="col-5 text-muted">Licenses</dt>
                    <dd class="col-7">{{ $employee->identityUser->licenses_count ?? 0 }}</dd>
                    <dt class="col-5 text-muted">Groups</dt>
                    <dd class="col-7">{{ $employee->identityUser->groups_count ?? 0 }}</dd>
                    @if($employee->identityUser->department)
                    <dt class="col-5 text-muted">Dept (Azure)</dt>
                    <dd class="col-7">{{ $employee->identityUser->department }}</dd>
                    @endif
                </dl>
                <a href="{{ route('admin.identity.user', $employee->identityUser->azure_id) }}"
                   class="btn btn-sm btn-outline-primary mt-2 w-100">
                    <i class="bi bi-box-arrow-up-right me-1"></i>View in Identity
                </a>
            </div>
        </div>
        @endif

        {{-- Printer Deployment Card --}}
        @can('manage-employees')
        @if($employee->email && $employee->branch_id)
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body py-3 px-4">
                @if(session('success') && str_contains(session('success'), 'Printer'))
                  <div class="alert alert-success alert-sm py-2 px-3 mb-2 small">{{ session('success') }}</div>
                @endif
                @error('employee_id')
                  <div class="alert alert-danger alert-sm py-2 px-3 mb-2 small">{{ $message }}</div>
                @enderror
                <form method="POST" action="/admin/printer-deploy">
                    @csrf
                    <input type="hidden" name="employee_id" value="{{ $employee->id }}">
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-printer me-1"></i>Send Printer Setup Link
                    </button>
                    <div class="text-muted small mt-1 text-center">
                        Sends a setup link to <strong>{{ $employee->email }}</strong>
                    </div>
                </form>
            </div>
        </div>
        @endif
        @endcan

    </div>{{-- /sidebar --}}

    {{-- MAIN CONTENT --}}
    <div class="col-12 col-lg-8">

        @if($employee->notes)
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-transparent"><strong><i class="bi bi-sticky me-1"></i>Notes</strong></div>
            <div class="card-body small">{{ $employee->notes }}</div>
        </div>
        @endif

        {{-- Unified Equipment (tabbed) --}}
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent p-0 pt-1 px-3">
                <ul class="nav nav-tabs border-0" id="equipmentTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active fw-semibold" id="it-assets-tab"
                                data-bs-toggle="tab" data-bs-target="#it-assets" type="button">
                            <i class="bi bi-cpu me-1"></i>IT Assets
                            @if($employee->activeAssets->count())
                            <span class="badge bg-primary ms-1">{{ $employee->activeAssets->count() }}</span>
                            @endif
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold" id="personal-items-tab"
                                data-bs-toggle="tab" data-bs-target="#personal-items" type="button">
                            <i class="bi bi-laptop me-1"></i>Personal Items
                            @if($employee->activeItems->count())
                            <span class="badge bg-secondary ms-1">{{ $employee->activeItems->count() }}</span>
                            @endif
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold" id="accessories-tab"
                                data-bs-toggle="tab" data-bs-target="#accessories" type="button">
                            <i class="bi bi-box-seam me-1"></i>Accessories
                            @if($employee->accessoryAssignments->whereNull('returned_date')->count())
                            <span class="badge bg-info ms-1">{{ $employee->accessoryAssignments->whereNull('returned_date')->count() }}</span>
                            @endif
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link fw-semibold" id="licenses-tab"
                                data-bs-toggle="tab" data-bs-target="#licenses" type="button">
                            <i class="bi bi-key me-1"></i>Licenses
                            @if(($licenseAssignments ?? collect())->count())
                            <span class="badge bg-warning text-dark ms-1">{{ $licenseAssignments->count() }}</span>
                            @endif
                        </button>
                    </li>
                </ul>
            </div>

            <div class="tab-content">

                {{-- Tab 1: IT Assets from inventory --}}
                <div class="tab-pane fade show active" id="it-assets" role="tabpanel">
                    <div class="px-3 py-2 border-bottom d-flex justify-content-end">
                        @can('manage-employees')
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#assignAssetModal">
                            <i class="bi bi-plus-lg me-1"></i>Assign from Inventory
                        </button>
                        @endcan
                    </div>
                    @if($employee->assetAssignments->isEmpty())
                    <div class="text-center py-5 text-muted small">
                        <i class="bi bi-cpu d-block display-5 mb-2 opacity-25"></i>
                        No IT assets assigned.<br>
                        <span class="text-muted">Add laptops, monitors, etc. to IT inventory first.</span>
                    </div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Device</th><th>Type</th><th>Serial</th>
                                    <th>Condition</th><th>Assigned</th><th>Status</th><th class="pe-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($employee->assetAssignments as $a)
                            <tr class="{{ $a->returned_date ? 'table-light text-muted' : '' }}">
                                <td class="ps-3 fw-semibold">
                                    <i class="bi {{ $a->device?->typeIcon() ?? 'bi-cpu' }} me-1 text-muted"></i>
                                    @if($a->device)
                                        <a href="{{ route('admin.devices.show', $a->device->id) }}" class="text-decoration-none">
                                            {{ $a->device->name }}
                                        </a>
                                    @else
                                        Unknown
                                    @endif
                                </td>
                                <td>
                                    @if($a->device)<span class="badge {{ $a->device->typeBadgeClass() }}">{{ $a->device->typeLabel() }}</span>
                                    @else —
                                    @endif
                                </td>
                                <td class="text-muted">{{ $a->device?->serial_number ?? '—' }}</td>
                                <td><span class="badge bg-{{ $a->conditionBadgeClass() }}">{{ ucfirst($a->condition) }}</span></td>
                                <td>{{ $a->assigned_date->format('d M Y') }}</td>
                                <td>
                                    @if($a->returned_date)
                                    <span class="badge bg-success">Returned {{ $a->returned_date->format('d M Y') }}</span>
                                    @else<span class="badge bg-primary">Active</span>
                                    @endif
                                </td>
                                <td class="pe-3">
                                    @if(!$a->returned_date)
                                    @can('manage-employees')
                                    <button class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#returnAssetModal{{ $a->id }}">Return</button>
                                    @endcan
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>

                {{-- Tab 2: Personal Items (free-text) --}}
                <div class="tab-pane fade" id="personal-items" role="tabpanel">
                    <div class="px-3 py-2 border-bottom d-flex justify-content-end">
                        @can('manage-employees')
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addItemModal">
                            <i class="bi bi-plus-lg me-1"></i>Add Item
                        </button>
                        @endcan
                    </div>
                    @if($employee->items->isEmpty())
                    <div class="text-center py-5 text-muted small">
                        <i class="bi bi-laptop d-block display-5 mb-2 opacity-25"></i>No personal items assigned.
                    </div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Item</th><th>Type</th><th>Serial / Model</th>
                                    <th class="text-center">Condition</th><th>Assigned</th><th>Returned</th>
                                    <th class="text-end pe-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($employee->items as $item)
                            <tr class="{{ $item->returned_date ? 'text-muted' : '' }}">
                                <td class="ps-3 fw-semibold">
                                    <i class="bi {{ $item->typeIcon() }} me-1 text-muted"></i>{{ $item->item_name }}
                                </td>
                                <td><span class="badge bg-{{ $item->typeBadgeClass() }}">{{ $item->typeLabel() }}</span></td>
                                <td>
                                    @if($item->serial_number)<div>SN: {{ $item->serial_number }}</div>@endif
                                    @if($item->model)<div class="text-muted">{{ $item->model }}</div>@endif
                                </td>
                                <td class="text-center"><span class="badge bg-{{ $item->conditionBadgeClass() }}">{{ ucfirst($item->condition) }}</span></td>
                                <td>{{ $item->assigned_date ? \Carbon\Carbon::parse($item->assigned_date)->format('d M Y') : '—' }}</td>
                                <td>
                                    @if($item->returned_date)
                                    <span class="text-success">{{ \Carbon\Carbon::parse($item->returned_date)->format('d M Y') }}</span>
                                    @else<span class="text-muted">Active</span>
                                    @endif
                                </td>
                                <td class="text-end pe-3">
                                    @if(!$item->returned_date)
                                    @can('manage-employees')
                                    <form method="POST" action="{{ route('admin.employees.items.return', [$employee->id, $item->id]) }}" class="d-inline">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="returned_date" value="{{ now()->toDateString() }}">
                                        <button type="submit" class="btn btn-outline-success btn-sm"
                                                onclick="return confirm('Mark as returned today?')">
                                            <i class="bi bi-box-arrow-in-left"></i>
                                        </button>
                                    </form>
                                    @endcan
                                    @endif
                                    @can('manage-employees')
                                    <form method="POST" action="{{ route('admin.employees.items.destroy', [$employee->id, $item->id]) }}"
                                          class="d-inline" onsubmit="return confirm('Remove this item?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>

                {{-- Tab 3: Accessories --}}
                <div class="tab-pane fade" id="accessories" role="tabpanel">
                    <div class="px-3 py-2 border-bottom d-flex justify-content-end">
                        @can('manage-employees')
                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#assignAccessoryModal">
                            <i class="bi bi-plus-lg me-1"></i>Assign Accessory
                        </button>
                        @endcan
                    </div>
                    @if($employee->accessoryAssignments->isEmpty())
                    <div class="text-center py-5 text-muted small">
                        <i class="bi bi-box-seam d-block display-5 mb-2 opacity-25"></i>No accessories assigned.
                    </div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Accessory</th><th>Category</th>
                                    <th>Assigned</th><th>Status</th><th class="pe-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($employee->accessoryAssignments as $aa)
                            <tr class="{{ $aa->returned_date ? 'table-light text-muted' : '' }}">
                                <td class="ps-3 fw-semibold">
                                    <i class="bi bi-box-seam me-1 text-muted"></i>{{ $aa->accessory?->name ?? 'Unknown' }}
                                </td>
                                <td><span class="badge bg-secondary">{{ $aa->accessory?->category ?? '—' }}</span></td>
                                <td>{{ $aa->assigned_date?->format('d M Y') ?? '—' }}</td>
                                <td>
                                    @if($aa->returned_date)
                                    <span class="badge bg-success">Returned {{ $aa->returned_date->format('d M Y') }}</span>
                                    @else<span class="badge bg-info">Active</span>
                                    @endif
                                </td>
                                <td class="pe-3">
                                    @if(!$aa->returned_date && $aa->accessory)
                                    @can('manage-employees')
                                    <form method="POST" action="{{ route('admin.itam.accessories.return', [$aa->accessory_id, $aa->id]) }}" class="d-inline"
                                          onsubmit="return confirm('Return this accessory?')">
                                        @csrf @method('PATCH')
                                        <button class="btn btn-sm btn-outline-secondary">Return</button>
                                    </form>
                                    @endcan
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>

                {{-- Tab 4: Licenses --}}
                <div class="tab-pane fade" id="licenses" role="tabpanel">
                    <div class="px-3 py-2 border-bottom d-flex justify-content-end">
                        @can('manage-employees')
                        <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#assignLicenseModal">
                            <i class="bi bi-plus-lg me-1"></i>Assign License
                        </button>
                        @endcan
                    </div>
                    @if(($licenseAssignments ?? collect())->isEmpty())
                    <div class="text-center py-5 text-muted small">
                        <i class="bi bi-key d-block display-5 mb-2 opacity-25"></i>No licenses assigned.
                    </div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">License</th><th>Vendor</th><th>Type</th>
                                    <th>Assigned</th><th class="pe-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($licenseAssignments as $la)
                            <tr>
                                <td class="ps-3 fw-semibold">
                                    <i class="bi bi-key me-1 text-muted"></i>{{ $la->license?->license_name ?? 'Unknown' }}
                                </td>
                                <td>{{ $la->license?->vendor ?? '—' }}</td>
                                <td><span class="badge bg-secondary">{{ ucfirst($la->license?->license_type ?? '—') }}</span></td>
                                <td>{{ $la->assigned_date?->format('d M Y') ?? '—' }}</td>
                                <td class="pe-3">
                                    @if($la->license)
                                    @can('manage-employees')
                                    <form method="POST" action="{{ route('admin.itam.licenses.unassign', [$la->license_id, $la->id]) }}" class="d-inline"
                                          onsubmit="return confirm('Unassign this license?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger">Unassign</button>
                                    </form>
                                    @endcan
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>

            </div>{{-- /tab-content --}}
        </div>{{-- /unified equipment --}}

    </div>{{-- /col-lg-8 --}}
</div>{{-- /row --}}

{{-- ⅦⅦⅦ MODALS ⅦⅦⅦ --}}

@can('manage-employees')

{{-- Assign from Inventory --}}
<div class="modal fade" id="assignAssetModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.employees.assets.assign', $employee->id) }}">
                @csrf
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-cpu me-2"></i>Assign Asset from IT Inventory</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Select Device <span class="text-danger">*</span></label>
                            @if($availableDevices->isEmpty())
                            <div class="alert alert-warning small py-2 mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                No user-equipment devices available. Add laptops, monitors, keyboards, etc. to IT inventory with status "Available".
                            </div>
                            @else
                            <select name="asset_id" class="form-select form-select-sm" required>
                                <option value="">— Select a device —</option>
                                @php $prevType = null; @endphp
                                @foreach($availableDevices as $device)
                                @if($device->type !== $prevType)
                                    @if($prevType !== null)</optgroup>@endif
                                    <optgroup label="{{ $device->typeLabel() }}">
                                    @php $prevType = $device->type; @endphp
                                @endif
                                <option value="{{ $device->id }}">{{ $device->name }}
                                    @if($device->model)({{ $device->model }})@endif
                                    @if($device->serial_number) — SN: {{ $device->serial_number }}@endif
                                </option>
                                @endforeach
                                @if($prevType !== null)</optgroup>@endif
                            </select>
                            @endif
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Assigned Date <span class="text-danger">*</span></label>
                            <input type="date" name="assigned_date" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Condition <span class="text-danger">*</span></label>
                            <select name="condition" class="form-select form-select-sm" required>
                                <option value="good">Good</option>
                                <option value="fair">Fair</option>
                                <option value="poor">Poor</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" {{ $availableDevices->isEmpty() ? 'disabled' : '' }}>
                        <i class="bi bi-check-lg me-1"></i>Assign Asset
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Return Asset Modals --}}
@foreach($employee->assetAssignments->whereNull('returned_date') as $a)
<div class="modal fade" id="returnAssetModal{{ $a->id }}" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.employees.assets.return', [$employee->id, $a->id]) }}">
                @csrf @method('PATCH')
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-in-left me-2"></i>Return Asset</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">Returning: <strong>{{ $a->device?->name }}</strong></p>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Return Date</label>
                        <input type="date" name="returned_date" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Condition on Return</label>
                        <select name="condition" class="form-select form-select-sm">
                            <option value="good">Good</option><option value="fair">Fair</option><option value="poor">Poor</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Confirm Return</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

{{-- Assign Accessory --}}
<div class="modal fade" id="assignAccessoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="assignAccForm" method="POST" action="">
                @csrf
                <input type="hidden" name="assign_to" value="employee">
                <input type="hidden" name="assignable_id" value="{{ $employee->id }}">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>Assign Accessory</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if(($availableAccessories ?? collect())->isEmpty())
                    <div class="alert alert-warning small py-2 mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>No accessories with available stock. Add accessories to inventory first.
                    </div>
                    @else
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Select Accessory <span class="text-danger">*</span></label>
                        <select name="accessory_id" id="empAccSelect" class="form-select form-select-sm" required
                                onchange="document.getElementById('assignAccForm').action='/admin/itam/accessories/'+this.value+'/assign'">
                            <option value="">— Select —</option>
                            @foreach($availableAccessories as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->name }} ({{ $acc->category }}) — {{ $acc->quantity_available }} available</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Assigned Date <span class="text-danger">*</span></label>
                        <input type="date" name="assigned_date" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info btn-sm" {{ ($availableAccessories ?? collect())->isEmpty() ? 'disabled' : '' }}>
                        <i class="bi bi-check-lg me-1"></i>Assign
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Assign License --}}
<div class="modal fade" id="assignLicenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="assignLicForm" method="POST" action="">
                @csrf
                <input type="hidden" name="assignable_type" value="employee">
                <input type="hidden" name="assignable_id" value="{{ $employee->id }}">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-key me-2"></i>Assign License</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @if(($availableLicenses ?? collect())->isEmpty())
                    <div class="alert alert-warning small py-2 mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>No licenses with available seats. Add licenses first.
                    </div>
                    @else
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Select License <span class="text-danger">*</span></label>
                        <select name="license_id" id="empLicSelect" class="form-select form-select-sm" required
                                onchange="document.getElementById('assignLicForm').action='/admin/itam/licenses/'+this.value+'/assign'">
                            <option value="">— Select —</option>
                            @foreach($availableLicenses as $lic)
                            <option value="{{ $lic->id }}">{{ $lic->license_name }} ({{ $lic->vendor }}) — {{ $lic->availableSeats() }} seats free</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Assigned Date <span class="text-danger">*</span></label>
                        <input type="date" name="assigned_date" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm" {{ ($availableLicenses ?? collect())->isEmpty() ? 'disabled' : '' }}>
                        <i class="bi bi-check-lg me-1"></i>Assign
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Add Personal Item --}}
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.employees.items.store', $employee->id) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-laptop me-2"></i>Add Personal Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Item Name <span class="text-danger">*</span></label>
                            <input type="text" name="item_name" class="form-control" placeholder="e.g. Dell XPS 15 Laptop" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                            <select name="item_type" class="form-select" required>
                                <option value="laptop">Laptop</option>
                                <option value="desktop">Desktop</option>
                                <option value="monitor">Monitor</option>
                                <option value="phone">Phone</option>
                                <option value="headset">Headset</option>
                                <option value="tablet">Tablet</option>
                                <option value="keyboard">Keyboard</option>
                                <option value="mouse">Mouse</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Condition <span class="text-danger">*</span></label>
                            <select name="condition" class="form-select" required>
                                <option value="good">Good</option>
                                <option value="fair">Fair</option>
                                <option value="poor">Poor</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Serial Number</label>
                            <input type="text" name="serial_number" class="form-control" placeholder="Optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Model</label>
                            <input type="text" name="model" class="form-control" placeholder="Optional">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Assigned Date <span class="text-danger">*</span></label>
                            <input type="date" name="assigned_date" class="form-control" value="{{ now()->toDateString() }}" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Add Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endcan

{{-- ── Link Contact Modal ── --}}
@can('manage-employees')
@if(!$employee->contact)
<div class="modal fade" id="linkContactModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.employees.link-contact', $employee) }}">
                @csrf
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-semibold"><i class="bi bi-link-45deg me-1"></i>Link Contact to {{ $employee->name }}</h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Search Contact</label>
                        <input type="text" id="contactSearch" class="form-control form-control-sm" placeholder="Type name, email, or phone to search..." autocomplete="off">
                        <small class="text-muted">Results are fetched on demand (max 50 shown).</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Contact <span class="text-danger">*</span></label>
                        <select name="contact_id" id="contactSelect" class="form-select form-select-sm" required size="8">
                            <option value="" disabled selected>Type to search for a contact above…</option>
                        </select>
                        <small id="contactSearchStatus" class="text-muted"></small>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Link Contact</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const input  = document.getElementById('contactSearch');
    const select = document.getElementById('contactSelect');
    const status = document.getElementById('contactSearchStatus');
    if (!input || !select) return;

    const searchUrl = @json(route('admin.contacts.search-light'));
    let debounce = null;
    let lastReq  = 0;

    function render(contacts) {
        if (!contacts.length) {
            select.innerHTML = '<option value="" disabled>No contacts match your search</option>';
            return;
        }
        const opts = contacts.map(c => {
            const label = `${c.name} — ${c.phone ?? ''} ${c.email ? '(' + c.email + ')' : ''}`.trim();
            return `<option value="${c.id}">${label.replace(/[<>]/g, '')}</option>`;
        }).join('');
        select.innerHTML = opts;
    }

    async function search(term) {
        const reqId = ++lastReq;
        status.textContent = 'Searching…';
        try {
            const url = `${searchUrl}?q=${encodeURIComponent(term)}`;
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (reqId !== lastReq) return; // ignore stale results
            render(data.contacts || []);
            status.textContent = `${data.contacts.length} result${data.contacts.length === 1 ? '' : 's'}`;
        } catch (e) {
            status.textContent = 'Search failed: ' + e.message;
        }
    }

    input.addEventListener('input', function () {
        clearTimeout(debounce);
        const term = this.value.trim();
        if (term.length < 2) {
            select.innerHTML = '<option value="" disabled selected>Type at least 2 characters to search…</option>';
            status.textContent = '';
            return;
        }
        debounce = setTimeout(() => search(term), 250);
    });

    // Pre-fill the search with the employee's email so the most relevant contact appears first.
    const employeeEmail = @json($employee->email ?? null);
    if (employeeEmail) {
        input.value = employeeEmail.split('@')[0];
        input.dispatchEvent(new Event('input'));
    }
})();
</script>
@endpush
@endif
@endcan

{{-- ── Share Card Modal ─────────────────────────────────────────────────────── --}}
<div class="modal fade" id="shareCardModal" tabindex="-1" aria-labelledby="shareCardModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="shareCardModalLabel">
                    <i class="bi bi-person-vcard me-2 text-info"></i>Digital Business Card
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2" id="shareCardBody">
                <div class="text-center py-4">
                    <div class="spinner-border spinner-border-sm text-info" role="status"></div>
                    <p class="text-muted small mt-2 mb-0">Loading…</p>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const cardDataUrl    = @json(route('admin.employees.card-data', $employee));
    const regenUrl       = @json(route('admin.employees.card-token.regenerate', $employee));
    const csrfToken      = document.querySelector('meta[name="csrf-token"]').content;
    let   cardData       = null;

    window.openShareCard = function () {
        const modal = new bootstrap.Modal(document.getElementById('shareCardModal'));
        modal.show();

        if (cardData) {
            renderShareCard(cardData);
            return;
        }

        fetch(cardDataUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => { cardData = d; renderShareCard(d); })
            .catch(() => {
                document.getElementById('shareCardBody').innerHTML =
                    '<div class="alert alert-danger">Failed to load card data.</div>';
            });
    };

    function renderShareCard(d) {
        document.getElementById('shareCardBody').innerHTML = `
            <p class="text-muted small mb-3">
                Share this link so anyone can view {{ $employee->name }}'s digital business card, download their contact, or add it to Apple Wallet.
            </p>

            <div class="d-flex align-items-center gap-2 mb-3">
                <input type="text" id="cardLinkInput" class="form-control form-control-sm font-monospace"
                       value="${d.card_url}" readonly>
                <button class="btn btn-sm btn-outline-secondary flex-shrink-0" onclick="copyCardLink()">
                    <i class="bi bi-clipboard"></i>
                </button>
                <a href="${d.card_url}" target="_blank" class="btn btn-sm btn-outline-info flex-shrink-0">
                    <i class="bi bi-box-arrow-up-right"></i>
                </a>
            </div>

            <div class="d-flex align-items-start gap-3 mb-3">
                <div id="qrContainer" style="width:120px;flex-shrink:0;">${d.qr_svg}</div>
                <div class="d-flex flex-column gap-2 w-100">
                    <a href="${d.vcard_url}" download class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-person-plus me-1"></i>Download vCard (.vcf)
                    </a>
                    ${d.wallet_ready
                        ? `<a href="${d.wallet_url}" class="btn btn-sm btn-dark w-100"><i class="bi bi-wallet2 me-1"></i>Add to Apple Wallet</a>`
                        : `<button class="btn btn-sm btn-outline-secondary w-100" disabled title="Configure Apple Wallet in Settings to enable"><i class="bi bi-wallet2 me-1"></i>Apple Wallet (not configured)</button>`
                    }
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                <small class="text-muted">Regenerating the link invalidates all previous shares.</small>
                <button class="btn btn-sm btn-outline-danger" id="regenBtn" onclick="regenerateToken()">
                    <i class="bi bi-arrow-clockwise me-1"></i>Regenerate
                </button>
            </div>
        `;
    }

    window.copyCardLink = function () {
        const input = document.getElementById('cardLinkInput');
        navigator.clipboard.writeText(input.value).catch(() => {
            input.select(); document.execCommand('copy');
        });
        const btn = input.nextElementSibling;
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check"></i>';
        setTimeout(() => btn.innerHTML = orig, 1500);
    };

    window.regenerateToken = function () {
        if (!confirm('Regenerate the card link? Anyone with the old link will no longer be able to access it.')) return;
        const btn = document.getElementById('regenBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch(regenUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(d => { cardData = d; renderShareCard(d); })
        .catch(() => alert('Failed to regenerate token.'));
    };
})();
</script>
@endpush

@endsection
