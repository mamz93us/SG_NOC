@extends('layouts.admin')

@section('title', 'Campaign Approvals')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-patch-check me-2"></i>Campaign Approvals</h3>
        <a href="{{ route('admin.email-marketing.settings') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-gear me-1"></i>Email Marketing Settings
        </a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">
            @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
        </ul></div>
    @endif

    <p class="text-muted">
        Campaigns with external recipients wait here until approved. Internal-only campaigns
        (every recipient on an internal domain) send without approval.
    </p>

    @if ($rows->isEmpty())
        <div class="card shadow-sm">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                No campaigns are awaiting approval.
            </div>
        </div>
    @else
        @foreach ($rows as $row)
            @php($c = $row['campaign'])
            @php($s = $row['summary'])
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <div>
                            <h5 class="mb-1">{{ $c->name }}</h5>
                            <div class="text-muted small mb-2">Subject: <strong>{{ $c->subject }}</strong></div>
                        </div>
                        <div class="text-end small text-muted">
                            Submitted {{ optional($c->submitted_for_approval_at)->diffForHumans() }}<br>
                            by {{ $c->creator->name ?? 'Unknown' }}
                        </div>
                    </div>

                    <div class="row g-3 small mb-3">
                        <div class="col-md-3">
                            <div class="text-muted">From</div>
                            <div>{{ $c->from_email }}</div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted">Recipients</div>
                            <div>
                                {{ number_format($s['total']) }}
                                <span class="badge bg-warning text-dark">{{ number_format($s['external_count']) }} external</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted">Send time</div>
                            <div>{{ optional($c->scheduled_at)->format('Y-m-d H:i') ?? 'Immediate' }}</div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-muted">Preview</div>
                            <a href="{{ \App\Support\Marketing::url('/campaigns/'.$c->id) }}" target="_blank" rel="noopener">
                                Open in portal <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </div>
                    </div>

                    @if (! empty($s['external_domains']))
                        <div class="mb-3 small">
                            <span class="text-muted">External domains:</span>
                            @foreach ($s['external_domains'] as $d)
                                <span class="badge bg-light text-dark border">{{ $d }}</span>
                            @endforeach
                        </div>
                    @endif

                    <div class="d-flex gap-2">
                        <form method="POST" action="{{ route('admin.email-marketing.approvals.approve', $c) }}">
                            @csrf
                            <button class="btn btn-success btn-sm" onclick="return confirm('Approve and send this campaign?')">
                                <i class="bi bi-check-lg me-1"></i>Approve &amp; send
                            </button>
                        </form>
                        <button class="btn btn-outline-danger btn-sm" type="button"
                                data-bs-toggle="collapse" data-bs-target="#reject-{{ $c->id }}">
                            <i class="bi bi-x-lg me-1"></i>Reject
                        </button>
                    </div>

                    <div class="collapse mt-3" id="reject-{{ $c->id }}">
                        <form method="POST" action="{{ route('admin.email-marketing.approvals.reject', $c) }}">
                            @csrf
                            <label class="form-label small fw-semibold">Reason for rejection (emailed to the creator)</label>
                            <textarea name="reason" class="form-control mb-2" rows="2" maxlength="1000" required
                                      placeholder="e.g. Remove the external partner list, or get sign-off first."></textarea>
                            <button class="btn btn-danger btn-sm">Reject &amp; return to draft</button>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
</div>
@endsection
