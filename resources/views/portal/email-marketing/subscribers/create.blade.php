@extends('layouts.portal')

@section('title', $subscriber->exists ? 'Edit subscriber' : 'New subscriber')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <form class="card shadow-sm"
          method="POST"
          action="{{ $subscriber->exists ? route('portal.marketing.subscribers.update', $subscriber) : route('portal.marketing.subscribers.store') }}">
        @csrf
        @if ($subscriber->exists) @method('PUT') @endif

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required
                           value="{{ old('email', $subscriber->email) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">First name</label>
                    <input type="text" name="first_name" class="form-control"
                           value="{{ old('first_name', $subscriber->first_name) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Last name</label>
                    <input type="text" name="last_name" class="form-control"
                           value="{{ old('last_name', $subscriber->last_name) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        @foreach (['pending','subscribed','unsubscribed','bounced','complained'] as $st)
                            <option value="{{ $st }}" @selected(old('status', $subscriber->status ?: 'subscribed') === $st)>{{ $st }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-9">
                    <label class="form-label">Lists</label>
                    <select name="list_ids[]" class="form-select" multiple size="4">
                        @foreach ($lists as $l)
                            <option value="{{ $l->id }}"
                                @selected(in_array($l->id, old('list_ids', $subscriber->lists?->pluck('id')->all() ?? [])))>{{ $l->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Tags</label>
                    <select name="tag_ids[]" class="form-select" multiple size="3">
                        @foreach ($tags as $t)
                            <option value="{{ $t->id }}"
                                @selected(in_array($t->id, old('tag_ids', $subscriber->tags?->pluck('id')->all() ?? [])))>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <a href="{{ route('portal.marketing.subscribers.index') }}" class="btn btn-link">Cancel</a>
            <div>
                @if ($subscriber->exists)
                    <form method="POST" action="{{ route('portal.marketing.subscribers.destroy', $subscriber) }}" class="d-inline"
                          onsubmit="return confirm('Delete this subscriber permanently?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                @endif
                <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save</button>
            </div>
        </div>
    </form>

    @if ($subscriber->exists)
        @php
            $history      = $history      ?? collect();
            $recentEvents = $recentEvents ?? collect();
            $aggDelivered = $history->where('send_status', 'delivered')->count();
            $aggBounced   = $history->where('send_status', 'bounced')->count();
            $aggOpens     = (int) $history->sum('opens');
            $aggClicks    = (int) $history->sum('clicks');
        @endphp

        {{-- ── KPI row: lifetime totals across all campaigns ─────── --}}
        <div class="row g-3 mt-4">
            <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
                <small class="text-muted">Campaigns received</small>
                <h4 class="mb-0">{{ $history->count() }}</h4>
            </div></div></div>
            <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
                <small class="text-muted">Delivered / Bounced</small>
                <h4 class="mb-0"><span class="text-success">{{ $aggDelivered }}</span> / <span class="text-danger">{{ $aggBounced }}</span></h4>
            </div></div></div>
            <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
                <small class="text-muted">Total opens</small>
                <h4 class="mb-0">{{ $aggOpens }}</h4>
            </div></div></div>
            <div class="col-md-3"><div class="card shadow-sm"><div class="card-body">
                <small class="text-muted">Total clicks</small>
                <h4 class="mb-0">{{ $aggClicks }}</h4>
            </div></div></div>
        </div>

        {{-- ── Per-campaign history ───────────────────────────────── --}}
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-light">
                <strong><i class="bi bi-megaphone me-1"></i>Campaign history</strong>
                <small class="text-muted ms-2">Every campaign this subscriber has received — click any campaign to drill into the full event log.</small>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Campaign</th>
                            <th>Subject</th>
                            <th>From</th>
                            <th>Status</th>
                            <th class="text-end">Opens</th>
                            <th class="text-end">Clicks</th>
                            <th>Last activity</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($history as $h)
                        <tr>
                            <td>
                                <a href="{{ route('portal.marketing.campaigns.show', $h->campaign_id) }}"><strong>{{ $h->campaign_name }}</strong></a>
                                @if ($h->campaign_archived_at)
                                    <span class="badge bg-light text-muted ms-1"><i class="bi bi-archive"></i> archived</span>
                                @endif
                                <br><small class="text-muted">Sent {{ optional($h->sent_at ? \Carbon\Carbon::parse($h->sent_at) : null)?->diffForHumans() ?: '—' }}</small>
                            </td>
                            <td><small>{{ \Illuminate\Support\Str::limit($h->campaign_subject, 60) }}</small></td>
                            <td><small class="text-muted">{{ $h->from_email }}</small></td>
                            <td>
                                <span class="badge bg-{{ match($h->send_status) {
                                    'delivered' => 'success',
                                    'sent' => 'primary',
                                    'bounced' => 'danger',
                                    'complained' => 'warning',
                                    'suppressed' => 'secondary',
                                    'failed' => 'dark',
                                    default => 'light text-dark',
                                } }}">{{ $h->send_status }}</span>
                                @if ($h->error_message)
                                    <br><small class="text-danger" title="{{ $h->error_message }}">{{ \Illuminate\Support\Str::limit($h->error_message, 40) }}</small>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($h->opens > 0)<span class="badge bg-info">{{ $h->opens }}</span>@else<small class="text-muted">—</small>@endif
                            </td>
                            <td class="text-end">
                                @if ($h->clicks > 0)<span class="badge bg-success">{{ $h->clicks }}</span>@else<small class="text-muted">—</small>@endif
                            </td>
                            <td><small>{{ $h->last_activity ? \Carbon\Carbon::parse($h->last_activity)->diffForHumans() : '—' }}</small></td>
                            <td class="text-end">
                                <a href="{{ route('portal.marketing.campaigns.analytics.recipient', ['campaign' => $h->campaign_id, 'send' => $h->send_id]) }}"
                                   class="btn btn-sm btn-outline-secondary" title="Full event log for this campaign + recipient">
                                    <i class="bi bi-list-ul"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No campaign sends recorded for this subscriber yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Cross-campaign event timeline (last 100) ──────────── --}}
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-light">
                <strong><i class="bi bi-list-ul me-1"></i>Event timeline</strong>
                <small class="text-muted ms-2">Every open, click, delivery, bounce, complaint across all campaigns — most recent first, capped at 100.</small>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 170px;">When</th>
                            <th>Campaign</th>
                            <th>Event</th>
                            <th>URL / Detail</th>
                            <th>IP</th>
                            <th>Location</th>
                            <th>User agent</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($recentEvents as $ev)
                        <tr>
                            <td><small>{{ \Carbon\Carbon::parse($ev->created_at)->format('Y-m-d H:i:s') }}</small></td>
                            <td>
                                <a href="{{ route('portal.marketing.campaigns.analytics.recipient', ['campaign' => $ev->campaign_id, 'send' => $ev->send_id]) }}">
                                    <small>{{ \Illuminate\Support\Str::limit($ev->campaign_name, 35) }}</small>
                                </a>
                            </td>
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
                            <td class="text-truncate" style="max-width: 280px;">
                                @if ($ev->event_type === 'Click' && $ev->url)
                                    <a href="{{ $ev->url }}" target="_blank" rel="noopener" title="{{ $ev->url }}"><small>{{ $ev->url }}</small></a>
                                @elseif ($ev->event_type === 'Bounce')
                                    @php
                                        $rp = is_array($ev->raw_payload) ? $ev->raw_payload : (json_decode($ev->raw_payload ?? '', true) ?: []);
                                        $br = ($rp['bounce']['bouncedRecipients'] ?? [[]])[0] ?? [];
                                        $diag = $br['diagnosticCode'] ?? null;
                                    @endphp
                                    <small class="text-danger d-block">{{ $ev->bounce_type }}{{ $ev->bounce_subtype ? ' / '.$ev->bounce_subtype : '' }}</small>
                                    @if ($diag)<small class="text-muted">{{ \Illuminate\Support\Str::limit($diag, 60) }}</small>@endif
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
                                        {{ $ev->country_code }}
                                    </small>
                                @else
                                    <small class="text-muted">—</small>
                                @endif
                            </td>
                            <td class="text-truncate" style="max-width: 260px;">
                                <small class="text-muted" title="{{ $ev->user_agent }}">{{ \Illuminate\Support\Str::limit($ev->user_agent ?? '—', 60) }}</small>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No events recorded yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
