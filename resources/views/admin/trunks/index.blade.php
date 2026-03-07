@extends('layouts.admin')

@section('content')

{{-- ─── Page Header ──────────────────────────────────────────── --}}
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-diagram-3-fill me-2"></i>VoIP Trunks</h1>
</div>


{{-- ─── UCM Server Selector ──────────────────────────────────── --}}
<div class="card mb-4">
    <div class="card-body">
        @if($ucmServers->isEmpty())
            <div class="text-center py-3">
                <i class="bi bi-hdd-network fs-2 text-muted"></i>
                <p class="mt-2 text-muted">No UCM servers configured yet.</p>
                <a href="{{ route('admin.settings.index') }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-gear me-1"></i> Go to Settings to add a UCM server
                </a>
            </div>
        @else
            <form method="GET" action="{{ route('admin.trunks.index') }}" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">
                        <i class="bi bi-hdd-network me-1"></i>Select UCM Server
                    </label>
                    <select name="ucm_id" class="form-select" onchange="this.form.submit()">
                        <option value="">— Choose a server —</option>
                        @foreach($ucmServers as $ucm)
                            <option value="{{ $ucm->id }}"
                                {{ optional($selectedUcm)->id == $ucm->id ? 'selected' : '' }}>
                                {{ $ucm->name }} — {{ $ucm->url }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @if($selectedUcm)
                <div class="col-auto">
                    <span class="badge bg-success fs-6 px-3 py-2">
                        <i class="bi bi-check-circle me-1"></i>
                        Connected: {{ $selectedUcm->name }}
                    </span>
                </div>
                @endif
            </form>
        @endif
    </div>
</div>

{{-- ─── Trunks Table ───────────────────────────────────────────── --}}
@if($selectedUcm && !$error)
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-list-ul me-2"></i>
            VoIP Trunks on <strong>{{ $selectedUcm->name }}</strong>
        </span>
        <span class="badge bg-secondary">{{ count($trunks) }} total</span>
    </div>

    @if(empty($trunks))
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-diagram-3 fs-1"></i>
            <p class="mt-3">No VoIP trunks found on this UCM server.</p>
        </div>
    @else
    <div class="card-body p-0">
        {{-- Search box --}}
        <div class="p-3 border-bottom">
            <input type="text" id="trunkSearch" class="form-control"
                placeholder="🔍  Search by trunk name, host, or type...">
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="trunksTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:80px">#</th>
                        <th>Trunk Name</th>
                        <th>Host</th>
                        <th>Type</th>
                        <th>Username</th>
                        <th>Admin Status</th>
                        <th>Reachable</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($trunks as $trunk)
                    @php
                        $typeColor = match($trunk['trunk_type'] ?? '') {
                            'peer'     => 'primary',
                            'register' => 'info',
                            default    => 'secondary',
                        };
                        $outOfService = ($trunk['out_of_service'] ?? 'no') === 'yes';

                        // status field from UCM
                        $trunkStatus = $trunk['status'] ?? null;
                        $statusColor = match($trunkStatus) {
                            'Reachable', 'Registered'               => 'success',
                            'Unreachable', 'Unregistered', 'Failed',
                            'Rejected', 'Timeout'                   => 'danger',
                            'Lagged'                                => 'warning',
                            'Request Sent'                          => 'info',
                            default                                 => 'secondary',
                        };
                        $statusIcon = match($trunkStatus) {
                            'Reachable', 'Registered'               => 'bi-wifi',
                            'Unreachable', 'Unregistered', 'Failed',
                            'Rejected', 'Timeout'                   => 'bi-wifi-off',
                            'Lagged'                                => 'bi-exclamation-triangle',
                            'Request Sent'                          => 'bi-arrow-clockwise',
                            default                                 => 'bi-question-circle',
                        };
                    @endphp
                    <tr>
                        <td class="text-muted">{{ $trunk['trunk_index'] ?? '-' }}</td>
                        <td><strong>{{ $trunk['trunk_name'] ?? '—' }}</strong></td>
                        <td>
                            @if(!empty($trunk['host']))
                                <code>{{ $trunk['host'] }}</code>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-{{ $typeColor }} text-capitalize">
                                {{ $trunk['trunk_type'] ?? '—' }}
                            </span>
                        </td>
                        <td>
                            @if(!empty($trunk['username']))
                                <span class="font-monospace">{{ $trunk['username'] }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($outOfService)
                                <span class="badge bg-danger">
                                    <i class="bi bi-x-circle me-1"></i>Out of Service
                                </span>
                            @else
                                <span class="badge bg-success">
                                    <i class="bi bi-check-circle me-1"></i>Active
                                </span>
                            @endif
                        </td>
                        <td>
                            @if($trunkStatus)
                                <span class="badge bg-{{ $statusColor }}">
                                    <i class="bi {{ $statusIcon }} me-1"></i>{{ $trunkStatus }}
                                </span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
@endif

{{-- ─── Scripts ────────────────────────────────────────────────── --}}
@push('scripts')
<script>
document.getElementById('trunkSearch')?.addEventListener('keyup', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#trunksTable tbody tr').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
@endpush

@endsection
