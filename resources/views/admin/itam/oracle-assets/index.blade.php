@extends('layouts.admin')

@section('title', 'Oracle Asset Register')

@section('content')
@php
    $canImport = auth()->user()->can('manage-itam');
    $canRetire = auth()->user()->can('manage-assets');
    $canScrap = auth()->user()->can('request-scrap');
    $stateBadge = [
        'not_in_intune' => 'bg-warning text-dark',
        'in_intune' => 'bg-success',
        'no_holder' => 'bg-secondary',
        'name_match' => 'bg-info text-dark',
        'retired' => 'bg-dark',
        'asset_deleted' => 'bg-danger',
        'removed' => 'bg-light text-dark border',
    ];
@endphp

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-journal-check me-2 text-primary"></i>Oracle Asset Register</h4>
        <small class="text-muted">
            The laptops and desktops in Oracle's fixed-asset register, each with its holder, its NOC asset and its Intune device.
            Every laptop has to be in Intune: work through <em>Not in Intune</em> — link each one, or retire or scrap it.
        </small>
    </div>
    @if ($canImport)
        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#oracleImport" aria-expanded="false">
            <i class="bi bi-cloud-arrow-up me-1"></i>Import from Oracle
        </button>
    @endif
</div>

@if ($errors->any())
    <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
@endif

@if ($canImport)
    <div class="collapse {{ $imports->isEmpty() || $errors->has('file') ? 'show' : '' }}" id="oracleImport">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <form method="POST" action="{{ route('admin.itam.oracle-assets.import') }}" enctype="multipart/form-data">
                            @csrf
                            <label class="form-label small mb-1" for="oracleFile">
                                Register file <span class="text-muted font-monospace">(Asset Number, Asset Name, P Date, End Date, Emp Name, Emp no)</span>
                            </label>
                            <input type="file" name="file" id="oracleFile" accept=".xls,.xlsx,.csv" class="form-control form-control-sm mb-2" required>
                            <div class="form-check small mb-3">
                                <input class="form-check-input" type="checkbox" name="dry_run" value="1" id="oracleDryRun">
                                <label class="form-check-label" for="oracleDryRun">Dry run — show what the import would do and save nothing</label>
                            </div>
                            <button class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Import</button>
                        </form>
                    </div>
                    <div class="col-lg-7 small">
                        <p class="mb-2">
                            <strong>What comes in.</strong> Laptops and desktops only, one per row: an Oracle asset number is a purchase,
                            so thirty laptops bought together are thirty rows under one number. Software licences, monitors and anything
                            else in the register are left out and counted.
                        </p>
                        <p class="mb-2">
                            <strong>Holders.</strong> The employee number is matched to an employee's Oracle no. in
                            {{ implode(', ', (array) config('oracle_assets.branches')) }} — the SSS Egypt numbers collide — or, for an employee
                            the NOC has no Oracle number for, by name when exactly one name fits. A holder who is not in the NOC waits
                            under <em>Holder not in the NOC</em>.
                        </p>
                        <p class="mb-2">
                            <strong>Intune.</strong> Each laptop is compared with the holder's Intune devices by serial, model, CPU and
                            dates. A match puts the Oracle number and purchase date on that asset, which keeps its code. A laptop nothing
                            matched becomes a new asset assigned to the holder and shows under <em>Not in Intune</em>.
                        </p>
                        <p class="mb-0">
                            <strong>Next imports.</strong> Upload the whole register each time. Units already matched are left as they are;
                            a unit the file no longer lists is marked <em>No longer in Oracle</em>, never deleted.
                        </p>
                    </div>
                </div>

                @if ($imports->isNotEmpty())
                    <div class="table-responsive mt-3">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>When</th>
                                    <th>File</th>
                                    <th class="text-end">Rows</th>
                                    <th class="text-end" title="Laptops and desktops">Computers</th>
                                    <th class="text-end" title="Software, monitors and other lines">Left out</th>
                                    <th class="text-end">New</th>
                                    <th class="text-end">Changed</th>
                                    <th class="text-end">No longer in Oracle</th>
                                    <th class="text-end">Matched to Intune</th>
                                    <th class="text-end">New assets</th>
                                    <th class="text-end">Holder not in NOC</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($imports as $import)
                                    <tr>
                                        <td class="text-nowrap">{{ $import->created_at?->format('d M Y H:i') }}<div class="text-muted">{{ $import->importer?->name ?? 'console' }}</div></td>
                                        <td class="text-break">{{ $import->filename }}</td>
                                        <td class="text-end">{{ $import->rows }}</td>
                                        <td class="text-end">{{ $import->stat('computers') }}</td>
                                        <td class="text-end text-muted">{{ $import->stat('skipped_other') + $import->stat('skipped_invalid') }}</td>
                                        <td class="text-end">{{ $import->stat('created') }}</td>
                                        <td class="text-end">{{ $import->stat('updated') }}</td>
                                        <td class="text-end">{{ $import->stat('removed') }}</td>
                                        <td class="text-end">{{ $import->stat('linked_intune') }}</td>
                                        <td class="text-end">{{ $import->stat('created_devices') }}</td>
                                        <td class="text-end {{ $import->stat('no_employee') ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">{{ $import->stat('no_employee') }}</td>
                                    </tr>
                                    @if ($import->notes)
                                        <tr>
                                            <td></td>
                                            <td colspan="10" class="text-muted">
                                                <details>
                                                    <summary>{{ count($import->notes) }} row(s) with problems</summary>
                                                    <ul class="mb-0 mt-1">
                                                        @foreach ($import->notes as $note)
                                                            <li>{{ $note }}</li>
                                                        @endforeach
                                                    </ul>
                                                </details>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif

