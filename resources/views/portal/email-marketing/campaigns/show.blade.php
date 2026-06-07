@extends('layouts.marketing')

@section('title', $campaign->name)

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
    @endif

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-1">{{ $campaign->name }}</h4>
            <small class="text-muted">{{ $campaign->subject }}</small>
        </div>
        <div>
            <span class="badge bg-{{ match($campaign->status) {
                'sent' => 'success', 'sending' => 'primary',
                'scheduled' => 'warning', 'paused' => 'secondary',
                'pending_approval' => 'info', 'failed' => 'danger',
                default => 'light text-dark',
            } }} text-capitalize fs-6">{{ str_replace('_', ' ', $campaign->status) }}</span>
        </div>
    </div>

    @if ($campaign->isPendingApproval())
        <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <i class="bi bi-hourglass-split me-1"></i>
                <strong>Awaiting IT approval.</strong>
                This campaign has external recipients, so it was submitted to IT and will send once approved@if($campaign->scheduled_at) ({{ $campaign->scheduled_at->format('Y-m-d H:i') }})@endif.
            </div>
            <form method="POST" action="{{ route('portal.marketing.campaigns.recall', $campaign) }}"
                  onsubmit="return confirm('Recall this campaign back to draft?')">
                @csrf
                <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Recall to draft</button>
            </form>
        </div>
    @elseif ($campaign->wasRejected())
        <div class="alert alert-danger">
            <i class="bi bi-x-octagon me-1"></i>
            <strong>Not approved.</strong> {{ $campaign->rejection_reason }}
            <div class="small text-muted mt-1">Update the campaign and send again to resubmit for approval.</div>
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><small class="text-muted">Recipients</small><h4>{{ $campaign->total_recipients }}</h4></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><small class="text-muted">Sent</small><h4>{{ $campaign->total_sent }}</h4></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><small class="text-muted">Delivered</small><h4>{{ $campaign->total_delivered }}</h4></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm"><div class="card-body"><small class="text-muted">Bounces</small><h4>{{ $campaign->total_bounces }}</h4></div></div></div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light"><strong>Details</strong></div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-md-3">From</dt>
                <dd class="col-md-9">{{ $campaign->from_name }} &lt;{{ $campaign->from_email }}&gt;</dd>
                <dt class="col-md-3">Reply-to</dt>
                <dd class="col-md-9">{{ $campaign->reply_to ?: '—' }}</dd>
                <dt class="col-md-3">List / Segment</dt>
                <dd class="col-md-9">{{ $campaign->list?->name ?: ($campaign->segment?->name ?: '—') }}</dd>
                <dt class="col-md-3">Template</dt>
                <dd class="col-md-9">{{ $campaign->template?->name ?: '—' }}</dd>
                <dt class="col-md-3">Scheduled</dt>
                <dd class="col-md-9">{{ $campaign->scheduled_at?->format('Y-m-d H:i') ?: '—' }}</dd>
                <dt class="col-md-3">Sent</dt>
                <dd class="col-md-9">{{ $campaign->sent_at?->format('Y-m-d H:i') ?: '—' }}</dd>
            </dl>
        </div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        @if ($campaign->isEditable())
            <a href="{{ route('portal.marketing.campaigns.edit', $campaign) }}" class="btn btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            <form method="POST" action="{{ route('portal.marketing.campaigns.send-now', $campaign) }}"
                  onsubmit="return confirm('{{ ($needsApproval ?? false) ? 'Submit this campaign to IT for approval?' : 'Send this campaign immediately?' }}')">
                @csrf
                <button class="btn btn-primary">
                    <i class="bi bi-send me-1"></i>{{ ($needsApproval ?? false) ? 'Submit for approval' : 'Send now' }}
                </button>
            </form>
            @if ($needsApproval ?? false)
                <span class="align-self-center text-muted small">
                    <i class="bi bi-shield-lock me-1"></i>External recipients — sending needs IT approval.
                </span>
            @endif
        @endif
        @if (in_array($campaign->status, ['scheduled', 'sending']))
            <form method="POST" action="{{ route('portal.marketing.campaigns.pause', $campaign) }}">
                @csrf <button class="btn btn-outline-warning"><i class="bi bi-pause-fill me-1"></i>Pause</button>
            </form>
        @endif
        <form method="POST" action="{{ route('portal.marketing.campaigns.duplicate', $campaign) }}">
            @csrf <button class="btn btn-outline-secondary"><i class="bi bi-files me-1"></i>Duplicate</button>
        </form>
        <a href="{{ route('portal.marketing.campaigns.analytics', $campaign) }}" class="btn btn-outline-info">
            <i class="bi bi-bar-chart me-1"></i>Analytics
        </a>
        @if (in_array($campaign->status, ['draft', 'paused', 'failed']))
            <form method="POST" action="{{ route('portal.marketing.campaigns.destroy', $campaign) }}"
                  onsubmit="return confirm('Delete this campaign?')">
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger ms-auto"><i class="bi bi-trash me-1"></i>Delete</button>
            </form>
        @endif
    </div>

    {{-- ── Send test email to an arbitrary address ───────────────── --}}
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light">
            <strong><i class="bi bi-send-check me-1"></i>Send test email</strong>
            <small class="text-muted ms-2">Renders the template with placeholder data and sends only to this address — doesn't touch real recipients or analytics.</small>
        </div>
        <form class="card-body" method="POST" action="{{ route('portal.marketing.campaigns.test-send', $campaign) }}">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Recipient email</label>
                    <input type="email" name="to" class="form-control" required
                           placeholder="you@samirgroup.com"
                           value="{{ old('to', auth()->user()->email) }}">
                </div>
                <div class="col-md-3">
                    <button class="btn btn-outline-primary w-100">
                        <i class="bi bi-send me-1"></i>Send test
                    </button>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">Subject is prefixed <code>[TEST]</code>. Recipient must be verified if SES is in sandbox.</small>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
