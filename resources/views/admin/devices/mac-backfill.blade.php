@extends('layouts.admin')
@section('content')

<div class="mb-4">
    <a href="{{ route('admin.devices.index') }}" class="btn btn-link link-secondary ps-0">
        <i class="bi bi-arrow-left me-1"></i> Back to Assets
    </a>
    <h4 class="mt-2 mb-1 fw-bold"><i class="bi bi-ethernet me-2 text-primary"></i>Backfill MACs from DHCP</h4>
    <p class="text-muted small mb-0">
        Assets that have an IP but no MAC address, matched against the freshest DHCP lease for that IP.
        Review the evidence (hostname, vendor, when last seen), tick the mappings you trust, then apply.
        Ambiguous rows — multiple MACs seen on the IP, stale leases, or a MAC already on another asset — are unticked by default.
    </p>
</div>

@if($rows->isEmpty())
    <div class="card shadow-sm border-0">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-check2-circle fs-1 d-block mb-3 opacity-25"></i>
            @if($missingCount === 0)
                Every asset with an IP already has a MAC address — nothing to backfill.
            @else
                {{ $missingCount }} asset(s) are missing a MAC, but none of their IPs appear in the DHCP leases.
            @endif
        </div>
    </div>
@else
<form method="POST" action="{{ route('admin.devices.mac-backfill.apply') }}">
    @csrf
    <div class="card shadow-sm border-0">
        <div class="card-header bg-transparent d-flex align-items-center">
            <strong>{{ $rows->count() }} proposed mapping(s)</strong>
            <small class="text-muted ms-2">({{ $missingCount }} asset(s) missing a MAC in total)</small>
            <button type="submit" class="btn btn-primary btn-sm ms-auto" id="applyBtn">
                <i class="bi bi-check2-square me-1"></i>Apply <span id="selCount">0</span> selected
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:36px">
                                <input type="checkbox" class="form-check-input" id="checkAll" title="Select all">
                            </th>
                            <th>Asset</th>
                            <th>IP</th>
                            <th>Proposed MAC</th>
                            <th>Lease Hostname</th>
                            <th>Vendor</th>
                            <th>Lease Source</th>
                            <th>Last Seen</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $r)
                        <tr class="{{ $r['preselect'] ? '' : 'table-warning' }}">
                            <td class="ps-3">
                                <input type="checkbox" class="form-check-input row-check" name="device_ids[]"
                                       value="{{ $r['device']->id }}" {{ $r['preselect'] ? 'checked' : '' }}>
                            </td>
                            <td>
                                <a href="{{ route('admin.devices.show', $r['device']) }}" class="fw-bold text-decoration-none">
                                    {{ $r['device']->name }}
                                </a>
                                <div class="text-muted">
                                    {{ $r['device']->asset_code ?? '—' }} · {{ $r['device']->type }}
                                    @if($r['device']->branch) · {{ $r['device']->branch->name }} @endif
                                </div>
                            </td>
                            <td class="font-monospace">{{ $r['device']->ip_address }}</td>
                            <td class="font-monospace fw-bold">{{ $r['mac'] }}</td>
                            <td>{{ $r['lease']->hostname ?? '—' }}</td>
                            <td>{{ $r['lease']->vendor ?? '—' }}</td>
                            <td><span class="badge bg-light text-dark border">{{ $r['lease']->source }}</span></td>
                            <td class="text-muted" title="{{ $r['lease']->last_seen }}">
                                {{ $r['lease']->last_seen?->diffForHumans() ?? '—' }}
                            </td>
                            <td>
                                @if($r['used_by'])
                                    <span class="badge bg-danger" title="This MAC is already on asset: {{ $r['used_by']->name }}">
                                        MAC on {{ Str::limit($r['used_by']->name, 20) }}
                                    </span>
                                @endif
                                @if($r['multiple_macs'])
                                    <span class="badge bg-warning text-dark" title="More than one MAC has held this IP — verify before applying">
                                        multiple MACs on IP
                                    </span>
                                @endif
                                @if($r['stale'])
                                    <span class="badge bg-secondary" title="Lease not seen in over 30 days">stale lease</span>
                                @endif
                                @if($r['preselect'])
                                    <span class="text-success"><i class="bi bi-check-circle me-1"></i>clean match</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>
@endif

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const checks = [...document.querySelectorAll('.row-check')];
    const all = document.getElementById('checkAll');
    const btn = document.getElementById('applyBtn');
    const count = document.getElementById('selCount');

    function refresh() {
        const n = checks.filter(c => c.checked).length;
        if (count) count.textContent = n;
        if (btn) btn.disabled = n === 0;
        if (all) all.checked = n > 0 && n === checks.length;
    }
    checks.forEach(c => c.addEventListener('change', refresh));
    if (all) all.addEventListener('change', () => { checks.forEach(c => c.checked = all.checked); refresh(); });
    refresh();
});
</script>
@endpush

@endsection
