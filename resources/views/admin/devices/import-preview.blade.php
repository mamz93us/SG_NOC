@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-check me-2 text-success"></i>Import Preview</h4>
        <small class="text-muted">Review the data before applying changes</small>
    </div>
    <a href="{{ route('admin.devices.import') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Upload
    </a>
</div>

@php
    $updates = collect($preview)->where('action', 'update')->count();
    $creates = collect($preview)->where('action', 'create')->count();
@endphp

{{-- Summary --}}
<div class="row g-2 mb-3">
    <div class="col-auto">
        <div class="card px-3 py-2 text-center">
            <div class="fw-bold fs-5">{{ count($preview) }}</div>
            <small class="text-muted">Total Rows</small>
        </div>
    </div>
    <div class="col-auto">
        <div class="card px-3 py-2 text-center border-primary">
            <div class="fw-bold fs-5 text-primary">{{ $updates }}</div>
            <small class="text-muted">Update Serial</small>
        </div>
    </div>
    <div class="col-auto">
        <div class="card px-3 py-2 text-center border-success">
            <div class="fw-bold fs-5 text-success">{{ $creates }}</div>
            <small class="text-muted">Create New</small>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <form method="POST" action="{{ route('admin.devices.import.apply') }}" id="importForm">
            @csrf
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:40px">
                                <input type="checkbox" class="form-check-input" id="selectAll" checked>
                            </th>
                            <th>MAC Address</th>
                            <th>Serial Number</th>
                            <th>Existing Device</th>
                            <th>Current Serial</th>
                            <th>Model (from logs)</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($preview as $idx => $row)
                        <tr class="{{ $row['action'] === 'update' ? 'table-info' : 'table-success' }} bg-opacity-25">
                            <td class="ps-3">
                                <input type="checkbox" class="form-check-input import-cb"
                                       name="selected[]" value="{{ $idx }}" checked>
                            </td>
                            <td class="font-monospace">{{ $row['mac_display'] }}</td>
                            <td class="fw-semibold">{{ $row['serial'] ?: '—' }}</td>
                            <td>
                                @if($row['existing_device'])
                                <a href="{{ route('admin.devices.show', $row['existing_device']['id']) }}" class="text-decoration-none">
                                    {{ $row['existing_device']['name'] }}
                                </a>
                                @else
                                <span class="text-muted">— (new device)</span>
                                @endif
                            </td>
                            <td class="text-muted">
                                {{ $row['existing_device']['current_serial'] ?? '—' }}
                            </td>
                            <td>{{ $row['model_from_log'] ?: '—' }}</td>
                            <td>
                                @if($row['action'] === 'update')
                                <span class="badge bg-primary"><i class="bi bi-pencil me-1"></i>Update Serial</span>
                                @else
                                <span class="badge bg-success"><i class="bi bi-plus-lg me-1"></i>Create Device</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-3 bg-light border-top d-flex justify-content-between align-items-center">
                <span class="text-muted small"><strong id="selectedCount">{{ count($preview) }}</strong> item(s) selected</span>
                <div class="d-flex gap-2">
                    <a href="{{ route('admin.devices.import') }}" class="btn btn-secondary btn-sm">Cancel</a>
                    <button type="submit" class="btn btn-success btn-sm" id="applyBtn">
                        <i class="bi bi-check-lg me-1"></i>Apply Selected
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
const selectAll  = document.getElementById('selectAll');
const checkboxes = document.querySelectorAll('.import-cb');
const countEl    = document.getElementById('selectedCount');
const btn        = document.getElementById('applyBtn');

function updateCount() {
    const checked = document.querySelectorAll('.import-cb:checked').length;
    countEl.textContent = checked;
    btn.disabled = checked === 0;
    selectAll.checked = checked === checkboxes.length && checkboxes.length > 0;
}

selectAll.addEventListener('change', function () {
    checkboxes.forEach(cb => cb.checked = this.checked);
    updateCount();
});

checkboxes.forEach(cb => cb.addEventListener('change', updateCount));
</script>
@endpush

@endsection
