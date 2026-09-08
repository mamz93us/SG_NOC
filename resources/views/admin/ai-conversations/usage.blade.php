@extends('layouts.admin')

@section('title', 'AI Assistant Usage')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-graph-up me-2 text-primary"></i>AI Assistant Usage</h4>
        <small class="text-muted">Last 30 days. See <a href="{{ route('admin.ai-assistant.conversations.index') }}">Conversations</a> for individual transcripts.</small>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Conversations</div>
                <div class="fs-3 fw-bold">{{ number_format($totalConversations) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Tokens spent</div>
                <div class="fs-3 fw-bold">{{ number_format($totalTokens) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Open knowledge gaps</div>
                <div class="fs-3 fw-bold">{{ number_format($gaps->count()) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-exclamation-circle me-1 text-warning"></i>Knowledge gaps — what to write next
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Question</th><th style="width:80px" class="text-center">Hits</th><th style="width:140px">Last seen</th></tr>
                    </thead>
                    <tbody>
                        @forelse($gaps as $gap)
                            <tr>
                                <td class="small">{{ $gap->query_sample }}</td>
                                <td class="text-center"><span class="badge bg-warning text-dark">{{ $gap->hit_count }}</span></td>
                                <td class="small text-muted">{{ $gap->last_seen_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-4">No unanswered questions recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-hand-thumbs-down me-1 text-danger"></i>Recent "not helpful" ratings
            </div>
            <div class="list-group list-group-flush">
                @forelse($thumbsDown as $m)
                    <a href="{{ route('admin.ai-assistant.conversations.show', $m->conversation_id) }}"
                       class="list-group-item list-group-item-action">
                        <div class="small text-muted">{{ $m->conversation?->user?->name }} &middot; {{ $m->created_at?->diffForHumans() }}</div>
                        <div class="text-truncate">{{ $m->content }}</div>
                        @if($m->rating_reason)
                            <div class="small text-muted">{{ $m->rating_reason }}</div>
                        @endif
                    </a>
                @empty
                    <div class="list-group-item text-center text-muted py-4">Nothing rated poorly yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
