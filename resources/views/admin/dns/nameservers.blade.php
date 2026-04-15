@extends('layouts.admin')
@section('title', 'Nameservers — ' . $domain)

@section('content')
<div class="container-fluid py-4" x-data="nsManager()">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-server me-2"></i>Nameservers</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.index') }}">DNS Accounts</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.domains.index', $account) }}">{{ $account->label }}</a></li>
                    <li class="breadcrumb-item active">{{ $domain }} &mdash; Nameservers</li>
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

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Current Nameservers for <strong>{{ $domain }}</strong></h6>
            @can('manage-dns')
            <button class="btn btn-outline-secondary btn-sm" @click="resetToDefault()" type="button">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to GoDaddy Default
            </button>
            @endcan
        </div>
        <div class="card-body">
            <template x-for="(ns, idx) in nameservers" :key="idx">
                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text" x-text="'NS ' + (idx + 1)"></span>
                    <input type="text" class="form-control" x-model="nameservers[idx]" placeholder="ns1.example.com" @can('manage-dns') @else disabled @endcan>
                    @can('manage-dns')
                    <button class="btn btn-outline-danger" type="button" @click="removeNs(idx)" :disabled="nameservers.length <= 1">
                        <i class="bi bi-x"></i>
                    </button>
                    @endcan
                </div>
            </template>

            @can('manage-dns')
            <button class="btn btn-outline-primary btn-sm mt-2" @click="addNs()" type="button">
                <i class="bi bi-plus-lg me-1"></i>Add Nameserver
            </button>

            <hr>
            <div class="d-flex gap-2 align-items-center">
                <button class="btn btn-primary btn-sm" @click="save()" :disabled="saving" type="button">
                    <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                    <i class="bi bi-check-lg" x-show="!saving"></i> Save Nameservers
                </button>
                <span x-show="error" class="text-danger small" x-text="error"></span>
            </div>
            @endcan
        </div>
    </div>
</div>

@push('scripts')
<script>
function nsManager() {
    return {
        nameservers: @json($nameservers),
        saving: false,
        error: '',
        toast: { message: '', type: 'success' },

        addNs() {
            this.nameservers.push('');
        },

        removeNs(idx) {
            this.nameservers.splice(idx, 1);
        },

        resetToDefault() {
            this.nameservers = ['ns73.domaincontrol.com', 'ns74.domaincontrol.com'];
        },

        async save() {
            this.saving = true;
            this.error = '';
            const filtered = this.nameservers.filter(ns => ns.trim() !== '');
            if (filtered.length === 0) {
                this.error = 'At least one nameserver is required.';
                this.saving = false;
                return;
            }

            try {
                const res = await fetch("{{ route('admin.network.dns.nameservers.update', [$account, $domain]) }}", {
                    method: 'PUT',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ nameservers: filtered })
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    this.error = data.message || 'Failed to update nameservers.';
                    return;
                }
                this.nameservers = filtered;
                this.showToast('Nameservers updated successfully.', 'success');
            } catch (e) {
                this.error = 'Network error. Please try again.';
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
