@extends('layouts.admin')
@section('content')

@php
    // Normalise: prefer new multi-sku array, fall back to legacy single-sku string
    $currentSkus = $settings->graph_default_license_skus
        ?? ($settings->graph_default_license_sku ? [$settings->graph_default_license_sku] : []);
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-patch-check-fill me-2 text-primary"></i>Provisioning Licenses</h4>
        <small class="text-muted">Select one or more Microsoft 365 licenses to assign automatically when provisioning new users</small>
    </div>
    <a href="{{ route('admin.settings.index') }}#provisioning" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Settings
    </a>
</div>


{{-- Current defaults --}}
<div class="alert {{ !empty($currentSkus) ? 'alert-info' : 'alert-warning' }} mb-4">
    <i class="bi bi-{{ !empty($currentSkus) ? 'info-circle' : 'exclamation-triangle' }}-fill me-2"></i>
    @if(!empty($currentSkus))
        <strong>Current defaults ({{ count($currentSkus) }} license{{ count($currentSkus) !== 1 ? 's' : '' }}):</strong>
        <ul class="mb-0 mt-1 ps-3">
        @foreach($currentSkus as $sku)
            @php
                $name = collect($licenses)->firstWhere('skuId', $sku)['skuPartNumber'] ?? $sku;
            @endphp
            <li><span class="fw-semibold">{{ $name }}</span> <code class="ms-1 small">{{ $sku }}</code></li>
        @endforeach
        </ul>
    @else
        <strong>No default licenses set.</strong> Users will be provisioned without a license until one or more are selected.
    @endif
</div>

@if($error)
{{-- Azure error — allow manual entry --}}
<div class="alert alert-danger">
    <i class="bi bi-x-circle-fill me-2"></i>
    <strong>Could not fetch licenses from Azure:</strong> {{ $error }}
    <div class="mt-2 small">Check that Microsoft Graph credentials are configured in
        <a href="{{ route('admin.settings.index') }}#graph" class="alert-link">General Settings → Microsoft Graph</a>.
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent"><strong><i class="bi bi-keyboard me-1"></i>Set License SKU Manually</strong></div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.settings.provisioning-licenses.save') }}">
            @csrf
            <p class="text-muted small mb-2">Enter a single SKU ID. Once Azure connectivity is restored you can pick multiple from the list.</p>
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-8">
                    <label class="form-label fw-semibold">License SKU ID (GUID)</label>
                    <input type="text" name="license_sku" class="form-control font-monospace"
                           value="{{ old('license_sku', $currentSkus[0] ?? '') }}"
                           placeholder="e.g. 6fd2c87f-b296-42f0-b197-1e91e994b900">
                    <div class="form-text">Find SKU IDs in the Azure Portal under Licenses, or via Microsoft's published SKU list.</div>
                </div>
                <div class="col-auto d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                    @if(!empty($currentSkus))
                    <button type="submit" name="license_sku" value="" class="btn btn-outline-secondary">
                        <i class="bi bi-x-lg me-1"></i>Clear
                    </button>
                    @endif
                </div>
            </div>
        </form>
    </div>
</div>

@elseif(empty($licenses))
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>No licenses found in your Azure tenant.
</div>

