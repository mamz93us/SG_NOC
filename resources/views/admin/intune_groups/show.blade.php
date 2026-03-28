@extends('layouts.admin')
@section('content')

{{-- Header --}}
<div class="d-flex justify-content-between align-items-start mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-collection me-2 text-primary"></i>{{ $group->name }}</h4>
        <div class="d-flex flex-wrap gap-2 mt-1">
            <span class="badge {{ $group->groupTypeBadgeClass() }}">{{ ucfirst($group->group_type) }}</span>
            <span class="badge {{ $group->syncStatusBadgeClass() }}">{{ ucfirst($group->sync_status) }}</span>
            @if($group->branch)<span class="badge bg-light text-dark border"><i class="bi bi-building me-1"></i>{{ $group->branch->name }}</span>@endif
            @if($group->department)<span class="badge bg-light text-dark border"><i class="bi bi-diagram-3 me-1"></i>{{ $group->department->name }}</span>@endif
        </div>
        @if($group->azure_group_id)
        <div class="mt-1 small text-muted font-monospace">Azure ID: {{ $group->azure_group_id }}</div>
        @endif
        @if($group->description)<p class="mb-0 mt-1 text-muted small">{{ $group->description }}</p>@endif
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.intune-groups.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
        <form action="{{ route('admin.intune-groups.destroy', $group) }}" method="POST"
              onsubmit="return confirm('Delete this group? The Azure AD group will also be deleted.');">
            @csrf @method('DELETE')
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete Group</button>
        </form>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show py-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- Tabs --}}
