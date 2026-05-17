@extends('layouts.portal')

@section('title', 'Campaigns')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="d-flex justify-content-end mb-3">
        <a href="{{ route('portal.marketing.campaigns.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus me-1"></i>New campaign
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th><th>Subject</th><th>List / Segment</th><th>Status</th><th>Schedule</th><th>Sent</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($campaigns as $c)
                    <tr>
                        <td><a href="{{ route('portal.marketing.campaigns.show', $c) }}"><strong>{{ $c->name }}</strong></a></td>
                        <td><small>{{ $c->subject }}</small></td>
                        <td>{{ $c->list?->name ?: ($c->segment_id ? 'Segment #' . $c->segment_id : '—') }}</td>
                        <td>
                            <span class="badge bg-{{ match($c->status) {
                                'sent' => 'success',
                                'sending' => 'primary',
                                'scheduled' => 'warning',
                                'paused' => 'secondary',
                                'failed' => 'danger',
                                default => 'light text-dark',
                            } }} text-capitalize">{{ $c->status }}</span>
                        </td>
                        <td><small>{{ $c->scheduled_at?->format('Y-m-d H:i') ?: '—' }}</small></td>
                        <td><small>{{ $c->total_sent }} / {{ $c->total_recipients }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No campaigns yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $campaigns->links() }}</div>
    </div>
</div>
@endsection
