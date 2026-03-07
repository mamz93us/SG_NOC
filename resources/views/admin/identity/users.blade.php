@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-people-fill me-2 text-primary"></i>Identity Users</h4>
        <small class="text-muted">
            Synced from Microsoft Entra ID
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

{{-- ── Search + Filters ───────────────────────────────────────────────────── --}}
<form method="GET" id="userFilterForm" class="mb-3">
    <div class="input-group input-group-lg shadow-sm">
        <span class="input-group-text bg-white border-end-0 text-muted">
            <i class="bi bi-search"></i>
        </span>
        <input type="text" name="search" id="userSearch"
               class="form-control border-start-0 border-end-0 ps-0"
               placeholder="Search by name, email, or department…"
               value="{{ request('search') }}"
               autocomplete="off">
        <select name="status" class="form-select flex-grow-0" style="max-width:160px" onchange="this.form.submit()">
            <option value="">All Status</option>
            <option value="enabled"  {{ request('status') === 'enabled'  ? 'selected' : '' }}>Enabled</option>
            <option value="disabled" {{ request('status') === 'disabled' ? 'selected' : '' }}>Disabled</option>
        </select>
        @if(request('search') || request('status'))
        <a href="{{ route('admin.identity.users') }}" class="btn btn-outline-secondary">
            <i class="bi bi-x-lg"></i>
        </a>
        @endif
    </div>
    <div class="form-check form-switch ms-2 align-self-center">
        <input class="form-check-input" type="checkbox" id="showExternal" name="show_external" value="1"
               {{ $showExternal ? 'checked' : '' }} onchange="this.form.submit()">
        <label class="form-check-label small" for="showExternal">Show External (#EXT#)</label>
    </div>
    <div class="form-text ps-1 mt-1">Searching across all {{ number_format($users->total()) }} users</div>
</form>
@if(! empty($allowedDomains))
<div class="mb-2">
    <small class="text-muted"><i class="bi bi-funnel me-1"></i>Filtering by domains:
    @foreach($allowedDomains as $d)
    <span class="badge bg-light text-dark border ms-1">{{ $d }}</span>
    @endforeach
    </small>
</div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($users->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-people display-4 d-block mb-2"></i>
            @if(request('search') || request('status'))
                No users match your filters.
            @else
                No users found.
                @if(!$lastSync) <div class="small mt-1">Run a sync to import users from Entra ID.</div> @endif
            @endif
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Phone</th>
                        <th>Location</th>
                        <th class="text-center">Licenses</th>
                        <th class="text-center">Groups</th>
                        <th class="text-center">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $u)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white fw-bold"
                                     style="width:32px;height:32px;font-size:.75rem;flex-shrink:0">
                                    {{ $u->initials() }}
                                </div>
                                <div>
                                    <div class="fw-semibold">{{ $u->display_name }}</div>
                                    <div class="text-muted" style="font-size:.75rem">{{ $u->job_title ?: '' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="text-muted">{{ $u->mail ?: '—' }}</td>
                        <td>{{ $u->department ?: '—' }}</td>
                        <td class="text-muted">
                            @if($u->phone_number)
                                <a href="tel:{{ $u->phone_number }}" class="text-decoration-none">{{ $u->phone_number }}</a>
                            @elseif($u->mobile_phone)
                                <a href="tel:{{ $u->mobile_phone }}" class="text-decoration-none text-muted">{{ $u->mobile_phone }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-muted">
                            {{ implode(', ', array_filter([$u->office_location, $u->city])) ?: '—' }}
                        </td>
                        <td class="text-center">
                            <span class="badge bg-{{ $u->licenses_count > 0 ? 'primary' : 'light text-muted border' }}">
                                {{ $u->licenses_count }}
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-{{ $u->groups_count > 0 ? 'info text-dark' : 'light text-muted border' }}">
                                {{ $u->groups_count }}
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge {{ $u->statusBadgeClass() }}">{{ $u->statusLabel() }}</span>
                        </td>
                        <td>
                            <a href="{{ route('admin.identity.user', $u->azure_id) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">
                Showing {{ $users->firstItem() }}–{{ $users->lastItem() }} of {{ number_format($users->total()) }} users
            </small>
            {{ $users->links() }}
        </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
// Auto-submit form after 500 ms pause — searches all pages server-side
(function () {
    let timer;
    const input = document.getElementById('userSearch');
    if (!input) return;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(() => document.getElementById('userFilterForm').submit(), 500);
    });
})();
</script>
@endpush

@endsection
