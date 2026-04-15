@extends('layouts.admin')
@section('title', 'DNS Accounts')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-globe2 me-2"></i>DNS Accounts</h4>
            <small class="text-muted">Manage GoDaddy API accounts for DNS management</small>
        </div>
        @can('manage-dns')
        <a href="{{ route('admin.network.dns.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Add Account
        </a>
        @endcan
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Label</th>
                        <th>Environment</th>
                        <th>API Key</th>
                        <th>Shopper ID</th>
                        <th>Status</th>
                        <th>Last Tested</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($accounts as $acct)
                    <tr>
                        <td class="fw-semibold">{{ $acct->label }}</td>
                        <td><span class="badge {{ $acct->environmentBadgeClass() }}">{{ ucfirst($acct->environment) }}</span></td>
                        <td><code class="small">{{ $acct->maskedApiKey() }}</code></td>
                        <td>{{ $acct->shopper_id ?? '-' }}</td>
                        <td><span class="badge {{ $acct->statusBadgeClass() }}">{{ $acct->statusLabel() }}</span></td>
                        <td>{{ $acct->last_tested_at?->diffForHumans() ?? 'Never' }}</td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="{{ route('admin.network.dns.domains.index', $acct) }}" class="btn btn-outline-primary" title="View Domains">
                                    <i class="bi bi-globe"></i>
                                </a>
                                @can('manage-dns')
                                <a href="{{ route('admin.network.dns.edit', $acct) }}" class="btn btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" class="btn btn-outline-info btn-test-connection" data-url="{{ route('admin.network.dns.test', $acct) }}" title="Test Connection">
                                    <i class="bi bi-wifi"></i>
                                </button>
                                <form method="POST" action="{{ route('admin.network.dns.destroy', $acct) }}" class="d-inline" onsubmit="return confirm('Delete account \'{{ $acct->label }}\'?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-globe2 display-4 d-block mb-2"></i>
                            No DNS accounts configured yet.
                            @can('manage-dns')
                            <br><a href="{{ route('admin.network.dns.create') }}">Add your first GoDaddy account</a>.
                            @endcan
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.querySelectorAll('.btn-test-connection').forEach(btn => {
    btn.addEventListener('click', function() {
        const url = this.dataset.url;
        const icon = this.querySelector('i');
        this.disabled = true;
        icon.className = 'spinner-border spinner-border-sm';

        fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            icon.className = data.success ? 'bi bi-check-circle text-success' : 'bi bi-x-circle text-danger';
            // Update status badge in same row
            const row = this.closest('tr');
            const badge = row.querySelector('td:nth-child(5) .badge');
            if (badge) {
                badge.className = 'badge ' + (data.success ? 'bg-success' : 'bg-danger');
                badge.textContent = data.success ? 'Connected' : 'Error';
            }
            const tested = row.querySelector('td:nth-child(6)');
            if (tested) tested.textContent = 'just now';
        })
        .catch(() => {
            icon.className = 'bi bi-x-circle text-danger';
        })
        .finally(() => {
            this.disabled = false;
            setTimeout(() => { icon.className = 'bi bi-wifi'; }, 3000);
        });
    });
});
</script>
@endpush
@endsection