{{-- States --}}
<div class="d-flex flex-wrap gap-2 mb-3">
    @foreach ($states as $key => $label)
        <a href="{{ route('admin.itam.oracle-assets.index', array_filter(['state' => $key === 'all' ? null : $key, 'category' => request('category'), 'branch' => request('branch'), 'q' => request('q')])) }}"
           class="btn btn-sm {{ $state === $key ? 'btn-dark' : 'btn-outline-secondary' }}">
            {{ $label }}
            <span class="badge ms-1 {{ $state === $key ? 'bg-light text-dark' : ($stateBadge[$key] ?? 'bg-primary') }}">{{ number_format($counts[$key]) }}</span>
        </a>
    @endforeach
</div>

<form method="GET" class="row g-2 align-items-end mb-3">
    @if ($state !== 'all')
        <input type="hidden" name="state" value="{{ $state }}">
    @endif
    <div class="col-md-4">
        <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm"
               placeholder="Oracle asset no., employee no. or name, description, asset code, serial">
    </div>
    <div class="col-auto">
        <select name="category" class="form-select form-select-sm">
            <option value="">Laptops and desktops</option>
            @foreach (\App\Models\Itam\OracleAsset::CATEGORY_LABELS as $key => $label)
                <option value="{{ $key }}" @selected(request('category') === $key)>{{ $label }}s</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="branch" class="form-select form-select-sm">
            <option value="">All branches</option>
            @foreach ($branches as $branch)
                <option value="{{ $branch->id }}" @selected((string) request('branch') === (string) $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
        @if (request()->hasAny(['q', 'category', 'branch']))
            <a href="{{ route('admin.itam.oracle-assets.index', $state !== 'all' ? ['state' => $state] : []) }}" class="btn btn-sm btn-link">Clear</a>
        @endif
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Oracle asset</th>
                    <th>Description</th>
                    <th>Purchased</th>
                    <th>Holder</th>
                    <th>NOC asset</th>
                    <th>Intune</th>
                    <th class="pe-3 text-end"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($units as $unit)
                    @php
                        $device = $unit->device;
                        $intune = $device?->azureDevice?->isInIntune() ? $device->azureDevice : null;
                        $inService = $device && ! in_array($device->status, ['retired', 'scrapped'], true);
                        $scrapRequest = $device ? ($pendingScrap[$device->id] ?? null) : null;
                        $assetLabel = $device ? trim(($device->asset_code ? $device->asset_code.' · ' : '').$device->name) : 'Oracle asset '.$unit->asset_number;
                        $holderName = $unit->employee?->name ?? $unit->emp_name;
                        $suggestions = (array) ($unit->match_evidence['suggestions'] ?? []);
                        $matched = in_array($unit->device_match, ['serial', 'model', 'only_pair', 'manual'], true) && $device;
                    @endphp
                    <tr class="{{ $unit->removed_at ? 'text-muted' : '' }}">
                        <td class="ps-3 text-nowrap">
                            <span class="font-monospace fw-semibold">{{ $unit->asset_number }}</span>
                            @if ($unit->unit > 1)
                                <span class="badge bg-light text-dark border" title="Second unit of this asset held by the same person">unit {{ $unit->unit }}</span>
                            @endif
                            @if ($unit->removed_at)
                                <div><span class="badge bg-light text-dark border">No longer in Oracle</span></div>
                            @endif
                        </td>
                        <td style="max-width:320px">
                            <div class="text-truncate" title="{{ $unit->description }}">{{ $unit->description }}</div>
                            <span class="badge {{ $unit->category === 'desktop' ? 'bg-info text-dark' : 'bg-light text-dark border' }}">{{ $unit->categoryLabel() }}</span>
                        </td>
                        <td class="text-nowrap">
                            {{ $unit->purchase_date?->format('d M Y') ?? '—' }}
                            @if ($unit->end_date)
                                <div class="text-muted">to {{ $unit->end_date->format('d M Y') }}</div>
                            @endif
                        </td>
                        <td>
                            @if ($unit->employee)
                                <a href="{{ route('admin.employees.show', $unit->employee) }}" class="text-decoration-none fw-semibold">{{ $unit->employee->name }}</a>
                                <span class="text-muted">{{ $unit->employee->branch?->name }}</span>
                                @if ($unit->employee->status !== 'active')
                                    <span class="badge bg-danger-subtle text-danger-emphasis">{{ ucfirst($unit->employee->status) }}</span>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                            <div class="text-muted">
                                Oracle #{{ $unit->emp_no }} {{ $unit->emp_name }}
                                @if ($unit->employee_match && $unit->employee_match !== 'number')
                                    <span class="badge {{ in_array($unit->employee_match, ['none', 'ambiguous'], true) ? 'bg-secondary' : 'bg-info text-dark' }}">{{ $unit->employeeMatchLabel() }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="text-nowrap">
                            @if ($device)
                                <a href="{{ route('admin.devices.show', $device) }}" class="text-decoration-none font-monospace">{{ $device->asset_code ?: $device->name }}</a>
                                <span class="badge {{ $device->statusBadgeClass() }}">{{ ucfirst($device->status) }}</span>
                                @if ($scrapRequest)
                                    <div><a href="{{ route('admin.itam.scrap.show', $scrapRequest) }}" class="badge bg-warning text-dark text-decoration-none">Scrap requested #{{ $scrapRequest }}</a></div>
                                @endif
                            @elseif ($unit->device_match)
                                <span class="badge bg-danger">Asset deleted</span>
                            @else
                                <span class="text-muted">None yet</span>
                            @endif
                        </td>
                        <td>
                            @if ($intune)
                                <a href="{{ route('admin.itam.azure.show', $intune->id) }}" class="badge bg-success text-decoration-none">
                                    <i class="bi bi-check2 me-1"></i>{{ $intune->display_name }}
                                </a>
                                @if ($unit->deviceMatchLabel() && $unit->device_match !== 'created')
                                    <div class="text-muted" title="{{ implode(', ', (array) ($unit->match_evidence['reasons'] ?? [])) }}">{{ $unit->deviceMatchLabel() }}</div>
                                @endif
                            @elseif ($inService)
                                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Not in Intune</span>
                                @foreach (array_slice($suggestions, 0, 2) as $suggestion)
                                    <div class="text-muted text-truncate" style="max-width:260px" title="{{ implode(', ', (array) ($suggestion['reasons'] ?? [])) }}">
                                        Possible: {{ $suggestion['label'] ?? '' }}
                                    </div>
                                @endforeach
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="pe-3 text-end text-nowrap">
                            @if (! $unit->removed_at || $device)
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Actions</button>
                                    <ul class="dropdown-menu dropdown-menu-end small">
                                        @if ($inService && ! $intune && $canImport)
                                            <li>
                                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#intuneLinkModal"
                                                        data-action="{{ route('admin.itam.devices.intune-link', $device) }}"
                                                        data-asset="{{ $assetLabel }}"
                                                        data-options="{{ json_encode($holderIntune[$unit->employee_id] ?? []) }}">
                                                    <i class="bi bi-link-45deg me-1"></i>Link to Intune device
                                                </button>
                                            </li>
                                        @endif
                                        @if ($matched && $canImport)
                                            <li>
                                                <form method="POST" action="{{ route('admin.itam.oracle-assets.unmatch', $unit) }}"
                                                      onsubmit="return confirm('This Oracle asset is not {{ addslashes($device->asset_code ?: $device->name) }}? The asset keeps its Intune link; the Oracle unit gets an asset of its own again.')">
                                                    @csrf @method('DELETE')
                                                    <button class="dropdown-item"><i class="bi bi-x-circle me-1"></i>Wrong match — undo</button>
                                                </form>
                                            </li>
                                        @endif
                                        @if ($inService && ! $scrapRequest && $canRetire)
                                            <li>
                                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#retireAssetModal"
                                                        data-action="{{ route('admin.devices.retire', $device) }}" data-asset="{{ $assetLabel }}" data-holder="{{ $holderName }}">
                                                    <i class="bi bi-archive me-1"></i>Retire
                                                </button>
                                            </li>
                                        @endif
                                        @if ($inService && ! $scrapRequest && $canScrap)
                                            <li>
                                                <button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#scrapAssetModal"
                                                        data-device="{{ $device->id }}" data-asset="{{ $assetLabel }}" data-holder="{{ $holderName }}">
                                                    <i class="bi bi-trash3 me-1"></i>Request scrap
                                                </button>
                                            </li>
                                        @endif
                                        @if (! $unit->isResolved() && ! $unit->removed_at && $canImport)
                                            <li>
                                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#oracleHolderModal"
                                                        data-action="{{ route('admin.itam.oracle-assets.employee', $unit) }}"
                                                        data-asset="Oracle asset {{ $unit->asset_number }} — held in Oracle by #{{ $unit->emp_no }} {{ $unit->emp_name }}">
                                                    <i class="bi bi-person-check me-1"></i>Set the holder
                                                </button>
                                            </li>
                                            <li>
                                                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#retireAssetModal"
                                                        data-action="{{ route('admin.itam.oracle-assets.retire', $unit) }}" data-asset="Oracle asset {{ $unit->asset_number }} · {{ \Illuminate\Support\Str::limit($unit->description, 60) }}"
                                                        data-holder="#{{ $unit->emp_no }} {{ $unit->emp_name }} (not in the NOC)">
                                                    <i class="bi bi-archive me-1"></i>Retire (write off)
                                                </button>
                                            </li>
                                        @endif
                                        @if ($device)
                                            <li><a class="dropdown-item" href="{{ route('admin.devices.show', $device) }}"><i class="bi bi-box-arrow-up-right me-1"></i>Open asset</a></li>
                                        @endif
                                    </ul>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-journal-check d-block display-5 mb-2 opacity-25"></i>
                            {{ $counts['all'] === 0 && $counts['removed'] === 0 ? 'Nothing imported yet. Import the register exported from Oracle.' : 'No Oracle assets match.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($units->hasPages())
        <div class="card-footer bg-transparent">{{ $units->links() }}</div>
    @endif
</div>

@if ($canImport)
    @include('admin.itam.oracle-assets._intune-link-modal', ['unlinkedIntune' => $unlinkedIntune])

    @if ($employees->isNotEmpty())
        <div class="modal fade" id="oracleHolderModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" class="modal-content" id="oracleHolderForm">
                    @csrf
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-person-check me-2"></i>Set the holder</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small mb-2"><strong data-holder-asset></strong></p>
                        <p class="small text-muted">
                            The employee who has this laptop now. It is then matched to their Intune devices, or becomes a new asset
                            assigned to them. This choice is never changed by a later import.
                        </p>
                        <input type="search" class="form-control form-control-sm mb-2" placeholder="Filter by name or Oracle no." id="oracleHolderFilter">
                        <select name="employee_id" id="oracleHolderSelect" class="form-select form-select-sm" size="10" required>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->name }}{{ $employee->oracle_emp_no ? ' · #'.$employee->oracle_emp_no : '' }}{{ $employee->branch ? ' · '.$employee->branch->name : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>Set holder</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        document.getElementById('oracleHolderModal')?.addEventListener('show.bs.modal', function (e) {
            const btn = e.relatedTarget;
            if (!btn) return;
            document.getElementById('oracleHolderForm').action = btn.getAttribute('data-action');
            this.querySelector('[data-holder-asset]').textContent = btn.getAttribute('data-asset') || '';
        });
        document.getElementById('oracleHolderFilter')?.addEventListener('input', function () {
            const needle = this.value.trim().toLowerCase();
            document.querySelectorAll('#oracleHolderSelect option').forEach(function (option) {
                option.hidden = needle !== '' && !option.textContent.toLowerCase().includes(needle);
            });
        });
        </script>
    @endif
@endif

@if ($canRetire || $canImport)
    @include('admin.itam.oracle-assets._retire-modal')
@endif
@if ($canScrap)
    @include('admin.itam.oracle-assets._scrap-modal')
@endif
@endsection