<ul class="nav nav-tabs mb-3" id="groupTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-members">
            <i class="bi bi-people me-1"></i>Members
            <span class="badge bg-secondary ms-1">{{ $group->members->where('status','added')->count() }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-policies">
            <i class="bi bi-file-earmark-code me-1"></i>Policies
            <span class="badge bg-secondary ms-1">{{ $group->policies->count() }}</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    {{-- ── Members Tab ─────────────────────────────────────────────────── --}}
    <div class="tab-pane fade show active" id="tab-members" x-data="memberSearch('{{ route('admin.intune-groups.users.search') }}')">

        {{-- Add Member --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2 fw-semibold small"><i class="bi bi-person-plus me-1"></i>Add Member</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.intune-groups.members.add', $group) }}" @submit.prevent="submitAdd">
                    @csrf
                    <input type="hidden" name="azure_user_id"  x-model="selected.id">
                    <input type="hidden" name="user_upn"       x-model="selected.userPrincipalName">
                    <input type="hidden" name="display_name"   x-model="selected.displayName">

                    <div class="d-flex gap-2 align-items-start">
                        <div class="flex-grow-1 position-relative">
                            <input type="text" class="form-control form-control-sm"
                                placeholder="Search by name or email…"
                                x-model="query"
                                @input.debounce.300ms="search()"
                                @keydown.arrow-down.prevent="highlight(1)"
                                @keydown.arrow-up.prevent="highlight(-1)"
                                @keydown.enter.prevent="selectHighlighted()"
                                autocomplete="off">
                            <ul class="dropdown-menu w-100 shadow-sm show small" style="max-height:200px;overflow-y:auto;"
                                x-show="results.length > 0 && !selected.id" x-cloak>
                                <template x-for="(user, i) in results" :key="user.id">
                                    <li>
                                        <button type="button" class="dropdown-item py-1"
                                            :class="{'active': i === highlightedIndex}"
                                            @click="pick(user)">
                                            <span x-text="user.displayName" class="fw-semibold"></span>
                                            <span class="text-muted ms-1 small" x-text="user.userPrincipalName"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                            <div x-show="selected.id" x-cloak class="mt-1 small text-success">
                                <i class="bi bi-person-check me-1"></i>
                                <span x-text="selected.displayName"></span>
                                (<span x-text="selected.userPrincipalName"></span>)
                                <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-muted" @click="clear()">
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary" :disabled="!selected.id">
                            <i class="bi bi-plus-lg me-1"></i>Add
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Members List --}}
        <div class="card shadow-sm">
            <div class="card-body p-0">
                @php $activeMembers = $group->members->where('status','added'); @endphp
                @if($activeMembers->isEmpty())
                <div class="text-center py-4 text-muted small"><i class="bi bi-people d-block display-6 mb-2"></i>No active members. Add users above.</div>
                @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Display Name</th>
                                <th>UPN / Email</th>
                                <th>Status</th>
                                <th>Added</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($activeMembers as $m)
                            <tr>
                                <td class="fw-semibold">{{ $m->display_name }}</td>
                                <td class="text-muted">{{ $m->user_upn }}</td>
                                <td><span class="badge {{ $m->statusBadgeClass() }}">{{ ucfirst($m->status) }}</span></td>
                                <td class="text-muted">{{ $m->created_at?->diffForHumans() ?? '—' }}</td>
                                <td>
                                    <form action="{{ route('admin.intune-groups.members.remove', [$group, $m->azure_user_id]) }}"
                                          method="POST" class="d-inline"
                                          onsubmit="return confirm('Remove {{ addslashes($m->display_name) }} from this group?');">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-person-dash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Policies Tab ─────────────────────────────────────────────────── --}}
    <div class="tab-pane fade" id="tab-policies">

        {{-- Deploy Printer --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header py-2 fw-semibold small d-flex justify-content-between align-items-center">
                <span><i class="bi bi-printer me-1"></i>Deploy Printer Script</span>
                {{-- Sync button --}}
                <form action="{{ route('admin.intune-groups.policies.sync', $group) }}" method="POST" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary" title="Pull live assignment status from Intune">
                        <i class="bi bi-arrow-repeat me-1"></i>Sync from Intune
                    </button>
                </form>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.intune-groups.deploy-printer', $group) }}" class="d-flex gap-2 align-items-end flex-wrap">
                    @csrf
                    <div>
                        <label class="form-label small fw-semibold mb-1">Printer</label>
                        <select name="printer_id" class="form-select form-select-sm" required style="min-width:240px;">
                            <option value="">Select printer…</option>
                            @foreach($printers as $p)
                            <option value="{{ $p->id }}">{{ $p->printer_name }} ({{ $p->ip_address }})</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-cloud-upload me-1"></i>Upload & Assign Script
                    </button>
                </form>
                <div class="form-text mt-2">Generates a PowerShell script for this printer and assigns it to the Azure group in Intune.</div>
            </div>
        </div>

        {{-- Policies List --}}
        <div class="card shadow-sm">
            <div class="card-body p-0">
                @if($group->policies->isEmpty())
                <div class="text-center py-4 text-muted small"><i class="bi bi-file-earmark-code d-block display-6 mb-2"></i>No policies deployed yet.</div>
                @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Policy Name</th>
                                <th>Type</th>
                                <th>Intune Script ID</th>
                                <th>Status</th>
                                <th>Deployed</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($group->policies as $p)
                            <tr>
                                <td class="fw-semibold">{{ $p->policy_name }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $p->policy_type }}</span></td>
                                <td class="font-monospace text-muted small" title="{{ $p->intune_policy_id }}">
                                    {{ $p->intune_policy_id ? Str::limit($p->intune_policy_id, 18) : '—' }}
                                </td>
                                <td><span class="badge {{ $p->statusBadgeClass() }}">{{ ucfirst($p->status) }}</span></td>
                                <td class="text-muted">{{ $p->created_at?->diffForHumans() ?? '—' }}</td>
                                <td>
                                    <form action="{{ route('admin.intune-groups.policies.remove', [$group, $p]) }}"
                                          method="POST" class="d-inline"
                                          onsubmit="return confirm('Unassign &quot;{{ addslashes($p->policy_name) }}&quot; from Intune and remove this record?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" title="Unassign & Remove">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>

</div>

@push('scripts')
<script>
function memberSearch(searchUrl) {
    return {
        query: '',
        results: [],
        selected: {},
        highlightedIndex: -1,

        async search() {
            if (this.query.length < 2) { this.results = []; return; }
            try {
                const r = await fetch(searchUrl + '?q=' + encodeURIComponent(this.query));
                this.results = await r.json();
                this.highlightedIndex = -1;
            } catch (e) { this.results = []; }
        },

        pick(user) {
            this.selected = user;
            this.results  = [];
            this.query    = user.displayName;
        },

        clear() {
            this.selected = {};
            this.query    = '';
            this.results  = [];
        },

        highlight(dir) {
            const max = this.results.length - 1;
            this.highlightedIndex = Math.min(Math.max(this.highlightedIndex + dir, 0), max);
        },

        selectHighlighted() {
            if (this.highlightedIndex >= 0 && this.results[this.highlightedIndex]) {
                this.pick(this.results[this.highlightedIndex]);
            }
        },

        submitAdd() {
            if (!this.selected.id) return;
            this.$el.submit();
        },
    };
}
</script>
@endpush

@endsection
