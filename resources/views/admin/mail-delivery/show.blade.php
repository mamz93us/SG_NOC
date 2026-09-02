@extends('layouts.admin')

@section('title', 'Message — ' . Str::limit($message->subject ?: 'no subject', 40))

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-1">
                {{ $message->subject ?: '(no subject)' }}
                <span class="badge {{ $message->statusBadgeClass() }} align-middle">{{ $message->status }}</span>
            </h4>
            <div class="small text-muted">
                {{ $message->sent_at?->format('Y-m-d H:i:s') ?? 'unknown time' }}
                · <span class="badge {{ $message->sourceBadgeClass() }}">{{ $message->source }}</span>
            </div>
        </div>
        <a href="{{ route('admin.mail-delivery.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to log
        </a>
    </div>

    @if(in_array($message->status, ['bounced', 'complained', 'rejected', 'failed'], true))
        <div class="alert alert-danger py-2 small">
            <strong>
                @if($message->status === 'bounced')
                    Bounced{{ $message->bounce_type ? " — {$message->bounce_type}" : '' }}{{ $message->bounce_subtype ? " / {$message->bounce_subtype}" : '' }}
                @elseif($message->status === 'complained')
                    Marked as spam{{ $message->complaint_type ? " — {$message->complaint_type}" : '' }}
                @else
                    {{ ucfirst($message->status) }}
                @endif
            </strong>
            @if($message->failure_reason)
                <div class="font-monospace mt-1">{{ $message->failure_reason }}</div>
            @endif
            @if($message->bounce_type === 'Permanent')
                <div class="mt-1">A permanent bounce normally means the address does not exist. SES may suppress it on future sends.</div>
            @endif
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent"><strong class="small text-uppercase text-muted">Message</strong></div>
                <div class="card-body">
                    <dl class="row small mb-0">
                        <dt class="col-4 text-muted">From</dt>
                        <dd class="col-8">{{ $message->fromLabel() ?: '—' }}</dd>

                        <dt class="col-4 text-muted">To</dt>
                        <dd class="col-8">
                            @forelse(explode(', ', (string) $message->recipients) as $recipient)
                                @if($recipient !== '')<div>{{ $recipient }}</div>@endif
                            @empty
                                {{ $message->to_email ?: '—' }}
                            @endforelse
                        </dd>

                        <dt class="col-4 text-muted">Subject</dt>
                        <dd class="col-8">{{ $message->subject ?: '(none)' }}</dd>

                        <dt class="col-4 text-muted">Sent</dt>
                        <dd class="col-8">{{ $message->sent_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>

                        <dt class="col-4 text-muted">Delivered</dt>
                        <dd class="col-8">{{ $message->delivered_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>

                        <dt class="col-4 text-muted">Opens</dt>
                        <dd class="col-8">
                            {{ $message->open_count }}
                            @if($message->opened_at)
                                <span class="text-muted">(first {{ $message->opened_at->format('Y-m-d H:i') }})</span>
                            @endif
                        </dd>

                        <dt class="col-4 text-muted">Clicks</dt>
                        <dd class="col-8">{{ $message->click_count }}</dd>

                        @if($message->campaignSend)
                        <dt class="col-4 text-muted">Campaign</dt>
                        <dd class="col-8">send #{{ $message->email_campaign_send_id }}</dd>
                        @endif

                        <dt class="col-4 text-muted">SES id</dt>
                        <dd class="col-8"><code class="small">{{ $message->ses_message_id }}</code></dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent">
                    <strong class="small text-uppercase text-muted">SES event timeline</strong>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:160px;">When</th>
                                    <th style="width:120px;">Event</th>
                                    <th>Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($events as $event)
                                <tr>
                                    <td class="small text-muted text-nowrap">{{ $event->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td><span class="badge bg-secondary">{{ $event->event_type }}</span></td>
                                    <td class="small">
                                        @if($event->event_type === 'Click' && $event->url)
                                            <div class="text-truncate" style="max-width:340px" title="{{ $event->url }}">{{ $event->url }}</div>
                                        @endif
                                        @if($event->bounce_type)
                                            {{ $event->bounce_type }}{{ $event->bounce_subtype ? " / {$event->bounce_subtype}" : '' }}
                                        @endif
                                        @if($event->complaint_type)
                                            {{ $event->complaint_type }}
                                        @endif
                                        @if($event->ip_address)
                                            <span class="text-muted">{{ $event->ip_address }}</span>
                                            @if($event->country_name)<span class="text-muted">· {{ $event->country_name }}</span>@endif
                                        @endif
                                        @if($event->user_agent)
                                            <div class="text-muted text-truncate" style="max-width:340px" title="{{ $event->user_agent }}">
                                                {{ $event->user_agent }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted py-4">No events stored for this message.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <strong class="small text-uppercase text-muted">Raw SES payload</strong>
            <button class="btn btn-sm btn-outline-secondary" type="button"
                    data-bs-toggle="collapse" data-bs-target="#rawPayload">Show / hide</button>
        </div>
        <div class="collapse" id="rawPayload">
            <div class="card-body p-0">
                <pre class="mb-0 p-3 small" style="max-height:50vh; overflow:auto;">{{ json_encode(optional($events->last())->raw_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        </div>
    </div>
</div>
@endsection
