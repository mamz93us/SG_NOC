@extends('layouts.admin')
@section('title', 'SSL Certificates — ' . $domain)

@section('content')
<div class="container-fluid py-4" x-data="certManager()">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-shield-check me-2"></i>SSL Certificate Manager</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.index') }}">DNS Accounts</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.domains.index', $account) }}">{{ $account->label }}</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.subdomains.index', [$account, $domain]) }}">{{ $domain }}</a></li>
                    <li class="breadcrumb-item active">Certificates</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            @can('manage-dns')
            <button class="btn btn-success btn-sm" @click="renewAllExpiring()" :disabled="saving">
                <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                <i class="bi bi-arrow-repeat" x-show="!saving"></i> Renew All Expiring
            </button>
            @endcan
            <a href="{{ route('admin.network.dns.subdomains.index', [$account, $domain]) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    {{-- Toast --}}
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
        <div class="toast align-items-center border-0" :class="toast.type === 'success' ? 'text-bg-success' : 'text-bg-danger'" x-ref="toast" role="alert">
            <div class="d-flex">
                <div class="toast-body" x-text="toast.message"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>FQDN</th>
                        <th>Issuer</th>
                        <th>Issued</th>
                        <th>Expires</th>
                        <th>Days Left</th>
                        <th>Auto-Renew</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($certs as $cert)
                    <tr class="{{ $cert->expiryStatus() === 'expiring_soon' ? 'table-warning' : ($cert->expiryStatus() === 'expired' ? 'table-danger' : '') }}">
                        <td class="fw-semibold">{{ $cert->fqdn }}</td>
                        <td><small>{{ $cert->issuerLabel() }}</small></td>
                        <td><small>{{ $cert->issued_at?->format('Y-m-d') ?? '-' }}</small></td>
                        <td><small>{{ $cert->expires_at?->format('Y-m-d') ?? '-' }}</small></td>
                        <td>
                            @if($cert->expires_at)
                                <span class="{{ $cert->daysUntilExpiry() < 0 ? 'text-danger' : ($cert->daysUntilExpiry() < 14 ? 'text-warning' : '') }}">
                                    {{ $cert->daysUntilExpiry() < 0 ? 'Expired' : $cert->daysUntilExpiry() . 'd' }}
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            <i class="bi {{ $cert->auto_renew ? 'bi-check-circle text-success' : 'bi-x-circle text-muted' }}"></i>
                        </td>
                        <td>
                            <span class="badge {{ $cert->statusBadgeClass() }}">{{ ucfirst($cert->status) }}</span>
                            @if($cert->failure_reason)
                            <i class="bi bi-info-circle text-muted ms-1" title="{{ $cert->failure_reason }}" data-bs-toggle="tooltip"></i>
                            @endif
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                @can('manage-dns')
                                @if(in_array($cert->status, ['valid', 'expired', 'expiring_soon']))
                                <button class="btn btn-outline-success"
                                        onclick="renewCert({{ $cert->id }})"
                                        title="Renew">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                                @endif
                                @if($cert->status === 'valid')
                                <button class="btn btn-outline-secondary"
                                        onclick="openExportModal({{ $cert->id }}, '{{ $cert->fqdn }}', '{{ $cert->expires_at?->format('Y-m-d') }}')"
                                        title="Export">
                                    <i class="bi bi-download"></i>
                                </button>
                                <button class="btn btn-outline-warning"
                                        onclick="revokeCert({{ $cert->id }}, '{{ $cert->fqdn }}')"
                                        title="Revoke">
                                    <i class="bi bi-shield-x"></i>
                                </button>
                                @endif
                                <form method="POST" action="{{ route('admin.network.dns.certificates.destroy', [$account, $domain, $cert]) }}" class="d-inline"
                                      onsubmit="return confirm('Delete certificate for {{ $cert->fqdn }}?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-shield-check display-4 d-block mb-2"></i>
                            No certificates found for {{ $domain }}.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Export Modal --}}
    <div class="modal fade" id="exportCertModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-download me-2"></i>Export Certificate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" x-data="exportModal()">
                    <p class="text-muted small mb-3">
                        <strong id="exportFqdn"></strong>
                        &mdash; valid until <span id="exportExpiry"></span>
                    </p>
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-secondary text-start" onclick="triggerDownload('pem')">
                            <i class="bi bi-file-text me-2"></i><strong>PEM</strong> — Fullchain certificate
                        </button>
                        <button class="btn btn-outline-secondary text-start" onclick="triggerDownload('cer')">
                            <i class="bi bi-file-earmark me-2"></i><strong>CER</strong> — Certificate (DER/Base64)
                        </button>
                        <button class="btn btn-outline-secondary text-start" onclick="triggerDownload('key')">
                            <i class="bi bi-key me-2"></i><strong>KEY</strong> — Private key
                            <span class="badge bg-warning text-dark ms-1">Audit-logged</span>
                        </button>
                        <div class="input-group">
                            <button class="btn btn-outline-secondary text-start flex-grow-1" onclick="triggerDownload('p12')">
                                <i class="bi bi-archive me-2"></i><strong>P12</strong> — PKCS#12 bundle
                            </button>
                            <input type="password" id="p12Password" class="form-control" placeholder="Password (optional)" style="max-width:180px">
                        </div>
                        <button class="btn btn-outline-primary text-start" onclick="triggerDownload('bundle')">
                            <i class="bi bi-file-zip me-2"></i><strong>ZIP</strong> — All formats bundled
                        </button>
                    </div>
                    <p class="text-warning small mt-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Private key exports are audit-logged with your user ID and IP address.</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
