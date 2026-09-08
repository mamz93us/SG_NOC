@extends('layouts.admin')

@section('title', 'Conversation')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-chat-dots me-2 text-primary"></i>{{ $conversation->title ?: 'Conversation' }}</h4>
        <small class="text-muted">{{ $conversation->user?->name }} &middot; {{ $conversation->created_at?->format('d M Y H:i') }}</small>
    </div>
    <a href="{{ route('admin.ai-assistant.conversations.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        @foreach($messages as $m)
            @continue($m->role === \App\Models\AiMessage::ROLE_SYSTEM)
            <div class="mb-3 pb-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                <div class="d-flex justify-content-between">
                    <span class="badge {{ $m->role === 'user' ? 'bg-primary' : ($m->role === 'tool' ? 'bg-secondary' : 'bg-success') }}">
                        {{ ucfirst($m->role) }}
                    </span>
                    <span class="small text-muted">{{ $m->created_at?->format('H:i:s') }}</span>
                </div>
                @if($m->role === 'tool')
                    <pre class="small bg-light p-2 rounded mt-2 mb-0" style="white-space:pre-wrap">{{ $m->content }}</pre>
                @else
                    <div class="mt-1" style="white-space:pre-wrap">{{ $m->content }}</div>
                    @if($m->tool_calls)
                        <div class="small text-muted mt-1">
                            Tool calls: {{ collect($m->tool_calls)->pluck('function.name')->implode(', ') }}
                        </div>
                    @endif
                @endif
                @if($m->rating)
                    <div class="small mt-1">
                        <span class="badge {{ $m->rating > 0 ? 'bg-success' : 'bg-danger' }}">
                            {{ $m->rating > 0 ? 'Helpful' : 'Not helpful' }}
                        </span>
                        @if($m->rating_reason) <span class="text-muted">{{ $m->rating_reason }}</span> @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endsection