@else
{{-- License table with checkboxes (multi-select) --}}
<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
        <strong><i class="bi bi-list-check me-1"></i>Available Licenses</strong>
        <span class="badge bg-secondary">{{ count($licenses) }} SKU{{ count($licenses) !== 1 ? 's' : '' }}</span>
    </div>
    <div class="card-body p-0">
        <form method="POST" action="{{ route('admin.settings.provisioning-licenses.save') }}">
            @csrf
            {{-- Sentinel: ensures PHP receives license_skus as an array even when all checkboxes are unchecked --}}
            <input type="hidden" name="license_skus" value="">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:44px">
                                <div class="form-check d-flex justify-content-center mb-0" title="Select / deselect all">
                                    <input class="form-check-input" type="checkbox" id="selectAll">
                                </div>
                            </th>
                            <th>License Name</th>
                            <th>SKU ID</th>
                            <th class="text-center">Used / Total</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($licenses as $lic)
                        @php
                            $skuId      = $lic['skuId'] ?? '';
                            $skuName    = $lic['skuPartNumber'] ?? $skuId;
                            $consumed   = $lic['consumedUnits'] ?? 0;
                            $enabled    = $lic['prepaidUnits']['enabled']   ?? 0;
                            $warning    = $lic['prepaidUnits']['warning']   ?? 0;
                            $suspended  = $lic['prepaidUnits']['suspended'] ?? 0;
                            $total      = $enabled + $warning + $suspended;
                            $capStatus  = $lic['capabilityStatus'] ?? 'Enabled';
                            $isChecked  = in_array($skuId, $currentSkus);
                        @endphp
                        <tr class="{{ $isChecked ? 'table-primary' : '' }}" id="row_{{ $loop->index }}">
                            <td class="ps-3 text-center">
                                <div class="form-check d-flex justify-content-center mb-0">
                                    <input class="form-check-input sku-checkbox" type="checkbox"
                                           name="license_skus[]"
                                           id="lic_{{ $loop->index }}"
                                           value="{{ $skuId }}"
                                           {{ $isChecked ? 'checked' : '' }}
                                           data-row="row_{{ $loop->index }}">
                                </div>
                            </td>
                            <td>
                                <label for="lic_{{ $loop->index }}" class="fw-semibold mb-0" style="cursor:pointer">
                                    {{ $skuName }}
                                </label>
                                @if($isChecked)
                                <span class="badge bg-primary ms-1">Selected</span>
                                @endif
                            </td>
                            <td><code class="small" style="font-size:.7rem">{{ $skuId }}</code></td>
                            <td class="text-center">
                                <span class="{{ $consumed >= $total && $total > 0 ? 'text-danger fw-semibold' : '' }}">
                                    {{ $consumed }}
                                </span>
                                <span class="text-muted">/ {{ $total ?: '∞' }}</span>
                            </td>
                            <td class="text-center">
                                @if($capStatus === 'Enabled')
                                <span class="badge bg-success">Enabled</span>
                                @elseif($capStatus === 'Warning')
                                <span class="badge bg-warning text-dark">Warning</span>
                                @elseif($capStatus === 'Suspended')
                                <span class="badge bg-danger">Suspended</span>
                                @else
                                <span class="badge bg-secondary">{{ $capStatus }}</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-3 py-3 border-top d-flex gap-2 align-items-center">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check-lg me-1"></i>Save Default License(s)
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="clearAllBtn">
                    <i class="bi bi-x-lg me-1"></i>Clear All
                </button>
                <span class="text-muted small ms-auto" id="selectedCount">
                    {{ count($currentSkus) }} selected
                </span>
            </div>
        </form>
    </div>
</div>
@endif

@push('scripts')
<script>
(function () {
    const selectAllCb = document.getElementById('selectAll');
    const checkboxes  = document.querySelectorAll('.sku-checkbox');
    const countLabel  = document.getElementById('selectedCount');
    const clearBtn    = document.getElementById('clearAllBtn');
    if (!checkboxes.length) return;

    function updateCount() {
        const n = document.querySelectorAll('.sku-checkbox:checked').length;
        if (countLabel) countLabel.textContent = n + ' selected';
        if (selectAllCb) {
            selectAllCb.indeterminate = n > 0 && n < checkboxes.length;
            selectAllCb.checked = n === checkboxes.length && checkboxes.length > 0;
        }
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', () => {
            const row = document.getElementById(cb.dataset.row);
            if (row) row.classList.toggle('table-primary', cb.checked);
            updateCount();
        });
    });

    if (selectAllCb) {
        selectAllCb.addEventListener('change', () => {
            checkboxes.forEach(cb => {
                cb.checked = selectAllCb.checked;
                const row = document.getElementById(cb.dataset.row);
                if (row) row.classList.toggle('table-primary', cb.checked);
            });
            updateCount();
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            checkboxes.forEach(cb => {
                cb.checked = false;
                const row = document.getElementById(cb.dataset.row);
                if (row) row.classList.remove('table-primary');
            });
            updateCount();
        });
    }

    updateCount();
})();
</script>
@endpush

@endsection
