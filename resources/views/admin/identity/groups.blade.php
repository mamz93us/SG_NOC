@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-collection-fill me-2 text-primary"></i>Groups</h4>
        <small class="text-muted">
            Microsoft Entra ID groups
            @if($lastSync)
            &mdash; last sync {{ $lastSync->created_at->diffForHumans() }}
            @endif
        </small>
    </div>
    @can('manage-identity')
    <form method="POST" action="{{ route('admin.identity.sync') }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-arrow-repeat me-1"></i>Sync Now
        </button>
    </form>
    @endcan
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- ── Search ──────────────────────────────────────────────────────────── --}}
<form method="GET" id="grpFilterForm" class="mb-3">
    <div class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search text-muted"></i>
                </span>
                <input type="text" name="search" id="grpSearch"
                       class="form-control form-control-lg border-start-0 ps-0"
                       placeholder="Search by name or description…"
                       value="{{ request('search') }}"
                       autocomplete="off">
                @if(request('search'))
                <a href="{{ route('admin.identity.groups') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
                @endif
            </div>
            <div class="form-text ps-1">Searching across all {{ $groups->total() }} groups</div>
        </div>
        <div class="col-auto">
            <a href="{{ route('admin.identity.groups') }}" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle me-1"></i>Clear
            </a>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($groups->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-collection display-4 d-block mb-2"></i>
            No groups found. Run a sync to import from Entra ID.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small" id="grpTable">
                <thead class="table-light">
                    <tr>
                        <th>Display Name</th>
                        <th>Type</th>
                        <th class="text-center">Members</th>
                        <th class="text-center">Mail</th>
                        <th>Description</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($groups as $g)
                    <tr data-name="{{ strtolower($g->display_name) }} {{ strtolower($g->description ?? '') }}">
                        <td class="fw-semibold">{{ $g->display_name }}</td>
                        <td><span class="badge {{ $g->typeBadgeClass() }}">{{ $g->typeLabel() }}</span></td>
                        <td class="text-center">
                            <button type="button"
                                    class="badge bg-{{ $g->members_count > 0 ? 'primary' : 'light text-muted border' }} border-0"
                                    style="cursor:{{ $g->members_count > 0 ? 'pointer' : 'default' }}"
                                    onclick="{{ $g->members_count > 0 ? 'loadGroupMembers(\''.addslashes($g->azure_id).'\',\''.addslashes($g->display_name).'\')' : '' }}">
                                {{ $g->members_count }}
                            </button>
                        </td>
                        <td class="text-center">
                            @if($g->mail_enabled)
                            <span class="badge bg-success"><i class="bi bi-envelope-check me-1"></i>Yes</span>
                            @else
                            <span class="badge bg-light text-muted border">No</span>
                            @endif
                        </td>
                        <td class="text-muted">
                            @if($g->description)
                            <span title="{{ $g->description }}">{{ Str::limit($g->description, 60) }}</span>
                            @else
                            —
                            @endif
                        </td>
                        <td>
                            @if($g->members_count > 0)
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick="loadGroupMembers('{{ $g->azure_id }}','{{ addslashes($g->display_name) }}')">
                                <i class="bi bi-people me-1"></i>Members
                            </button>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $groups->links() }}</div>
        @endif
    </div>
</div>

{{-- ── Group Members Modal ─────────────────────────────────────────────── --}}
<div class="modal fade" id="groupMembersModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fw-semibold">
                    <i class="bi bi-people me-2 text-primary"></i><span id="gmModalTitle">Group Members</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="gmLoading" class="text-center py-4 text-muted">
                    <span class="spinner-border spinner-border-sm me-2"></span>Loading members…
                </div>
                <div id="gmContent" class="d-none"></div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Auto-submit form after 500 ms pause — searches all pages server-side
(function () {
    let timer;
    const input = document.getElementById('grpSearch');
    if (!input) return;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(() => document.getElementById('grpFilterForm').submit(), 500);
    });
})();

// ── Group Members AJAX modal ──────────────────────────────────────────────
const gmModal   = new bootstrap.Modal(document.getElementById('groupMembersModal'));
const gmTitle   = document.getElementById('gmModalTitle');
const gmLoading = document.getElementById('gmLoading');
const gmContent = document.getElementById('gmContent');

function loadGroupMembers(azureId, groupName) {
    gmTitle.textContent = groupName;
    gmLoading.classList.remove('d-none');
    gmContent.classList.add('d-none');
    gmContent.innerHTML = '';
    gmModal.show();

    fetch('{{ url('admin/identity/groups') }}/' + encodeURIComponent(azureId) + '/members', {
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.members || data.members.length === 0) {
            gmContent.innerHTML = '<p class="text-center text-muted py-4">No members found in local DB. Run a sync to update.</p>';
        } else {
            let rows = data.members.map(m => `
                <tr>
                    <td class="fw-semibold">${escHtml(m.display_name)}</td>
                    <td class="font-monospace text-muted small">${escHtml(m.user_principal_name)}</td>
                    <td>${escHtml(m.department || '—')}</td>
                    <td class="text-center">
                        <span class="badge bg-${m.account_enabled ? 'success' : 'danger'}">
                            ${m.account_enabled ? 'Enabled' : 'Disabled'}
                        </span>
                    </td>
                </tr>`).join('');
            gmContent.innerHTML = `
                <div class="small text-muted px-3 pt-2 pb-1">${data.members.length} member(s)</div>
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Name</th><th>UPN</th><th>Department</th><th class="text-center">Status</th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
                </div>`;
        }
        gmLoading.classList.add('d-none');
        gmContent.classList.remove('d-none');
    })
    .catch(() => {
        gmContent.innerHTML = '<p class="text-center text-danger py-4">Failed to load members.</p>';
        gmLoading.classList.add('d-none');
        gmContent.classList.remove('d-none');
    });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
@endpush

@endsection
