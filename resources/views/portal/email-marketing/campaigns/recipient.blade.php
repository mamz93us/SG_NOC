@extends('layouts.marketing')

@section('title', 'Recipient: ' . ($send->subscriber?->email ?? 'unknown'))

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">
            <i class="bi bi-person-lines-fill me-2"></i>{{ $send->subscriber?->email ?? 'Unknown recipient' }}
        </h4>
        <a href="{{ route('portal.marketing.campaigns.analytics', $campaign) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $campaign->name }}
        </a>
    </div>

    {{-- ── KPI cards ────────────────────────────────────────────── --}}
    @php
        $opens   = $events->where('event_type', 'Open')->count();
        $clicks  = $events->where('event_type', 'Click')->count();
        $delivered = $events->where('event_type', 'Delivery')->count() > 0;
        $bounced   = $events->where('event_type', 'Bounce')->count() > 0;
        $complained = $events->where('event_type', 'Complaint')->count() > 0;
        $firstOpen  = $events->where('event_type', 'Open')->first()?->created_at;
        $firstClick = $events->where('event_type', 'Click')->first()?->created_at;
        $lastEvent  = $events->last()?->created_at;
    @endphp
    <div class="row g-3 mb-3">
        <div class="col-md-2"><div class="card shadow-sm"><div class="card-body"><small class="text-muted">Send status</small>
            <h5 class="mb-0 text-capitalize">
                <span class="badge bg-{{ match($send->status) {
                    'delivered' => 'success', 'sent' => 'primary', 'bounced' => 'danger',
                    'complained' => 'warning', 'suppressed' => 'secondary', 'failed' => 'dark',
                    default => 'light text-dark',
                } }}">{{ $send->status }}</span>
            </h5>
        </div></div></div>
        <div class="col-md-2"><div class="card shadow-sm"><div class="card-body"><small class="text-muted">Opens</small>
            <h4 class="mb-0">{{ $opens }}</h4>
            @if ($firstOpen)<small class="text-muted">First: {{ $firstOpen->diffForHumans() }}</small>@endif
        </div></div></div>
        <div class="col-md-2"><div class="card shadow-sm"><div class="card-body"><small class="text-muted">Clicks</small>
            <h4 class="mb-0">{{ $clicks }}</h4>
            @if ($firstClick)<small class="text-muted">First: {{ $firstClick->diffForHumans() }}</small>@endif
        </div></div></div>
        <div class="col-md-2"><div class="card shadow-sm"><div class="card-body"><small class="text-muted">Delivered</small>
            <h4 class="mb-0">{!! $delivered ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>' !!}</h4>
        </div></div></div>
        <div class="col-md-2"><div class="card shadow-sm"><div class="card-body"><small class="text-muted">Bounced</small>
            <h4 class="mb-0">{!! $bounced ? '<i class="bi bi-x-circle text-danger"></i>' : '<i class="bi bi-check-circle text-muted"></i>' !!}</h4>
        </div></div></div>
        <div class="col-md-2"><div class="card shadow-sm"><div class="card-body"><small class="text-muted">Complaint</small>
            <h4 class="mb-0">{!! $complained ? '<i class="bi bi-exclamation-triangle text-warning"></i>' : '<i class="bi bi-check-circle text-muted"></i>' !!}</h4>
        </div></div></div>
    </div>

    {{-- ── Subscriber + send metadata ───────────────────────────── --}}
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light"><strong>Subscriber</strong></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-md-4">Name</dt>
                        <dd class="col-md-8">{{ trim(($send->subscriber?->first_name ?? '').' '.($send->subscriber?->last_name ?? '')) ?: '—' }}</dd>
                        <dt class="col-md-4">Status</dt>
                        <dd class="col-md-8"><span class="badge bg-secondary text-capitalize">{{ $send->subscriber?->status ?? '—' }}</span></dd>
                        <dt class="col-md-4">Source</dt>
                        <dd class="col-md-8">{{ $send->subscriber?->source ?? '—' }}</dd>
                        <dt class="col-md-4">Confirmed</dt>
                        <dd class="col-md-8">{{ $send->subscriber?->confirmed_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                        @if ($send->subscriber)
                            <dt class="col-md-4"></dt>
                            <dd class="col-md-8 mt-2">
                                <a href="{{ route('portal.marketing.subscribers.edit', $send->email_subscriber_id) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil me-1"></i>Edit subscriber profile
                                </a>
                            </dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-light"><strong>This send</strong></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-md-4">SES MessageId</dt>
                        <dd class="col-md-8"><small><code>{{ $send->ses_message_id ?: '—' }}</code></small></dd>
                        <dt class="col-md-4">Sent</dt>
                        <dd class="col-md-8">{{ $send->sent_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                        <dt class="col-md-4">Delivered</dt>
                        <dd class="col-md-8">{{ $send->delivered_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                        <dt class="col-md-4">Last event</dt>
                        <dd class="col-md-8">{{ $lastEvent?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                        @if ($send->error_message)
                            <dt class="col-md-4">Error</dt>
                            <dd class="col-md-8 text-danger"><small>{{ $send->error_message }}</small></dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Full event timeline for this recipient ───────────────── --}}
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <strong><i class="bi bi-list-ul me-1"></i>Event timeline</strong>
            <small class="text-muted ms-2">{{ $events->count() }} event(s)</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 170px;">When</th>
                        <th>Event</th>
                        <th>URL / Detail</th>
                        <th>IP</th>
                        <th>Location</th>
                        <th>User agent</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($events as $ev)
                    <tr>
                        <td><small>{{ $ev->created_at?->format('Y-m-d H:i:s') }}</small></td>
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
                                    $rp = is_array($ev->raw_payload) ? $ev->raw_payload : (json_decode($ev->raw_payload ?? '', true) ?: []);
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
                        <td><small><code>{{ $ev->ip_address ?: '—' }}</code></small></td>
                        <td>
                            @if ($ev->country_code)
                                <small title="{{ $ev->country_name }}">
                                    {{ \App\Services\EmailMarketing\GeoIpLookup::flagEmoji($ev->country_code) }}
                                    {{ $ev->country_code }} — {{ $ev->country_name }}
                                </small>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td class="text-truncate" style="max-width: 320px;">
                            <small class="text-muted" title="{{ $ev->user_agent }}">{{ \Illuminate\Support\Str::limit($ev->user_agent ?? '—', 60) }}</small>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No events recorded for this recipient yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
