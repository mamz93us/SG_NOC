@extends('layouts.portal')

@section('title', 'Email Marketing')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row g-3 mb-4">
        @foreach ([
            ['label' => 'Subscribed', 'value' => $kpis['subscribers_active'], 'icon' => 'people-fill', 'color' => 'success'],
            ['label' => 'Pending opt-in', 'value' => $kpis['subscribers_pending'], 'icon' => 'hourglass-split', 'color' => 'warning'],
            ['label' => 'Lists', 'value' => $kpis['lists'], 'icon' => 'list-ul', 'color' => 'primary'],
            ['label' => 'Templates', 'value' => $kpis['templates'], 'icon' => 'file-earmark-text', 'color' => 'info'],
        ] as $kpi)
        <div class="col-md-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <small class="text-muted">{{ $kpi['label'] }}</small>
                    <h3 class="mb-0"><i class="bi bi-{{ $kpi['icon'] }} text-{{ $kpi['color'] }} me-2"></i>{{ number_format($kpi['value']) }}</h3>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="row g-3 mb-4">
        @foreach ([
            ['label' => 'Draft campaigns', 'value' => $kpis['campaigns_draft'], 'color' => 'secondary'],
            ['label' => 'Scheduled', 'value' => $kpis['campaigns_scheduled'], 'color' => 'warning'],
            ['label' => 'Sent', 'value' => $kpis['campaigns_sent'], 'color' => 'success'],
        ] as $kpi)
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <small class="text-muted">{{ $kpi['label'] }}</small>
                    <h3 class="mb-0 text-{{ $kpi['color'] }}">{{ $kpi['value'] }}</h3>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between">
            <strong>Recent campaigns</strong>
            <a href="{{ route('portal.marketing.campaigns.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus me-1"></i>New campaign
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th><th>List</th><th>Status</th><th>Sent</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($recentCampaigns as $c)
                    <tr>
                        <td><a href="{{ route('portal.marketing.campaigns.show', $c) }}">{{ $c->name }}</a></td>
                        <td>{{ $c->list?->name ?: '—' }}</td>
                        <td><span class="badge bg-secondary text-capitalize">{{ $c->status }}</span></td>
                        <td>{{ $c->sent_at?->diffForHumans() ?: '—' }}</td>
                        <td><a href="{{ route('portal.marketing.campaigns.analytics', $c) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-bar-chart"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No campaigns yet — <a href="{{ route('portal.marketing.campaigns.create') }}">create your first one</a>.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
