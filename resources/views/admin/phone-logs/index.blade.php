@extends('layouts.admin')

@section('title', 'Phone XML Requests')

@section('content')

{{-- ── Header row: title + action buttons ── --}}
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0">Phone XML Request Logs</h4>

    <div class="d-flex gap-2">
        {{-- Sync all devices --}}
        <form method="POST" action="{{ route('admin.phone-logs.sync') }}" class="sync-form">
            @csrf
            <button type="submit" class="btn btn-primary sync-btn">
                🔄 Sync All
            </button>
        </form>

        {{-- Sync only devices not yet fetched --}}
        <form method="POST" action="{{ route('admin.phone-logs.sync-unsynced') }}" class="sync-form">
            @csrf
            <button type="submit" class="btn btn-outline-secondary sync-btn">
                ⬇️ Sync Unsynced Only
            </button>
        </form>
    </div>
</div>

{{-- ── Dynamic search bar ── --}}
<div class="mb-3">
    <input type="search"
           id="log-search"
           class="form-control"
           placeholder="Search by MAC, model, SIP account, contact name…"
           autocomplete="off">
</div>

{{-- ── Table ── --}}
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-bordered mb-0 align-middle" id="logs-table">
            <thead class="table-dark">
                <tr>
                    <th class="px-3">
                        <a href="{{ request()->fullUrlWithQuery(['sort' => 'mac', 'direction' => request('sort') == 'mac' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-white text-decoration-none">
                            MAC Address {!! request('sort') == 'mac' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small opacity-50"></i>' !!}
                        </a>
                    </th>
                    <th>
                        <a href="{{ request()->fullUrlWithQuery(['sort' => 'model', 'direction' => request('sort') == 'model' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-white text-decoration-none">
                            Model {!! request('sort') == 'model' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small opacity-50"></i>' !!}
                        </a>
                    </th>
                    <th>SIP Accounts (from GDMS)</th>
                    <th>
                        <a href="{{ request()->fullUrlWithQuery(['sort' => 'last_request_at', 'direction' => request('sort') == 'last_request_at' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-white text-decoration-none">
                            Last Request {!! request('sort') == 'last_request_at' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small opacity-50"></i>' !!}
                        </a>
                    </th>
                    <th class="text-center">
                        <a href="{{ request()->fullUrlWithQuery(['sort' => 'total_requests', 'direction' => request('sort') == 'total_requests' && request('direction') == 'asc' ? 'desc' : 'asc']) }}" class="text-white text-decoration-none">
                            Total Requests {!! request('sort') == 'total_requests' ? (request('direction') == 'asc' ? '<i class="bi bi-sort-up"></i>' : '<i class="bi bi-sort-down"></i>') : '<i class="bi bi-arrows-expand small opacity-50"></i>' !!}
                        </a>
                    </th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    @php
                        $macAccounts = $accounts[$log->mac] ?? collect();

                        // Build search index for this row
                        $searchIndex = $log->mac . ' '
                            . strtoupper(implode(':', str_split($log->mac, 2))) . ' '
                            . ($log->model ?? '');

                        foreach ($macAccounts as $acc) {
                            $searchIndex .= ' ' . ($acc->sip_user_id    ?? '')
                                          . ' ' . ($acc->sip_server     ?? '')
                                          . ' ' . ($acc->account_status ?? '');

                            $c = $acc->sip_user_id ? ($contactsByPhone[$acc->sip_user_id] ?? null) : null;
                            if ($c) {
                                $searchIndex .= ' ' . $c->first_name . ' ' . $c->last_name;
                            }
                        }
                    @endphp
                    <tr data-search="{{ strtolower($searchIndex) }}">

                        {{-- MAC --}}
                        <td class="font-monospace px-3">
                            {{ strtoupper(implode(':', str_split($log->mac, 2))) }}
                        </td>

                        {{-- Model --}}
                        <td>{{ $log->model ?? '—' }}</td>

                        {{-- SIP Accounts --}}
                        <td>
                            @if ($macAccounts->isEmpty())
                                <span class="text-muted small">Not synced yet</span>
                            @else
                                @foreach ($macAccounts as $acc)
                                    @php
                                        $contact = $acc->sip_user_id ? ($contactsByPhone[$acc->sip_user_id] ?? null) : null;
                                        $isReg   = strtolower($acc->account_status ?? '') === 'registered';
                                    @endphp
                                    <div class="mb-1 d-flex align-items-center gap-2 flex-wrap">
                                        <span class="badge bg-secondary">Acc #{{ $acc->account_index }}</span>

                                        @if ($acc->sip_user_id)
                                            <code>{{ $acc->sip_user_id }}</code>
                                        @else
                                            <span class="text-muted small">(empty)</span>
                                        @endif

                                        @if ($acc->sip_server)
                                            <span class="text-muted small">@ {{ $acc->sip_server }}</span>
                                        @endif

                                        @if ($acc->account_status)
                                            <span class="badge {{ $isReg ? 'bg-success' : 'bg-danger' }}">
                                                {{ $acc->account_status }}
                                            </span>
                                        @endif

                                        @if ($acc->is_local)
                                            <span class="badge bg-info text-dark">Local</span>
                                        @endif

                                        @if ($contact)
                                            <span class="fw-semibold text-primary">
                                                &#x1F464; {{ $contact->first_name }} {{ $contact->last_name }}
                                            </span>
                                        @elseif ($acc->sip_user_id)
                                            <span class="text-warning small">No contact match</span>
                                        @endif
                                    </div>
                                @endforeach

                                <div class="text-muted" style="font-size:0.75rem">
                                    Synced: {{ $macAccounts->first()->fetched_at?->diffForHumans() ?? '—' }}
                                </div>
                            @endif
                        </td>

                        {{-- Last Request --}}
                        <td class="text-nowrap">{{ $log->last_request_at }}</td>

                        {{-- Total --}}
                        <td class="text-center">{{ $log->total_requests }}</td>
                    </tr>
                @empty
                    <tr id="empty-row">
                        <td colspan="5" class="text-center text-muted py-4">
                            No phone requests logged yet.
                        </td>
                    </tr>
                @endforelse

                {{-- Shown when search matches nothing --}}
                <tr id="no-results-row" style="display:none">
                    <td colspan="5" class="text-center text-muted py-4">
                        No devices match your search.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
// ── Loading spinner for sync buttons ──
document.querySelectorAll('.sync-form').forEach(function (form) {
    form.addEventListener('submit', function () {
        var btn = form.querySelector('.sync-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Syncing…';
        // Disable the other button too so both can't fire at once
        document.querySelectorAll('.sync-btn').forEach(function (b) { b.disabled = true; });
    });
});

// ── Dynamic search ──
document.getElementById('log-search').addEventListener('input', function () {
    var term = this.value.toLowerCase().trim();
    var rows = document.querySelectorAll('#logs-table tbody tr[data-search]');
    var visible = 0;

    rows.forEach(function (row) {
        var match = !term || row.getAttribute('data-search').includes(term);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    document.getElementById('no-results-row').style.display =
        (term && visible === 0) ? '' : 'none';
});
</script>

@endsection
