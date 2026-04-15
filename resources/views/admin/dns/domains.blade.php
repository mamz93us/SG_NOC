@extends('layouts.admin')
@section('title', 'Domains — ' . $account->label)

@section('content')
<div class="container-fluid py-4" x-data="domainsList()">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-globe me-2"></i>Domains</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.index') }}">DNS Accounts</a></li>
                    <li class="breadcrumb-item active">{{ $account->label }}</li>
                </ol>
            </nav>
        </div>
        <a href="{{ route('admin.network.dns.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    {{-- Filters --}}
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" placeholder="Search domains..." x-model="search">
            </div>
        </div>
        <div class="col-md-3">
            <select class="form-select form-select-sm" x-model="statusFilter">
                <option value="">All Statuses</option>
                <option value="ACTIVE">Active</option>
                <option value="EXPIRED">Expired</option>
                <option value="CANCELLED">Cancelled</option>
                <option value="PENDING">Pending</option>
            </select>
        </div>
        <div class="col-md-5 text-end">
            <span class="text-muted small" x-text="filteredDomains().length + ' domain(s)'"></span>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="cursor:pointer" @click="sortBy('domain')">Domain <i class="bi bi-arrow-down-up small text-muted"></i></th>
                        <th>Status</th>
                        <th style="cursor:pointer" @click="sortBy('expires')">Expires <i class="bi bi-arrow-down-up small text-muted"></i></th>
                        <th>Auto-Renew</th>
                        <th>Privacy</th>
                        <th>Nameservers</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="d in filteredDomains()" :key="d.domain">
                        <tr :class="isExpiringSoon(d.expires) ? 'table-warning' : ''">
                            <td class="fw-semibold" x-text="d.domain"></td>
                            <td>
                                <span class="badge" :class="statusBadge(d.status)" x-text="d.status"></span>
                            </td>
                            <td>
                                <span x-text="formatDate(d.expires)"></span>
                                <template x-if="isExpiringSoon(d.expires)">
                                    <i class="bi bi-exclamation-triangle text-warning ms-1" title="Expiring soon"></i>
                                </template>
                            </td>
                            <td>
                                <i :class="d.renewAuto ? 'bi bi-check-circle text-success' : 'bi bi-x-circle text-muted'"></i>
                            </td>
                            <td>
                                <i :class="d.privacy ? 'bi bi-shield-check text-success' : 'bi bi-shield text-muted'"></i>
                            </td>
                            <td>
                                <small class="text-muted" x-text="(d.nameServers || []).slice(0, 2).join(', ') + ((d.nameServers || []).length > 2 ? '...' : '')"></small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a :href="baseUrl + '/domains/' + d.domain + '/records'" class="btn btn-outline-primary" title="DNS Records">
                                        <i class="bi bi-list-ul"></i>
                                    </a>
                                    <a :href="baseUrl + '/domains/' + d.domain + '/nameservers'" class="btn btn-outline-info" title="Nameservers">
                                        <i class="bi bi-server"></i>
                                    </a>
                                    <a :href="baseUrl + '/domains/' + d.domain" class="btn btn-outline-secondary" title="Settings">
                                        <i class="bi bi-gear"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <template x-if="filteredDomains().length === 0">
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <template x-if="allDomains.length === 0">
                                    <span><i class="bi bi-globe display-4 d-block mb-2"></i>No domains found in this account.</span>
                                </template>
                                <template x-if="allDomains.length > 0">
                                    <span>No domains match your filter.</span>
                                </template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
function domainsList() {
    return {
        baseUrl: '{{ url("admin/network/dns/{$account->id}") }}',
        allDomains: @json($domains),
        search: '',
        statusFilter: '',
        sortField: 'domain',
        sortAsc: true,

        sortBy(field) {
            if (this.sortField === field) { this.sortAsc = !this.sortAsc; }
            else { this.sortField = field; this.sortAsc = true; }
        },

        filteredDomains() {
            let list = this.allDomains;
            if (this.search) {
                const q = this.search.toLowerCase();
                list = list.filter(d => d.domain.toLowerCase().includes(q));
            }
            if (this.statusFilter) {
                list = list.filter(d => d.status === this.statusFilter);
            }
            list.sort((a, b) => {
                let va = a[this.sortField] || '', vb = b[this.sortField] || '';
                if (typeof va === 'string') va = va.toLowerCase();
                if (typeof vb === 'string') vb = vb.toLowerCase();
                if (va < vb) return this.sortAsc ? -1 : 1;
                if (va > vb) return this.sortAsc ? 1 : -1;
                return 0;
            });
            return list;
        },

        statusBadge(status) {
            return {
                'ACTIVE': 'bg-success',
                'PENDING': 'bg-warning text-dark',
                'EXPIRED': 'bg-danger',
                'CANCELLED': 'bg-secondary',
            }[status] || 'bg-secondary';
        },

        formatDate(dateStr) {
            if (!dateStr) return '-';
            return new Date(dateStr).toLocaleDateString();
        },

        isExpiringSoon(dateStr) {
            if (!dateStr) return false;
            const diff = new Date(dateStr) - new Date();
            return diff > 0 && diff < 30 * 24 * 60 * 60 * 1000;
        }
    };
}
</script>
@endpush
@endsection
