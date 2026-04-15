@extends('layouts.admin')
@section('title', 'Domain Settings — ' . $domain)

@section('content')
<div class="container-fluid py-4" x-data="domainSettings()">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-gear me-2"></i>Domain Settings</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.index') }}">DNS Accounts</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.domains.index', $account) }}">{{ $account->label }}</a></li>
                    <li class="breadcrumb-item active">{{ $domain }} &mdash; Settings</li>
                </ol>
            </nav>
        </div>
        <a href="{{ route('admin.network.dns.domains.index', $account) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back
        </a>
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

    <div class="row g-4">
        {{-- Domain Info --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent"><h6 class="mb-0">Domain Information</h6></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th class="text-muted" style="width:40%">Domain</th><td class="fw-semibold">{{ $domain }}</td></tr>
                        <tr>
                            <th class="text-muted">Status</th>
                            <td><span class="badge {{ ($domainInfo['status'] ?? '') === 'ACTIVE' ? 'bg-success' : 'bg-secondary' }}">{{ $domainInfo['status'] ?? 'Unknown' }}</span></td>
                        </tr>
                        <tr><th class="text-muted">Created</th><td>{{ isset($domainInfo['createdAt']) ? \Carbon\Carbon::parse($domainInfo['createdAt'])->format('M d, Y') : '-' }}</td></tr>
                        <tr><th class="text-muted">Expires</th><td>{{ isset($domainInfo['expires']) ? \Carbon\Carbon::parse($domainInfo['expires'])->format('M d, Y') : '-' }}</td></tr>
                        <tr><th class="text-muted">Registrar</th><td>{{ $domainInfo['registrarCreatedAt'] ?? 'GoDaddy' }}</td></tr>
                        <tr>
                            <th class="text-muted">Nameservers</th>
                            <td>
                                @foreach(($domainInfo['nameServers'] ?? []) as $ns)
                                    <div><code class="small">{{ $ns }}</code></div>
                                @endforeach
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- Settings Toggles --}}
        @can('manage-dns')
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent"><h6 class="mb-0">Domain Settings</h6></div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" x-model="settings.renewAuto" @change="save()">
                        <label class="form-check-label">
                            <strong>Auto-Renew</strong>
                            <div class="text-muted small">Automatically renew this domain before it expires</div>
                        </label>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" x-model="settings.privacy" @change="save()">
                        <label class="form-check-label">
                            <strong>Privacy Protection</strong>
                            <div class="text-muted small">Hide personal information from WHOIS lookups</div>
                        </label>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" x-model="settings.exposeWhois" @change="save()">
                        <label class="form-check-label">
                            <strong>Expose WHOIS</strong>
                            <div class="text-muted small">Allow public access to WHOIS data</div>
                        </label>
                    </div>

                    <div x-show="saving" class="text-muted small mt-2">
                        <span class="spinner-border spinner-border-sm me-1"></span> Saving...
                    </div>
                </div>
            </div>

            {{-- Quick Links --}}
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-transparent"><h6 class="mb-0">Quick Actions</h6></div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('admin.network.dns.records.index', [$account, $domain]) }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-list-ul me-1"></i>DNS Records
                        </a>
                        <a href="{{ route('admin.network.dns.nameservers.show', [$account, $domain]) }}" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-server me-1"></i>Nameservers
                        </a>
                    </div>
                </div>
            </div>
        </div>
        @endcan
    </div>
</div>

@push('scripts')
<script>
function domainSettings() {
    return {
        settings: {
            renewAuto: @json($domainInfo['renewAuto'] ?? false),
            privacy: @json($domainInfo['privacy'] ?? false),
            exposeWhois: @json($domainInfo['exposeWhois'] ?? false),
        },
        saving: false,
        toast: { message: '', type: 'success' },

        async save() {
            this.saving = true;
            try {
                const res = await fetch("{{ route('admin.network.dns.domains.update', [$account, $domain]) }}", {
                    method: 'PATCH',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify(this.settings)
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    this.showToast(data.message || 'Failed to update settings.', 'error');
                    return;
                }
                this.showToast('Settings saved.', 'success');
            } catch (e) {
                this.showToast('Network error.', 'error');
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
</script>
@endpush
@endsection
