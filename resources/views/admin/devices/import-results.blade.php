@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-check-circle me-2 text-success"></i>Import Results</h4>
        <small class="text-muted">{{ $source }} — completed successfully</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.devices.import') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrow-repeat me-1"></i>Import More
        </a>
        <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Devices
        </a>
    </div>
</div>

{{-- Summary --}}
<div class="row g-2 mb-3">
    <div class="col-auto">
        <div class="card px-3 py-2 text-center border-success">
            <div class="fw-bold fs-5 text-success">{{ count($results) }}</div>
            <small class="text-muted">Total Processed</small>
        </div>
    </div>
    @if($created > 0)
    <div class="col-auto">
        <div class="card px-3 py-2 text-center border-success">
            <div class="fw-bold fs-5 text-success">{{ $created }}</div>
            <small class="text-muted">Created</small>
        </div>
    </div>
    @endif
    @if($updated > 0)
    <div class="col-auto">
        <div class="card px-3 py-2 text-center border-primary">
            <div class="fw-bold fs-5 text-primary">{{ $updated }}</div>
            <small class="text-muted">Updated</small>
        </div>
    </div>
    @endif
    @if(isset($skipped) && $skipped > 0)
    <div class="col-auto">
        <div class="card px-3 py-2 text-center border-warning">
            <div class="fw-bold fs-5 text-warning">{{ $skipped }}</div>
            <small class="text-muted">Skipped</small>
        </div>
    </div>
    @endif
</div>

{{-- Results Table --}}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th>MAC Address</th>
                        <th>IP Address</th>
                        <th>Serial Number</th>
                        <th>Model</th>
                        <th>Device</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($results as $i => $row)
                    <tr class="{{ $row['action'] === 'created' ? 'table-success' : 'table-info' }} bg-opacity-25">
                        <td class="ps-3 text-muted">{{ $i + 1 }}</td>
                        <td class="font-monospace">{{ $row['mac_display'] ?: '—' }}</td>
                        <td class="font-monospace">{{ $row['ip'] ?? '—' }}</td>
                        <td class="fw-semibold">{{ $row['serial'] ?: '—' }}</td>
                        <td>{{ $row['model'] ?: '—' }}</td>
                        <td>
                            <a href="{{ route('admin.devices.show', $row['device_id']) }}" class="text-decoration-none">
                                {{ $row['device_name'] }}
                            </a>
                        </td>
                        <td>
                            @if($row['action'] === 'created')
                            <span class="badge bg-success"><i class="bi bi-plus-lg me-1"></i>Created</span>
                            @else
                            <span class="badge bg-primary"><i class="bi bi-pencil me-1"></i>Updated</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@endsection
