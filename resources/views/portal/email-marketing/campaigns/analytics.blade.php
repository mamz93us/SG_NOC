@extends('layouts.marketing')

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
            ['label' => 'Sent',       'value' => $campaign->total_sent],
            ['label' => 'Delivered',  'value' => $campaign->total_delivered, 'pct' => $campaign->deliveryRate()],
            ['label' => 'Opens',      'value' => $campaign->total_opens,     'sub' => $campaign->total_unique_opens.' unique ('.$campaign->openRate().'%)'],
            ['label' => 'Clicks',     'value' => $campaign->total_clicks,    'sub' => $campaign->total_unique_clicks.' unique ('.$campaign->clickRate().'%)'],
            ['label' => 'Bounces',    'value' => $campaign->total_bounces,   'pct' => $campaign->bounceRate()],
            ['label' => 'Complaints', 'value' => $campaign->total_complaints,'pct' => $campaign->complaintRate()],
            ['label' => 'Unsubscribes','value'=> $campaign->total_unsubscribes],
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
                    @if (isset($kpi['sub']))
                        <small class="text-muted">{{ $kpi['sub'] }}</small>
                    @endif
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
                        <thead class="table-light"><tr><th>URL</th><th class="text-end">Clicks</th><th class="text-end">Unique</th></tr></thead>
                        <tbody>
                        @forelse ($topLinks as $link)
                            <tr>
                                <td class="text-truncate" style="max-width: 280px;">
                                    <a href="{{ $link->url }}" target="_blank" rel="noopener">{{ $link->url }}</a>
                                </td>
                                <td class="text-end">{{ $link->clicks }}</td>
                                <td class="text-end"><small class="text-muted">{{ $link->unique_clicks }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">No clicks yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Per-recipient activity (who opened / clicked) ──────────── --}}
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong><i class="bi bi-people me-1"></i>Recipient activity</strong>
            <form class="d-flex gap-2 flex-wrap" method="GET">
                @if ($eventFilter !== '')<input type="hidden" name="event_type" value="{{ $eventFilter }}">@endif
                <input type="text" name="q" class="form-control form-control-sm" style="max-width: 220px"
                       placeholder="Search email / name…" value="{{ $recipientQuery }}">
                <select name="recipient_status" class="form-select form-select-sm" style="max-width: 180px">
                    <option value="">All send statuses</option>
                    @foreach (['queued','sent','delivered','bounced','complained','failed','suppressed'] as $s)
                        <option value="{{ $s }}" @selected($recipientFilter === $s)>{{ $s }}</option>
                    @endforeach
                </select>
                <button class="btn btn-outline-primary btn-sm">Filter</button>
                @if ($recipientQuery !== '' || $recipientFilter !== '')
                    <a href="{{ route('portal.marketing.campaigns.analytics', $campaign) }}" class="btn btn-link btn-sm">Reset</a>
                @endif
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Recipient</th>
                        <th>Status</th>
                        <th class="text-end">Opens</th>
                        <th class="text-end">Clicks</th>
                        <th>Last activity</th>
                        <th>Last IP</th>
                        <th>Last user agent</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($recipients as $r)
                    <tr>
                        <td>
                            <a href="{{ route('portal.marketing.campaigns.analytics.recipient', ['campaign' => $campaign, 'send' => $r->send_id]) }}"
                               title="View full event log for this recipient">
                                <strong>{{ $r->email }}</strong>
                            </a>
                            @if ($r->first_name || $r->last_name)
                                <br><small class="text-muted">{{ trim(($r->first_name ?? '').' '.($r->last_name ?? '')) }}</small>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-{{ match($r->send_status) {
                                'delivered' => 'success',
                                'sent' => 'primary',
                                'bounced' => 'danger',
                                'complained' => 'warning',
                                'suppressed' => 'secondary',
                                'failed' => 'dark',
                                default => 'light text-dark',
                            } }} text-capitalize">{{ $r->send_status }}</span>
                            @if ($r->error_message)
                                <br><small class="text-danger" title="{{ $r->error_message }}">{{ \Illuminate\Support\Str::limit($r->error_message, 50) }}</small>
                            @endif
                        </td>
                        <td class="text-end">
                            @if ($r->opens > 0)
                                <span class="badge bg-info">{{ $r->opens }}</span>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td class="text-end">
                            @if ($r->clicks > 0)
                                <span class="badge bg-success">{{ $r->clicks }}</span>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td><small>{{ $r->last_activity_at ? \Carbon\Carbon::parse($r->last_activity_at)->diffForHumans() : '—' }}</small></td>
                        <td>
                            <small><code>{{ $r->last_ip ?: '—' }}</code></small>
                            @if ($r->last_country_code)
                                <br><small class="text-muted" title="{{ $r->last_country_name }}">
                                    {{ \App\Services\EmailMarketing\GeoIpLookup::flagEmoji($r->last_country_code) }}
                                    {{ $r->last_country_code }} — {{ $r->last_country_name }}
                                </small>
                            @endif
                        </td>
                        <td class="text-truncate" style="max-width: 260px;">
                            <small class="text-muted" title="{{ $r->last_user_agent }}">{{ \Illuminate\Support\Str::limit($r->last_user_agent ?? '—', 50) }}</small>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No recipients match.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $recipients->links() }}</div>
    </div>

    {{-- ── Detailed event log ─────────────────────────────────────── --}}
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong><i class="bi bi-list-ul me-1"></i>Event log</strong>
            <form class="d-flex gap-2" method="GET">
                @if ($recipientQuery !== '')<input type="hidden" name="q" value="{{ $recipientQuery }}">@endif
                @if ($recipientFilter !== '')<input type="hidden" name="recipient_status" value="{{ $recipientFilter }}">@endif
                <select name="event_type" class="form-select form-select-sm" style="max-width: 180px">
                    <option value="">All event types</option>
                    @foreach (['Send','Delivery','Open','Click','Bounce','Complaint','Reject','RenderingFailure'] as $t)
                        <option value="{{ $t }}" @selected($eventFilter === $t)>{{ $t }}</option>
                    @endforeach
                </select>
                <button class="btn btn-outline-primary btn-sm">Filter</button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 150px;">When</th>
                        <th>Recipient</th>
                        <th>Event</th>
                        <th>URL / Detail</th>
                        <th>IP</th>
                        <th>User agent</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($events as $ev)
                    <tr>
                        <td><small>{{ $ev->created_at?->format('Y-m-d H:i:s') }}</small></td>
                        <td><small>{{ $ev->subscriber_email ?: '—' }}</small></td>
                        <td>
                            <span class="badge bg-{{ match($ev->event_type) {
                                'Delivery' => 'success',
                                'Open' => 'info',
                                'Click' => 'primary',
                                'Bounce' => 'danger',
                                'Complaint' => 'warning',
                                'Reject', 'RenderingFailure' => 'dark',
                                default => 'secondary',
                            } }}">{{ $ev->event_type }}</span>
                        </td>
                        <td class="text-truncate" style="max-width: 360px;">
                            @if ($ev->event_type === 'Click' && $ev->url)
                                <a href="{{ $ev->url }}" target="_blank" rel="noopener" title="{{ $ev->url }}"><small>{{ $ev->url }}</small></a>
                            @elseif ($ev->event_type === 'Bounce')
                                @php
                                    $rp = is_array($ev->raw_payload)
                                        ? $ev->raw_payload
                                        : (json_decode($ev->raw_payload ?? '', true) ?: []);
                                    $br = ($rp['bounce']['bouncedRecipients'] ?? [[]])[0] ?? [];
                                    $diag = $br['diagnosticCode'] ?? null;
                                @endphp
                                <small class="text-danger d-block">{{ $ev->bounce_type }}{{ $ev->bounce_subtype ? ' / '.$ev->bounce_subtype : '' }}</small>
                                @if ($diag)
                                    <small class="text-muted" title="{{ $diag }}">{{ \Illuminate\Support\Str::limit($diag, 80) }}</small>
                                @endif
                            @elseif ($ev->event_type === 'Complaint' && $ev->complaint_type)
                                <small class="text-warning">{{ $ev->complaint_type }}</small>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td>
                            <small><code>{{ $ev->ip_address ?: '—' }}</code></small>
                            @if ($ev->country_code)
                                <br><small class="text-muted" title="{{ $ev->country_name }}">
                                    {{ \App\Services\EmailMarketing\GeoIpLookup::flagEmoji($ev->country_code) }}
                                    {{ $ev->country_code }}
                                </small>
                            @endif
                        </td>
                        <td class="text-truncate" style="max-width: 260px;">
                            <small class="text-muted" title="{{ $ev->user_agent }}">{{ \Illuminate\Support\Str::limit($ev->user_agent ?? '—', 60) }}</small>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No events yet — opens/clicks/deliveries will appear here as they arrive from AWS SNS.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $events->links() }}</div>
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
