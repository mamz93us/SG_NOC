@extends('layouts.home')

@section('title', __('home_ai.widget.title'))

@section('content')
<div class="assistant-page">
    @if(! $configured)
        <div class="assistant-page-empty">{{ __('home_ai.errors.system_unavailable') }}</div>
    @else
        @include('home.partials.assistant-chat')

        <div class="assistant-page-card">
            <div class="assistant-head">
                <span class="assistant-avatar lg" aria-hidden="true"><img src="{{ asset('images/brand/samir-mark.png') }}" alt=""></span>
                <div class="assistant-head-title">
                    <h2>{{ __('home_ai.widget.title') }}</h2>
                    <div class="assistant-status" id="assistantStatus" data-state="ready">
                        <span class="assistant-status-dot" aria-hidden="true"></span>
                        <span class="assistant-status-text">{{ __('home_ai.widget.online') }}</span>
                    </div>
                </div>
            </div>

            <div class="assistant-messages" id="assistantMessages" role="log" style="min-height:50vh; max-height:66vh;">
                @foreach($messages as $m)
                    {{-- A turn that only called tools is stored with no text: nothing to show. --}}
                    @continue(trim((string) $m->content) === '')
                    @if($m->role === 'user')
                        <div class="assistant-msg assistant-msg-user"><div class="assistant-bubble">{{ $m->content }}</div></div>
                    @else
                        <div class="assistant-msg assistant-msg-bot" data-message-id="{{ $m->id }}" data-rating="{{ $m->rating }}">
                            <span class="assistant-avatar" aria-hidden="true"><img src="{{ asset('images/brand/samir-mark.png') }}" alt=""></span>
                            <div class="assistant-msg-body"><div class="assistant-bubble">{{ $m->content }}</div></div>
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="field-error assistant-error" id="assistantError"></div>

            <div class="assistant-input-row">
                <textarea id="assistantInput" rows="1" placeholder="{{ __('home_ai.widget.placeholder') }}" maxlength="4000"></textarea>
                <button type="button" class="btn btn-primary" id="assistantSendBtn">{{ __('home_ai.widget.send') }}</button>
            </div>
            <p class="assistant-disclaimer">{{ __('home_ai.widget.disclaimer') }}</p>
        </div>
    @endif
</div>

<style>
  .assistant-page{ max-width:720px; margin:0 auto; padding:0 20px 40px; }
  .assistant-page-card{
    background:#fff; border:1px solid var(--line); border-radius:18px;
    display:flex; flex-direction:column; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,.06);
  }
  .assistant-page-empty{ padding:40px; text-align:center; color:var(--ink-soft); }
</style>

@if($configured)
@push('scripts')
<script>
(function () {
  'use strict';
  if (!window.SamirAssistant) return;

  var messages = document.getElementById('assistantMessages');
  var chat = window.SamirAssistant.chat({
    messages: messages,
    input: document.getElementById('assistantInput'),
    sendBtn: document.getElementById('assistantSendBtn'),
    errorEl: document.getElementById('assistantError'),
    statusEl: document.getElementById('assistantStatus'),
    conversationId: @json($conversation?->id),
  });

  if (!messages.children.length) chat.greet();
})();
</script>
@endpush
@endif
@endsection
