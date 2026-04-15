@extends('layouts.admin')
@section('title', 'DNS Records — ' . $domain)

@section('content')
<div class="container-fluid py-4" x-data="dnsRecords()">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-list-ul me-2"></i>DNS Records</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.index') }}">DNS Accounts</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.network.dns.domains.index', $account) }}">{{ $account->label }}</a></li>
                    <li class="breadcrumb-item active">{{ $domain }}</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            @can('manage-dns')
            <button class="btn btn-primary btn-sm" @click="openAddModal()">
                <i class="bi bi-plus-lg"></i> Add Record
            </button>
            @endcan
            <a href="{{ route('admin.network.dns.domains.index', $account) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    {{-- Toast --}}
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
        <div class="toast align-items-center border-0" :class="toast.type === 'success' ? 'text-bg-success' : 'text-bg-danger'" x-ref="toast" role="alert">
            <div class="d-flex">
                <div class="toast-body" x-text="toast.message"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    {{-- Type filter tabs --}}
    <div class="d-flex flex-wrap gap-1 mb-3">
        <button class="btn btn-sm" :class="typeFilter === '' ? 'btn-dark' : 'btn-outline-secondary'" @click="typeFilter = ''">
            All <span class="badge bg-light text-dark ms-1" x-text="allRecords.length"></span>
        </button>
        <template x-for="t in availableTypes" :key="t">
            <button class="btn btn-sm" :class="typeFilter === t ? 'btn-dark' : 'btn-outline-secondary'" @click="typeFilter = t">
                <span x-text="t"></span>
                <span class="badge bg-light text-dark ms-1" x-text="countByType(t)"></span>
            </button>
        </template>
    </div>

    {{-- Search --}}
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" placeholder="Search by name or value..." x-model="search">
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Value</th>
                        <th>TTL</th>
                        <th>Priority</th>
                        @can('manage-dns')
                        <th>Actions</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(rec, idx) in filteredRecords()" :key="idx">
                        <tr>
                            <td><span class="badge" :class="typeBadge(rec.type)" x-text="rec.type"></span></td>
                            <td class="fw-semibold" x-text="rec.name"></td>
                            <td style="max-width:300px;word-break:break-all">
                                <small x-text="rec.data.length > 80 ? rec.data.substring(0, 80) + '...' : rec.data" :title="rec.data"></small>
                            </td>
                            <td><small class="text-muted" x-text="formatTTL(rec.ttl)"></small></td>
                            <td x-text="rec.priority !== undefined && rec.priority !== 0 ? rec.priority : '-'"></td>
                            @can('manage-dns')
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-secondary" @click="openEditModal(rec)" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" @click="confirmDelete(rec)" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                            @endcan
                        </tr>
                    </template>
                    <template x-if="filteredRecords().length === 0">
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                No records found.
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Add/Edit Record Modal --}}
    <div class="modal fade" id="recordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" x-text="editing ? 'Edit Record' : 'Add Record'"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger small" x-show="formError" x-text="formError"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Type <span class="text-danger">*</span></label>
                            <select class="form-select" x-model="form.type" :disabled="editing">
                                <template x-for="t in recordTypes" :key="t">
                                    <option :value="t" x-text="t"></option>
                                </template>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" x-model="form.name" placeholder="@ for root" :disabled="editing">
                            <div class="form-text">Use @ for the root domain</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Value / Data <span class="text-danger">*</span></label>
                            <template x-if="form.type === 'TXT'">
                                <textarea class="form-control" x-model="form.data" rows="3" required></textarea>
                            </template>
                            <template x-if="form.type !== 'TXT'">
                                <input type="text" class="form-control" x-model="form.data" required>
                            </template>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">TTL <span class="text-danger">*</span></label>
                            <select class="form-select" x-model="form.ttl">
                                <option value="600">10 minutes</option>
                                <option value="1800">30 minutes</option>
                                <option value="3600">1 hour</option>
                                <option value="14400">4 hours</option>
                                <option value="86400">1 day</option>
                                <option value="604800">1 week</option>
                            </select>
                        </div>
                        <div class="col-md-6" x-show="['MX','SRV'].includes(form.type)">
                            <label class="form-label">Priority</label>
                            <input type="number" class="form-control" x-model="form.priority" min="0" max="65535">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" @click="saveRecord()" :disabled="saving">
                        <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                        <span x-text="editing ? 'Update' : 'Add Record'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Delete Confirmation Modal --}}
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Delete Record</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Delete <strong x-text="deleteTarget.type"></strong> record <strong x-text="deleteTarget.name"></strong>?</p>
                    <p class="text-muted small mb-0">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" @click="doDelete()" :disabled="saving">
                        <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                        Yes, Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function dnsRecords() {
    return {
        allRecords: @json($records),
        typeFilter: '',
        search: '',
        editing: false,
        saving: false,
        formError: '',
        toast: { message: '', type: 'success' },
        deleteTarget: { type: '', name: '' },
        recordTypes: ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'],
        form: { type: 'A', name: '', data: '', ttl: '3600', priority: 0 },

        get availableTypes() {
            return [...new Set(this.allRecords.map(r => r.type))].sort();
        },

        countByType(type) {
            return this.allRecords.filter(r => r.type === type).length;
        },

        filteredRecords() {
            let list = this.allRecords;
            if (this.typeFilter) list = list.filter(r => r.type === this.typeFilter);
            if (this.search) {
                const q = this.search.toLowerCase();
                list = list.filter(r => r.name.toLowerCase().includes(q) || r.data.toLowerCase().includes(q));
            }
            return list;
        },

        typeBadge(type) {
            return {
                A: 'bg-primary', AAAA: 'bg-purple', CNAME: 'bg-teal',
                MX: 'bg-orange', TXT: 'bg-secondary', NS: 'bg-indigo',
                SRV: 'bg-warning text-dark', CAA: 'bg-danger', PTR: 'bg-success', SOA: 'bg-dark'
            }[type] || 'bg-secondary';
        },

        formatTTL(ttl) {
            const map = {600:'10m',1800:'30m',3600:'1h',14400:'4h',86400:'1d',604800:'1w'};
            return map[ttl] || ttl + 's';
        },

        openAddModal() {
            this.editing = false;
            this.formError = '';
            this.form = { type: 'A', name: '', data: '', ttl: '3600', priority: 0 };
            new bootstrap.Modal(document.getElementById('recordModal')).show();
        },

        openEditModal(rec) {
            this.editing = true;
            this.formError = '';
            this.form = { type: rec.type, name: rec.name, data: rec.data, ttl: String(rec.ttl), priority: rec.priority || 0 };
            new bootstrap.Modal(document.getElementById('recordModal')).show();
        },

        async saveRecord() {
            this.saving = true;
            this.formError = '';
            const url = this.editing
                ? "{{ route('admin.network.dns.records.update', [$account, $domain]) }}"
                : "{{ route('admin.network.dns.records.store', [$account, $domain]) }}";
            const method = this.editing ? 'PUT' : 'POST';

            try {
                const res = await fetch(url, {
                    method,
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        type: this.form.type,
                        name: this.form.name,
                        data: this.form.data,
                        ttl: parseInt(this.form.ttl),
                        priority: parseInt(this.form.priority) || 0,
                    })
                });
                const data = await res.json();
                if (!res.ok || !data.success) {
                    this.formError = data.message || 'An error occurred.';
                    return;
                }
                bootstrap.Modal.getInstance(document.getElementById('recordModal'))?.hide();
                this.showToast(data.message, 'success');
                this.reloadRecords();
            } catch (e) {
                this.formError = 'Network error. Please try again.';
            } finally {
                this.saving = false;
            }
        },

        confirmDelete(rec) {
            this.deleteTarget = { type: rec.type, name: rec.name };
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        },

        async doDelete() {
            this.saving = true;
            try {
                const res = await fetch("{{ route('admin.network.dns.records.destroy', [$account, $domain]) }}", {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type: this.deleteTarget.type, name: this.deleteTarget.name })
                });
                const data = await res.json();
                bootstrap.Modal.getInstance(document.getElementById('deleteModal'))?.hide();
                if (!res.ok || !data.success) {
                    this.showToast(data.message || 'Delete failed.', 'error');
                    return;
                }
                this.showToast(data.message, 'success');
                this.reloadRecords();
            } catch (e) {
                this.showToast('Network error.', 'error');
            } finally {
                this.saving = false;
            }
        },

        async reloadRecords() {
            try {
                const res = await fetch("{{ route('admin.network.dns.records.index', [$account, $domain]) }}", {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                // Page returns HTML, so just reload
                location.reload();
            } catch(e) { location.reload(); }
        },

        showToast(message, type) {
            this.toast = { message, type };
            const el = this.$refs.toast;
            if (el) new bootstrap.Toast(el, { delay: type === 'error' ? 8000 : 4000 }).show();
        }
    };
}
</script>
<style>
.bg-purple { background-color: #6f42c1 !important; }
.bg-teal { background-color: #20c997 !important; }
.bg-orange { background-color: #fd7e14 !important; color: #fff; }
.bg-indigo { background-color: #6610f2 !important; }
</style>
@endpush
@endsection
