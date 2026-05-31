@extends('layouts.marketing')

@section('title', 'Email Marketing')

@section('content')
<style>
    .stat-card { transition: transform .15s ease, box-shadow .15s ease; border: 0; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.08) !important; }
    .stat-icon {
        width: 48px; height: 48px;
        display: inline-flex; align-items: center; justify-content: center;
        border-radius: 12px; font-size: 1.4rem;
    }
    .stat-card h3 { font-weight: 700; margin: 0; line-height: 1.1; }
    .stat-card .stat-label { font-size: .85rem; color: #6c757d; letter-spacing: .02em; }
    .progress-thin { height: 6px; border-radius: 6px; }
</style>

<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    {{-- ── Headline KPIs ─────────────────────────────────────────── --}}
    <div class="row g-3 mb-4">
        @php
            $headline = [
                ['label' => 'Active subscribers', 'value' => $kpis['subscribers_active'], 'icon' => 'people-fill', 'bg' => 'bg-success-subtle', 'color' => 'text-success'],
                ['label' => 'Pending opt-in',     'value' => $kpis['subscribers_pending'], 'icon' => 'hourglass-split', 'bg' => 'bg-warning-subtle', 'color' => 'text-warning'],
                ['label' => 'Bounced/Complained', 'value' => $kpis['subscribers_bounced'], 'icon' => 'x-octagon-fill', 'bg' => 'bg-danger-subtle', 'color' => 'text-danger'],
                ['label' => 'Suppression list',   'value' => $kpis['suppressions'], 'icon' => 'shield-x', 'bg' => 'bg-secondary-subtle', 'color' => 'text-secondary'],
            ];
        @endphp
        @foreach ($headline as $k)
        <div class="col-md-6 col-xl-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon {{ $k['bg'] }} {{ $k['color'] }}"><i class="bi bi-{{ $k['icon'] }}"></i></span>
                    <div>
                        <div class="stat-label">{{ $k['label'] }}</div>
                        <h3>{{ number_format($k['value']) }}</h3>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ── This-month KPI band ───────────────────────────────────── --}}
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <strong><i class="bi bi-calendar-month me-1"></i>This month so far</strong>
                <small class="text-muted">{{ now()->format('F Y') }}</small>
            </div>
            <div class="row g-3 text-center">
                <div class="col-md-2 col-6">
                    <div class="stat-label">Sent</div>
                    <h3 class="text-primary">{{ number_format($kpis['sent_this_month']) }}</h3>
                </div>
                <div class="col-md-2 col-6">
                    <div class="stat-label">Delivered</div>
                    <h3 class="text-success">{{ number_format($kpis['delivered_this_month']) }}</h3>
                </div>
                <div class="col-md-2 col-6">
                    <div class="stat-label">Unique opens</div>
                    <h3 class="text-info">{{ number_format($kpis['opens_this_month']) }}</h3>
                    <small class="text-muted">{{ $kpis['avg_open_rate'] }}% rate</small>
                </div>
                <div class="col-md-2 col-6">
                    <div class="stat-label">Unique clicks</div>
                    <h3 class="text-primary">{{ number_format($kpis['clicks_this_month']) }}</h3>
                    <small class="text-muted">{{ $kpis['avg_click_rate'] }}% rate</small>
                </div>
                <div class="col-md-2 col-6">
                    <div class="stat-label">Bounces</div>
                    <h3 class="text-danger">{{ number_format($kpis['bounces_this_month']) }}</h3>
                    <small class="text-muted">{{ $kpis['avg_bounce_rate'] }}% rate</small>
                </div>
                <div class="col-md-2 col-6 d-flex align-items-center justify-content-center">
                    <a href="{{ route('portal.marketing.campaigns.benchmark') }}" class="btn btn-outline-info">
                        <i class="bi bi-bar-chart-line me-1"></i>Benchmark
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Charts row ────────────────────────────────────────────── --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-graph-up me-1"></i>Email volume (last 30 days)</strong>
                    <small class="text-muted">Emails sent per day</small>
                </div>
                <div class="card-body">
                    <canvas id="volumeChart" height="100"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light">
                    <strong><i class="bi bi-pie-chart me-1"></i>Engagement (30 days)</strong>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <canvas id="engagementChart" height="220"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Top campaigns + Recent campaigns ─────────────────────── --}}
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-trophy me-1"></i>Top open rates (last 90 days)</strong>
                    <small class="text-muted">Min 10 delivered</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Campaign</th><th class="text-end">Delivered</th><th class="text-end">Open rate</th></tr>
                        </thead>
                        <tbody>
                        @forelse ($topByOpens as $c)
                            @php $rate = $c->total_delivered > 0 ? round($c->total_unique_opens / $c->total_delivered * 100, 1) : 0; @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('portal.marketing.campaigns.show', $c) }}"><small><strong>{{ $c->name }}</strong></small></a>
                                    <br><small class="text-muted">{{ $c->sent_at?->format('Y-m-d') ?: '—' }}</small>
                                </td>
                                <td class="text-end">{{ number_format($c->total_delivered) }}</td>
                                <td class="text-end">
                                    <span class="badge bg-success">{{ $rate }}%</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">Not enough data yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-megaphone me-1"></i>Recent campaigns</strong>
                    <a href="{{ route('portal.marketing.campaigns.create') }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus me-1"></i>New
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Name</th><th>Status</th><th>Sent</th></tr>
                        </thead>
                        <tbody>
                        @forelse ($recentCampaigns as $c)
                            <tr>
                                <td><a href="{{ route('portal.marketing.campaigns.show', $c) }}"><small><strong>{{ $c->name }}</strong></small></a></td>
                                <td>
                                    <span class="badge bg-{{ match($c->status) {
                                        'sent' => 'success', 'sending' => 'primary', 'scheduled' => 'warning',
                                        'paused' => 'secondary', 'failed' => 'danger', default => 'light text-dark',
                                    } }} text-capitalize">{{ $c->status }}</span>
                                </td>
                                <td><small>{{ $c->sent_at?->diffForHumans() ?: ($c->scheduled_at?->diffForHumans() ?: '—') }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">No campaigns yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Lists + Recent issues ─────────────────────────────────── --}}
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-list-ul me-1"></i>Lists</strong>
                    <a href="{{ route('portal.marketing.lists.index') }}" class="small">View all →</a>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                    @forelse ($lists as $l)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <a href="{{ route('portal.marketing.lists.show', $l) }}">{{ $l->name }}</a>
                                @if ($l->isDynamic())
                                    <span class="badge bg-info text-dark ms-1"><i class="bi bi-arrow-repeat"></i> dynamic</span>
                                @endif
                            </div>
                            <span class="badge bg-secondary rounded-pill">{{ number_format($l->subscribers_count) }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-center text-muted">No lists yet.</li>
                    @endforelse
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light">
                    <strong><i class="bi bi-exclamation-triangle me-1"></i>Recent bounces &amp; complaints (last 7 days)</strong>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>When</th><th>Recipient</th><th>Type</th><th>Campaign</th></tr>
                        </thead>
                        <tbody>
                        @forelse ($recentIssues as $ev)
                            <tr>
                                <td><small>{{ $ev->created_at?->diffForHumans() }}</small></td>
                                <td><small>{{ $ev->subscriber_email ?: '—' }}</small></td>
                                <td>
                                    <span class="badge bg-{{ $ev->event_type === 'Bounce' ? 'danger' : 'warning' }}">{{ $ev->event_type }}</span>
                                    @if ($ev->bounce_type)<small class="text-muted ms-1">{{ $ev->bounce_type }}</small>@endif
                                </td>
                                <td>
                                    <a href="{{ route('portal.marketing.campaigns.analytics.recipient', ['campaign' => $ev->campaign_id, 'send' => $ev->send_id]) }}">
                                        <small>{{ \Illuminate\Support\Str::limit($ev->campaign_name, 30) }}</small>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">Nothing in the last 7 days — good!</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(function () {
    // Volume line chart
    const volume = @json($volumeSeries);
    if (volume.length) {
        new Chart(document.getElementById('volumeChart'), {
            type: 'line',
            data: {
                labels: volume.map(d => d.day.slice(5)), // MM-DD
                datasets: [{
                    label: 'Emails sent',
                    data: volume.map(d => d.count),
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 2,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }

    // Engagement donut
    const eng = @json($engagement);
    const total = Object.values(eng).reduce((a, b) => a + b, 0);
    if (total > 0) {
        new Chart(document.getElementById('engagementChart'), {
            type: 'doughnut',
            data: {
                labels: ['Delivery', 'Open', 'Click', 'Bounce', 'Complaint'],
                datasets: [{
                    data: [eng.Delivery, eng.Open, eng.Click, eng.Bounce, eng.Complaint],
                    backgroundColor: ['#198754', '#0dcaf0', '#0d6efd', '#dc3545', '#ffc107'],
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
            },
        });
    }
})();
</script>
@endsection
