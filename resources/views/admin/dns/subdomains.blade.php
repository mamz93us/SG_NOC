@extends('layouts.admin')
@section('title', 'Subdomains & SSL — ' . $domain)

@section('content')
<div class="container-fluid py-4" x-data="subdomainManager()">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-diagram-2 me-2"></i>Subdomains & SSL</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.index') }}">DNS Accounts</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.domains.index', $account) }}">{{ $account->label }}</a></li>
                    <li class="breadcrumb-item active">{{ $domain }} — Subdomains</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            @can('manage-dns')
            <button class="btn btn-primary btn-sm" @click="openAddModal()">
                <i class="bi bi-plus-lg"></i> Add Subdomain
            </button>
            <button class="btn btn-outline-secondary btn-sm" @click="syncFromGoDaddy()" :disabled="syncing">
                <span x-show="syncing" class="spinner-border spinner-border-sm"></span>
                <i class="bi bi-arrow-repeat" x-show="!syncing"></i> Sync
            </button>
            @endcan
            <a href="{{ route('admin.network.dns.certificates.index', [$account, $domain]) }}" class="btn btn-outline-info btn-sm">
                <i class="bi bi-shield-check me-1"></i> Cert Manager
            </a>
            <a href="{{ route('admin.network.dns.domains.index', $account) }}" class="btn btn-outline-secondary btn-sm">
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

    {{-- Sync result banner --}}
    <div class="alert alert-info alert-dismissible fade show" x-show="syncResult" x-cloak>
        <i class="bi bi-arrow-repeat me-2"></i>
        Sync complete — <span x-text="syncResult"></span>
        <button type="button" class="btn-close" @click="syncResult = ''"></button>
    </div>

    {{-- Filter tabs --}}
    <div class="d-flex flex-wrap gap-1 mb-3">
        <button class="btn btn-sm" :class="filter === 'all' ? 'btn-dark' : 'btn-outline-secondary'" @click="filter='all'">All <span class="badge bg-light text-dark ms-1" x-text="subdomains.length"></span></button>
        <button class="btn btn-sm" :class="filter === 'ssl' ? 'btn-dark' : 'btn-outline-secondary'" @click="filter='ssl'">With SSL</button>
        <button class="btn btn-sm" :class="filter === 'no_ssl' ? 'btn-dark' : 'btn-outline-secondary'" @click="filter='no_ssl'">Without SSL</button>
        <button class="btn btn-sm" :class="filter === 'expiring' ? 'btn-dark' : 'btn-outline-secondary'" @click="filter='expiring'">⚠ Expiring</button>
        <button class="btn btn-sm" :class="filter === 'expired' ? 'btn-dark' : 'btn-outline-secondary'" @click="filter='expired'">✗ Expired</button>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>FQDN</th>
                        <th>IP Address</th>
                        <th>NOC IP</th>
                        <th>TTL</th>
                        <th>SSL Status</th>
                        <th>Expires</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="sub in filteredSubdomains()" :key="sub.fqdn">
                        <tr>
                            <td class="fw-semibold" x-text="sub.fqdn"></td>
                            <td><code x-text="sub.ip_address"></code></td>
                            <td>
                                <span class="badge" :class="sub.is_noc_ip ? 'bg-success' : 'bg-secondary'" x-text="sub.is_noc_ip ? '✓ NOC' : '✗ Other'"></span>
                            </td>
                            <td><small class="text-muted" x-text="formatTTL(sub.ttl)"></small></td>
                            <td x-html="sslBadge(sub.ssl)"></td>
                            <td>
                                <template x-if="sub.ssl && sub.ssl.expires_at">
                                    <span :class="sub.ssl.expiry_status === 'expiring_soon' ? 'text-warning' : sub.ssl.expiry_status === 'expired' ? 'text-danger' : ''"
                                          x-text="sub.ssl.expires_at"></span>
                                </template>
                                <template x-if="!sub.ssl || !sub.ssl.expires_at"><span class="text-muted">—</span></template>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <template x-if="sub.ssl && sub.ssl.status === 'valid'">
                                        <button class="btn btn-outline-success" @click="renewSsl(sub)" title="Renew SSL">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                    </template>
                                    <template x-if="!sub.ssl || sub.ssl.status === 'failed'">
                                        <button class="btn btn-outline-primary" @click="issueSsl(sub)" title="Issue SSL">
                                            <i class="bi bi-shield-plus"></i>
                                        </button>
                                    </template>
                                    <template x-if="sub.ssl && sub.ssl.status === 'valid'">
                                        <button class="btn btn-outline-secondary" @click="openExport(sub)" title="Export">
                                            <i class="bi bi-download"></i>
                                        </button>
                                    </template>
                                    @can('manage-dns')
                                    <button class="btn btn-outline-danger" @click="confirmDelete(sub)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    </template>
                    <template x-if="filteredSubdomains().length === 0">
                        <tr><td colspan="7" class="text-center text-muted py-4">No subdomains found.</td></tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Add Subdomain Modal --}}
    <div class="modal fade" id="addSubdomainModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-diagram-2 me-2"></i>Add Subdomain to {{ $domain }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger small" x-show="formError" x-text="formError"></div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Subdomain Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" x-model="form.subdomain" placeholder="noc" @input="form.subdomain = form.subdomain.toLowerCase()">
                                <span class="input-group-text text-muted">.{{ $domain }}</span>
                            </div>
                            <div class="form-text" x-show="form.subdomain">
                                Preview: <strong x-text="form.subdomain + '.{{ $domain }}'"></strong>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">IP Address <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" x-model="form.ip_address" placeholder="10.0.0.5">
                                @if(env('NOC_SERVER_IP'))
                                <button class="btn btn-outline-secondary" type="button" @click="form.ip_address = '{{ env('NOC_SERVER_IP') }}'">
                                    Use NOC IP
                                </button>
                                @endif
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">TTL</label>
                            <select class="form-select" x-model="form.ttl">
                                <option value="600">10 minutes</option>
                                <option value="1800">30 minutes</option>
                                <option value="3600">1 hour</option>
                                <option value="14400">4 hours</option>
                                <option value="86400">1 day</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <hr class="my-1">
                            <h6 class="text-muted small">SSL Certificate</h6>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" x-model="form.issue_ssl">
                                <label class="form-check-label">
                                    Auto-generate SSL via Let's Encrypt (DNS-01)
                                    <div class="text-muted small">Certificate will be issued after the A record propagates (~60s).</div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" @click="createSubdomain()" :disabled="saving">
                        <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                        Create Subdomain
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Export Modal --}}
    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-download me-2"></i>Export Certificate</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">
                        <strong x-text="exportTarget.fqdn"></strong> &mdash;
                        expires <span x-text="exportTarget.ssl?.expires_at"></span>
                    </p>
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-secondary text-start" @click="downloadExport('pem')">
                            <i class="bi bi-file-text me-2"></i><strong>PEM</strong> — Fullchain certificate
                        </button>
                        <button class="btn btn-outline-secondary text-start" @click="downloadExport('cer')">
                            <i class="bi bi-file-earmark me-2"></i><strong>CER</strong> — Certificate (DER/Base64)
                        </button>
                        <button class="btn btn-outline-secondary text-start" @click="downloadExport('key')">
                            <i class="bi bi-key me-2"></i><strong>KEY</strong> — Private key
                            <span class="badge bg-warning text-dark ms-1">Sensitive</span>
                        </button>
                        <div class="input-group">
                            <button class="btn btn-outline-secondary text-start flex-grow-1" @click="downloadExport('p12')">
                                <i class="bi bi-archive me-2"></i><strong>P12</strong> — PKCS#12 bundle
                            </button>
                            <input type="password" class="form-control" x-model="p12Password" placeholder="Password (optional)" style="max-width:180px">
                        </div>
                        <button class="btn btn-outline-primary text-start" @click="downloadExport('bundle')">
                            <i class="bi bi-file-zip me-2"></i><strong>ZIP</strong> — All formats bundled
                        </button>
                    </div>
                    <p class="text-muted small mt-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Private key exports are audit-logged.</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Delete Confirm Modal --}}
    <div class="modal fade" id="deleteSubdomainModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Delete Subdomain</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Delete <strong x-text="deleteTarget.fqdn"></strong>?</p>
                    <p class="text-muted small mb-0">This removes the A record from GoDaddy and revokes any SSL certificate.</p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-danger" @click="doDelete()" :disabled="saving">
                        <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                        Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function subdomainManager() {
    return {
        subdomains: @json($subdomains->toArray()),
        filter: 'all',
        saving: false,
        syncing: false,
        syncResult: '',
        formError: '',
        exportTarget: { fqdn: '', ssl: null },
        deleteTarget: { fqdn: '', subdomain: '' },
        p12Password: '',
        toast: { message: '', type: 'success' },
        form: { subdomain: '', ip_address: '{{ $nocIp }}', ttl: '3600', issue_ssl: false },

        filteredSubdomains() {
            return this.subdomains.filter(s => {
                if (this.filter === 'ssl')      return s.ssl !== null;
                if (this.filter === 'no_ssl')   return !s.ssl;
                if (this.filter === 'expiring') return s.ssl?.expiry_status === 'expiring_soon';
                if (this.filter === 'expired')  return s.ssl?.expiry_status === 'expired' || s.ssl?.status === 'expired';
                return true;
            });
        },

        sslBadge(ssl) {
            if (!ssl) return '<span class="badge bg-secondary">— Missing</span>';
            const map = {
                valid:         '<span class="badge bg-success">● Valid</span>',
                expiring_soon: '<span class="badge bg-warning text-dark">⚠ Expiring</span>',
                expired:       '<span class="badge bg-danger">✗ Expired</span>',
                pending:       '<span class="badge bg-primary">⟳ Pending</span>',
                failed:        '<span class="badge bg-danger">✗ Failed</span>',
                revoked:       '<span class="badge bg-secondary">⊘ Revoked</span>',
            };
            return map[ssl.status] || map.failed;
        },

        formatTTL(ttl) {
            return {600:'10m',1800:'30m',3600:'1h',14400:'4h',86400:'1d',604800:'1w'}[ttl] || ttl+'s';
        },

        openAddModal() {
            this.formError = '';
            this.form = { subdomain: '', ip_address: '{{ $nocIp }}', ttl: '3600', issue_ssl: false };
            new bootstrap.Modal(document.getElementById('addSubdomainModal')).show();
        },

        async createSubdomain() {
            this.saving = true;
            this.formError = '';
            try {
                const res = await fetch("{{ route('admin.network.dns.subdomains.store', [$account, $domain]) }}", {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        subdomain: this.form.subdomain,
                        ip_address: this.form.ip_address,
                        ttl: parseInt(this.form.ttl),
                        issue_ssl: this.form.issue_ssl,
                    })
                });
                const data = await res.json();
                if (!res.ok || !data.success) { this.formError = data.message || 'Failed.'; return; }
                bootstrap.Modal.getInstance(document.getElementById('addSubdomainModal'))?.hide();
                this.showToast(data.message, 'success');
                location.reload();
            } catch(e) { this.formError = 'Network error.'; }
            finally { this.saving = false; }
        },

        async syncFromGoDaddy() {
            this.syncing = true;
            try {
                const res = await fetch("{{ route('admin.network.dns.subdomains.sync', [$account, $domain]) }}", {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
                const data = await res.json();
                this.syncResult = data.message;
                if (data.success) location.reload();
            } catch(e) { this.showToast('Sync failed.', 'error'); }
            finally { this.syncing = false; }
        },

        async issueSsl(sub) {
            if (!confirm(`Issue SSL certificate for ${sub.fqdn}?`)) return;
            try {
                const res = await fetch("{{ route('admin.network.dns.certificates.store', [$account, $domain]) }}", {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ fqdn: sub.fqdn, auto_renew: true })
                });
                const data = await res.json();
                this.showToast(data.success ? 'SSL issuance queued.' : (data.message || 'Failed.'), data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 2000);
            } catch(e) { this.showToast('Network error.', 'error'); }
        },

        async renewSsl(sub) {
            if (!sub.ssl || !confirm(`Renew SSL certificate for ${sub.fqdn}?`)) return;
            try {
                const res = await fetch(`{{ url('admin/network/dns') }}/${{{ $account->id }}}/domains/{{ $domain }}/certificates/${sub.ssl.id}/renew`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
                const data = await res.json();
                this.showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 2000);
            } catch(e) { this.showToast('Network error.', 'error'); }
        },

        openExport(sub) {
            this.exportTarget = sub;
            this.p12Password = '';
            new bootstrap.Modal(document.getElementById('exportModal')).show();
        },

        async downloadExport(format) {
            if (!this.exportTarget.ssl) return;
            const params = new URLSearchParams({ format });
            if (format === 'p12' && this.p12Password) params.append('password', this.p12Password);

            const url = `{{ url('admin/network/dns') }}/{{ $account->id }}/domains/{{ $domain }}/certificates/${this.exportTarget.ssl.id}/export?${params}`;
            const a = document.createElement('a');
            a.href = url;
            a.download = '';
            a.click();
        },

        confirmDelete(sub) {
            this.deleteTarget = { fqdn: sub.fqdn, subdomain: sub.subdomain };
            new bootstrap.Modal(document.getElementById('deleteSubdomainModal')).show();
        },

        async doDelete() {
            this.saving = true;
            try {
                const res = await fetch(`{{ url('admin/network/dns') }}/{{ $account->id }}/domains/{{ $domain }}/subdomains/${this.deleteTarget.subdomain}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                });
                const data = await res.json();
                bootstrap.Modal.getInstance(document.getElementById('deleteSubdomainModal'))?.hide();
                this.showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) location.reload();
            } catch(e) { this.showToast('Network error.', 'error'); }
            finally { this.saving = false; }
        },

        showToast(message, type) {
            this.toast = { message, type };
            const el = this.$refs.toast;
            if (el) new bootstrap.Toast(el, { delay: type === 'error' ? 8000 : 4000 }).show();
        }
    };
}
</script>
@endpush
@endsection
