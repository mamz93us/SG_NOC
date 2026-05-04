@extends('layouts.admin')
@section('title', 'Asset Transfer')

@section('content')
<div class="container-fluid py-4" x-data="transferForm()">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Asset Transfer</h4>
        <a href="{{ route('admin.itam.dashboard') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>ITAM Dashboard
        </a>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif

    <form method="POST" action="{{ route('admin.itam.transfer.store') }}" @submit="onSubmit($event)">
        @csrf

        {{-- Step 1: Source employee --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <strong>1. Source Employee</strong> — pick whose assets are being transferred
            </div>
            <div class="card-body">
                <select name="from_employee_id" class="form-select" x-model="fromEmployeeId" @change="loadAssets()" required>
                    <option value="">— Select employee —</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}">{{ $emp->name }} ({{ $emp->email }})</option>
                    @endforeach
                </select>
                <small class="text-muted">Only employees with at least one assigned asset are listed.</small>
            </div>
        </div>

        {{-- Step 2: Asset selection --}}
        <div class="card border-0 shadow-sm mb-4" x-show="fromEmployeeId">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>2. Select Assets</strong>
                <span class="text-muted small" x-text="selectedCount() + ' of ' + assets.length + ' selected'"></span>
            </div>
            <div class="card-body p-0">
                <template x-if="loading">
                    <div class="text-center py-4 text-muted">Loading assets...</div>
                </template>
                <template x-if="!loading && assets.length === 0 && fromEmployeeId">
                    <div class="text-center py-4 text-muted">No active assets for this employee.</div>
                </template>
                <table class="table table-sm mb-0" x-show="!loading && assets.length > 0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px">
                                <input type="checkbox" class="form-check-input" @change="toggleAll($event.target.checked)" :checked="allSelected()">
                            </th>
                            <th>Asset Code</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Serial</th>
                            <th>Condition</th>
                            <th>Assigned</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="asset in assets" :key="asset.device_id">
                            <tr>
                                <td>
                                    <input type="checkbox" name="asset_ids[]" :value="asset.device_id" x-model="selectedIds" class="form-check-input">
                                </td>
                                <td><code x-text="asset.asset_code || '-'"></code></td>
                                <td x-text="asset.name"></td>
                                <td><span class="badge bg-secondary" x-text="asset.type"></span></td>
                                <td x-text="asset.serial_number || '-'"></td>
                                <td><span class="badge bg-info" x-text="asset.condition"></span></td>
                                <td x-text="asset.assigned_date"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Step 3: Destination --}}
        <div class="card border-0 shadow-sm mb-4" x-show="selectedCount() > 0">
            <div class="card-header bg-white"><strong>3. Destination</strong></div>
            <div class="card-body">
                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item">
                        <a class="nav-link" :class="targetType === 'employee' ? 'active' : ''" href="#" @click.prevent="targetType = 'employee'">
                            <i class="bi bi-person-badge me-1"></i>Another Employee
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" :class="targetType === 'branch_store' ? 'active' : ''" href="#" @click.prevent="targetType = 'branch_store'">
                            <i class="bi bi-box-seam me-1"></i>Branch Store
                        </a>
                    </li>
                </ul>

                <input type="hidden" name="target_type" :value="targetType">

                <div x-show="targetType === 'employee'">
                    <label class="form-label">Target Employee</label>
                    <select name="to_employee_id" class="form-select" :required="targetType === 'employee'">
                        <option value="">— Select employee —</option>
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->name }} ({{ $emp->email }})</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Tip: target employee must be different from the source.</small>
                </div>

                <div x-show="targetType === 'branch_store'" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Target Branch</label>
                        <select name="to_branch_id" class="form-select" :required="targetType === 'branch_store'">
                            <option value="">— Select branch —</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Storage Location</label>
                        <input type="text" name="storage_location" class="form-control" placeholder="e.g. IT Store - Shelf B" :required="targetType === 'branch_store'">
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 4: Details --}}
        <div class="card border-0 shadow-sm mb-4" x-show="selectedCount() > 0">
            <div class="card-header bg-white"><strong>4. Transfer Details</strong></div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label">Transfer Date</label>
                    <input type="date" name="transfer_date" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Asset Condition</label>
                    <select name="condition" class="form-select" required>
                        <option value="good" selected>Good</option>
                        <option value="fair">Fair</option>
                        <option value="poor">Poor</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="2" maxlength="1000"></textarea>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2" x-show="selectedCount() > 0">
            <button type="reset" class="btn btn-outline-secondary" @click="resetForm()">Reset</button>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-circle me-1"></i>Transfer & Print Slip
            </button>
        </div>
    </form>
</div>

<script>
function transferForm() {
    return {
        fromEmployeeId: '',
        assets: [],
        selectedIds: [],
        targetType: 'employee',
        loading: false,
        async loadAssets() {
            this.assets = [];
            this.selectedIds = [];
            if (!this.fromEmployeeId) return;
            this.loading = true;
            try {
                const res = await fetch(`{{ url('admin/itam/transfer/employee') }}/${this.fromEmployeeId}/assets`, {
                    headers: {'Accept': 'application/json'}
                });
                const data = await res.json();
                this.assets = data.assets || [];
            } finally {
                this.loading = false;
            }
        },
        selectedCount() { return this.selectedIds.length; },
        allSelected() { return this.assets.length > 0 && this.selectedIds.length === this.assets.length; },
        toggleAll(checked) {
            this.selectedIds = checked ? this.assets.map(a => a.device_id) : [];
        },
        resetForm() {
            this.fromEmployeeId = '';
            this.assets = [];
            this.selectedIds = [];
            this.targetType = 'employee';
        },
        onSubmit(e) {
            if (this.selectedIds.length === 0) {
                e.preventDefault();
                alert('Please select at least one asset.');
            }
        }
    };
}
</script>
@endsection