let currentCertId = null;

function openExportModal(certId, fqdn, expiry) {
    currentCertId = certId;
    document.getElementById('exportFqdn').textContent = fqdn;
    document.getElementById('exportExpiry').textContent = expiry;
    new bootstrap.Modal(document.getElementById('exportCertModal')).show();
}

function triggerDownload(format) {
    if (!currentCertId) return;
    const password = document.getElementById('p12Password')?.value || '';
    const params = new URLSearchParams({ format });
    if (password) params.append('password', password);

    const a = document.createElement('a');
    a.href = `{{ url('admin/network/dns') }}/{{ $account->id }}/domains/{{ $domain }}/certificates/${currentCertId}/export?${params}`;
    a.download = '';
    a.click();
}

function renewCert(certId) {
    if (!confirm('Renew this certificate? This will initiate a new ACME DNS-01 challenge.')) return;
    fetch(`{{ url('admin/network/dns') }}/{{ $account->id }}/domains/{{ $domain }}/certificates/${certId}/renew`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) location.reload();
    });
}

function revokeCert(certId, fqdn) {
    if (!confirm(`Revoke certificate for ${fqdn}? This cannot be undone.`)) return;
    fetch(`{{ url('admin/network/dns') }}/{{ $account->id }}/domains/{{ $domain }}/certificates/${certId}/revoke`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        alert(data.message);
        if (data.success) location.reload();
    });
}

function certManager() {
    return {
        saving: false,
        toast: { message: '', type: 'success' },

        async renewAllExpiring() {
            if (!confirm('Renew all certificates expiring within 14 days?')) return;
            this.saving = true;
            try {
                // Dispatch renewal for each expiring cert
                const expiring = @json($certs->filter(fn($c) => $c->daysUntilExpiry() !== null && $c->daysUntilExpiry() <= 14 && $c->status === 'valid')->pluck('id'));
                for (const certId of expiring) {
                    await fetch(`{{ url('admin/network/dns') }}/{{ $account->id }}/domains/{{ $domain }}/certificates/${certId}/renew`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
                    });
                }
                this.showToast(`${expiring.length} certificate(s) renewal initiated.`, 'success');
                setTimeout(() => location.reload(), 2000);
            } catch(e) {
                this.showToast('Failed to renew certificates.', 'error');
            } finally {
                this.saving = false;
            }
        },

        showToast(message, type) {
            this.toast = { message, type };
            const el = this.$refs.toast;
            if (el) new bootstrap.Toast(el, { delay: 4000 }).show();
        }
    };
}

// Init tooltips
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
});
</script>
@endpush
@endsection
