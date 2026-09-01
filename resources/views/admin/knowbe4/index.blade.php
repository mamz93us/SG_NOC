@extends('layouts.admin')

@section('title', 'Security Awareness')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-shield-check me-2 text-primary"></i>Security Awareness</h4>
        <small class="text-muted">
            KnowBe4 Phish-Prone Percentage across the company — the share of simulated
            phishing emails each person failed.
            @if($settings->knowbe4_last_sync_at)
                Last synced {{ $settings->knowbe4_last_sync_at->diffForHumans() }}.
            @else
                <span class="text-danger">Never synced.</span>
            @endif
        </small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.knowbe4.export', request()->query()) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Export CSV
        </a>
        <form method="POST" action="{{ route('admin.knowbe4.sync') }}" id="syncForm">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" id="syncBtn">
                <i class="bi bi-arrow-repeat me-1"></i>Sync Now
            </button>
        </form>
    </div>
</div>

@if (session('success'))
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill me-1"></i>
        <pre class="mb-0 mt-1 small" style="white-space:pre-wrap">{{ session('success') }}</pre>
    </div>
@endif
@if (session('error'))
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-octagon-fill me-1"></i>
        <pre class="mb-0 mt-1 small" style="white-space:pre-wrap">{{ session('error') }}</pre>
    </div>
@endif

@if(! $settings->knowbe4_enabled)
    <div class="alert alert-warning d-flex gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div class="small">
            KnowBe4 is switched off, so nothing will sync.
            @can('manage-settings')
                Enable it under
                <a href="{{ route('admin.settings.index') }}#home-portal" class="alert-link">Settings &rarr; Employee Home Portal</a>.
            @endcan
        </div>
    </div>
@endif

{{-- ── Summary ──────────────────────────────────────────────── --}}
<div class="row g-3 mb-4">
    @php
        $tiles = [
            ['label' => 'People',        'value' => $stats['total'],        'cls' => 'text-body'],
            ['label' => 'Average PPP',   'value' => $stats['avg'] . '%',    'cls' => 'text-body'],
            ['label' => 'High (30%+)',   'value' => $stats['high'],         'cls' => 'text-danger'],
            ['label' => 'Medium (10-30%)', 'value' => $stats['medium'],     'cls' => 'text-warning-emphasis'],
            ['label' => 'Low (<10%)',    'value' => $stats['low'],          'cls' => 'text-success'],
            ['label' => 'Training due',  'value' => $stats['training_due'], 'cls' => 'text-warning-emphasis'],
            ['label' => 'Unmatched',     'value' => $stats['unmatched'],    'cls' => 'text-muted'],
        ];
    @endphp
    @foreach($tiles as $t)
        <div class="col-6 col-md-3 col-xl">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold {{ $t['cls'] }}">{{ $t['value'] }}</div>
                    <div class="small text-muted">{{ $t['label'] }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

@if($stats['unmatched'] > 0)
    <div class="alert alert-secondary d-flex gap-2 py-2">
        <i class="bi bi-info-circle"></i>
        <div class="small">
            <strong>{{ $stats['unmatched'] }}</strong> KnowBe4 users have no matching employee record.
            Matching is by email only, so anyone whose KnowBe4 address differs from their directory
            address will not see a Security Score on the portal.
            <a href="{{ route('admin.knowbe4.index', ['matched' => 'no']) }}" class="alert-link">Show them</a>.
        </div>
    </div>
@endif

{{-- ── Filters ──────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="search" name="q" value="{{ $q }}" class="form-control form-control-sm"
                       placeholder="Search name or email…">
            </div>
            <div class="col-md-3">
                <select name="band" class="form-select form-select-sm">
                    <option value="">All phish-prone levels</option>
                    <option value="high" @selected($band === 'high')>High (30%+)</option>
                    <option value="medium" @selected($band === 'medium')>Medium (10–30%)</option>
                    <option value="low" @selected($band === 'low')>Low (under 10%)</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="matched" class="form-select form-select-sm">
                    <option value="">Everyone</option>
                    <option value="yes" @selected($matched === 'yes')>Matched only</option>
                    <option value="no" @selected($matched === 'no')>Unmatched only</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary flex-grow-1">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                @if($q || $band || $matched)
                    <a href="{{ route('admin.knowbe4.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>

{{-- ── Roster ───────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Person</th>
                    <th style="min-width:170px">Department</th>
                    <th style="width:120px">Phish-prone</th>
                    <th style="width:120px" class="text-center">Phishing</th>
                    <th style="width:120px" class="text-center">Training due</th>
                    <th style="width:130px">Last failed</th>
                </tr>
            </thead>
            <tbody>
                @forelse($scores as $s)
                    <tr>
                        <td>
                            <div class="fw-semibold">
                                {{ $s->employee?->name ?: '—' }}
                                @unless($s->employee)
                                    <span class="badge bg-secondary ms-1" title="No matching employee record">unmatched</span>
                                @endunless
                            </div>
                            <div class="small text-muted font-monospace">{{ $s->email }}</div>
                        </td>
                        <td class="small">
                            {{ $s->employee?->department?->name ?: '—' }}
                            @if($s->employee?->branch)
                                <div class="text-muted">{{ $s->employee->branch->name }}</div>
                            @endif
                        </td>
                        <td>
                            @php
                                $badge = match($s->pppBand()) {
                                    'high' => 'bg-danger',
                                    'medium' => 'bg-warning text-dark',
                                    'low' => 'bg-success',
                                    default => 'bg-secondary',
                                };
                            @endphp
                            <span class="badge {{ $badge }}">{{ $s->pppLabel() }}@if($s->phish_prone_percentage !== null)%@endif</span>
                        </td>
                        <td class="text-center small">
                            @if($s->phish_fail_count > 0)
                                <span class="text-danger fw-semibold">{{ $s->phish_fail_count }}</span>
                            @else
                                <span class="text-muted">0</span>
                            @endif
                            <span class="text-muted">/ {{ $s->phish_sent_count }}</span>
                        </td>
                        <td class="text-center">
                            @if($s->trainings_outstanding > 0)
                                <span class="badge bg-warning text-dark">{{ $s->trainings_outstanding }}</span>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td class="small text-muted">
                            {{ $s->last_phish_failed_at?->format('d M Y') ?: '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-shield-check fs-3 d-block mb-2"></i>
                            @if($stats['total'] === 0)
                                Nothing synced yet — press <strong>Sync Now</strong> above.
                            @else
                                No one matches those filters.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($scores->hasPages())
    <div class="mt-3">{{ $scores->links() }}</div>
@endif

<script>
// The sync runs inline and takes a few seconds; without this the obvious move
// is to press it again and start a second one.
document.getElementById('syncForm')?.addEventListener('submit', function () {
    var btn = document.getElementById('syncBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Syncing…';
});
</script>
@endsection
