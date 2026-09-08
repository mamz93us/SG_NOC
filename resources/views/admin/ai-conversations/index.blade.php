@extends('layouts.admin')

@section('title', 'AI Assistant Conversations')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-chat-dots me-2 text-primary"></i>AI Assistant Conversations</h4>
        <small class="text-muted">Every chat thread on the home portal's AI Assistant. See <a href="{{ route('admin.ai-assistant.usage') }}">Usage &amp; Gaps</a> for token spend and unanswered questions.</small>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Employee</th>
                    <th>Title</th>
                    <th style="width:90px" class="text-center">Messages</th>
                    <th style="width:100px" class="text-center">Tokens</th>
                    <th style="width:160px">Last activity</th>
                    <th style="width:80px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($conversations as $c)
                    <tr>
                        <td>{{ $c->user?->name ?? '—' }}</td>
                        <td class="text-truncate" style="max-width:360px">{{ $c->title ?: '(untitled)' }}</td>
                        <td class="text-center small text-muted">{{ $c->message_count }}</td>
                        <td class="text-center small text-muted">{{ number_format($c->total_tokens) }}</td>
                        <td class="small text-muted">{{ $c->last_message_at?->diffForHumans() ?? '—' }}</td>
                        <td class="text-end">
                            <a href="{{ route('admin.ai-assistant.conversations.show', $c) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">No conversations yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($conversations->hasPages())
    <div class="mt-3">{{ $conversations->links() }}</div>
@endif
@endsection
