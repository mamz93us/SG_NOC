@extends('layouts.portal')

@section('title', 'Analytics: ' . $campaign->name)

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">{{ $campaign->name }} <small class="text-muted">analytics</small></h4>
        <a href="{{ route('portal.marketing.campaigns.show', $campaign) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <div class="row g-3 mb-4">
        @foreach ([
            ['label' => 'Recipients', 'value' => $campaign->total_recipients],
            ['label' => 'Sent', 'value' => $campaign->total_sent],
            ['label' => 'Delivered', 'value' => $campaign->total_delivered, 'pct' => $campaign->deliveryRate()],
            ['label' => 'Opens (unique)', 'value' => $campaign->total_unique_opens, 'pct' => $campaign->openRate()],
            ['label' => 'Clicks (unique)', 'value' => $campaign->total_unique_clicks, 'pct' => $campaign->clickRate()],
            ['label' => 'Bounces', 'value' => $campaign->total_bounces, 'pct' => $campaign->bounceRate()],
            ['label' => 'Complaints', 'value' => $campaign->total_complaints, 'pct' => $campaign->complaintRate()],
            ['label' => 'Unsubscribes', 'value' => $campaign->total_unsubscribes],
        ] as $kpi)
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <small class="text-muted">{{ $kpi['label'] }}</small>
                    <h4 class="mb-0">{{ number_format($kpi['value']) }}
                        @if (isset($kpi['pct']))
                            <small class="text-muted">({{ $kpi['pct'] }}%)</small>
                        @endif
                    </h4>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-md-7">
            <div class="card shadow-sm">
                <div class="card-header bg-light"><strong>Activity (first 24 h after send)</strong></div>
                <div class="card-body">
                    <canvas id="activity-chart" height="120"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-header bg-light"><strong>Top clicked links</strong></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>URL</th><th class="text-end">Clicks</th></tr></thead>
                        <tbody>
                        @forelse ($topLinks as $link)
                            <tr>
                                <td class="text-truncate" style="max-width: 320px;">
                                    <a href="{{ $link->url }}" target="_blank" rel="noopener">{{ $link->url }}</a>
                                </td>
                                <td class="text-end">{{ $link->clicks }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="text-center text-muted py-3">No clicks yet.</td></tr>
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
    const raw = @json($timeSeries);
    if (!raw || raw.length === 0) return;

    const byHour = {};
    raw.forEach(r => {
        byHour[r.hour] ??= { Open: 0, Click: 0, Bounce: 0 };
        byHour[r.hour][r.event_type] = r.c;
    });
    const labels = Object.keys(byHour).sort();

    new Chart(document.getElementById('activity-chart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Opens',   data: labels.map(l => byHour[l].Open || 0),   borderColor: '#0d6efd', tension: 0.3 },
                { label: 'Clicks',  data: labels.map(l => byHour[l].Click || 0),  borderColor: '#198754', tension: 0.3 },
                { label: 'Bounces', data: labels.map(l => byHour[l].Bounce || 0), borderColor: '#dc3545', tension: 0.3 },
            ],
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
})();
</script>
@endsection
