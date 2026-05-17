@extends('layouts.admin')

@section('title', 'Email Marketing — Quota')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>Email Marketing — Quota & Status</h3>
        <a href="{{ route('admin.email-marketing.settings') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Settings
        </a>
    </div>

    @if ($error)
        <div class="alert alert-warning">{{ $error }}</div>
    @endif

    @if ($quota)
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <small class="text-muted">24-hour sending quota</small>
                        <h3 class="mb-0">{{ number_format($quota['Max24HourSend']) }}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <small class="text-muted">Sent in last 24 h</small>
                        <h3 class="mb-0">{{ number_format($quota['SentLast24Hours']) }}</h3>
                        @php $pct = $quota['Max24HourSend'] > 0 ? round($quota['SentLast24Hours'] / $quota['Max24HourSend'] * 100) : 0; @endphp
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar {{ $pct > 80 ? 'bg-danger' : 'bg-success' }}" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <small class="text-muted">Max send rate (per second)</small>
                        <h3 class="mb-0">{{ number_format($quota['MaxSendRate'], 1) }}</h3>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light"><strong>Campaigns by status</strong></div>
                <div class="card-body">
                    @forelse ($campaignCounts as $status => $count)
                        <div class="d-flex justify-content-between border-bottom py-1">
                            <span class="text-capitalize">{{ $status }}</span>
                            <strong>{{ $count }}</strong>
                        </div>
                    @empty
                        <div class="text-muted">No campaigns yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-light"><strong>Subscribers by status</strong></div>
                <div class="card-body">
                    @foreach ($subscribers as $status => $count)
                        <div class="d-flex justify-content-between border-bottom py-1">
                            <span class="text-capitalize">{{ $status }}</span>
                            <strong>{{ $count }}</strong>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
