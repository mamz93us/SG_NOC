@extends('layouts.home')

@section('title', __('home_ai.widget.title'))

@section('content')
<div class="assistant-page">
    <p class="section-label">{{ __('home_ai.widget.title') }}</p>

    @if(! $configured)
        <div class="assistant-page-empty">{{ __('home_ai.errors.system_unavailable') }}</div>
    @else
        <div class="assistant-page-card">
            <div class="assistant-messages" id="assistantMessages" style="min-height:50vh; max-height:66vh;">
                @forelse($messages as $m)
                    <div class="assistant-msg {{ $m->role === 'user' ? 'assistant-msg-user' : 'assistant-msg-bot' }}">
                        <div class="assistant-bubble">{{ $m->content }}</div>
                    </div>
                @empty
                    <div class="assistant-msg assistant-msg-bot">
                        <div class="assistant-bubble">{{ __('home_ai.widget.title') }} 👋</div>
                    </div>
                @endforelse
            </div>

            <div class="assistant-typing" id="assistantTyping" hidden>{{ __('home_ai.widget.thinking') }}</div>
            <div class="field-error" id="assistantError"></div>

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

  /* Shared with home.partials.assistant-widget — see that file if editing. */
  .assistant-messages{ flex:1; overflow-y:auto; padding:18px 20px; display:flex; flex-direction:column; gap:12px; }
  .assistant-msg{ display:flex; }
  .assistant-msg-user{ justify-content:flex-end; }
  .assistant-bubble{
    max-width:82%; padding:10px 14px; border-radius:14px; font-size:13.5px; line-height:1.5;
    white-space:pre-wrap; word-break:break-word;
  }
  .assistant-msg-bot .assistant-bubble{ background:var(--bg); color:var(--ink); border-bottom-left-radius:4px; }
  .assistant-msg-user .assistant-bubble{ background:var(--red-600); color:#fff; border-bottom-right-radius:4px; }
  html[dir="rtl"] .assistant-msg-bot .assistant-bubble{ border-bottom-left-radius:14px; border-bottom-right-radius:4px; }
  html[dir="rtl"] .assistant-msg-user .assistant-bubble{ border-bottom-right-radius:14px; border-bottom-left-radius:4px; }

  .assistant-rating{ display:flex; gap:6px; margin-top:6px; }
  .assistant-rating button{
    border:1px solid var(--line); background:#fff; border-radius:8px; padding:3px 8px;
    font-size:11.5px; color:var(--ink-soft); cursor:pointer;
  }
  .assistant-rating button.active{ background:var(--bg); color:var(--ink); }

  .assistant-typing{ padding:0 20px 6px; font-size:12.5px; color:var(--ink-soft); }

  .assistant-input-row{ display:flex; gap:10px; padding:14px 20px; border-top:1px solid var(--line); align-items:flex-end; }
  .assistant-input-row textarea{
    flex:1; resize:none; max-height:120px; font-family:var(--font-sans); font-size:13.5px;
    border:1px solid var(--line); border-radius:10px; padding:10px 12px; color:var(--ink);
  }
  .assistant-input-row textarea:focus{ outline:none; border-color:var(--red-600); box-shadow:0 0 0 3px var(--red-100); }
  .assistant-disclaimer{ font-size:11px; color:var(--ink-soft); text-align:center; padding:0 20px 14px; }

  .assistant-draft-card{
    background:var(--bg); border:1px solid var(--line); border-radius:12px; padding:14px; font-size:13px; max-width:92%;
  }
  .assistant-draft-card h4{ font-size:13.5px; font-weight:700; color:var(--ink); margin-bottom:6px; }
  .assistant-draft-card .reason{ color:var(--ink-soft); font-size:12px; margin-bottom:10px; }
  .assistant-draft-card dl{ display:grid; grid-template-columns:auto 1fr; gap:4px 10px; margin-bottom:12px; }
  .assistant-draft-card dt{ color:var(--ink-soft); }
  .assistant-draft-card dd{ margin:0; color:var(--ink); }
  .assistant-draft-actions{ display:flex; gap:8px; }
  .assistant-draft-actions button{ font-size:12.5px; padding:7px 12px; }
</style>

@if($configured)
@push('scripts')
<script>
(function () {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  var messageUrl = @json(route('home.assistant.message'));
  var ticketUrl = @json(route('home.assistant.ticket'));
  var rateUrlTemplate = @json(route('home.assistant.rate', ['message' => '__ID__']));
  var conversationId = @json($conversation?->id);

  var i18n = {
    sendFailed: @json(__('home_ai.errors.send_failed')),
    ticketDraftHeading: @json(__('home_ai.ticket_draft.heading')),
    checkedLabel: @json(__('home_ai.ticket_draft.checked_label')),
    fieldTitle: @json(__('home_ai.ticket_draft.fields.title')),
    fieldDescription: @json(__('home_ai.ticket_draft.fields.description')),
    fieldCategory: @json(__('home_ai.ticket_draft.fields.category')),
    fieldSubcategory: @json(__('home_ai.ticket_draft.fields.subcategory')),
    send: @json(__('home_ai.ticket_draft.send')),
    sending: @json(__('home_ai.ticket_draft.sending')),
    cancel: @json(__('home_ai.ticket_draft.cancel')),
    sentHeading: @json(__('home_ai.ticket_draft.sent_heading')),
    sentBody: @json(__('home_ai.ticket_draft.sent_body')),
    sendFailedTicket: @json(__('home_ai.ticket_draft.send_failed')),
    helpful: @json(__('home_ai.rating.helpful')),
    notHelpful: @json(__('home_ai.rating.not_helpful')),
  };

  var messages = document.getElementById('assistantMessages');
  var typing = document.getElementById('assistantTyping');
  var errorEl = document.getElementById('assistantError');
  var input = document.getElementById('assistantInput');
  var sendBtn = document.getElementById('assistantSendBtn');

  function setError(msg) { errorEl.textContent = msg || ''; }
  function scrollToBottom() { messages.scrollTop = messages.scrollHeight; }

  function addBubble(role, text) {
    var row = document.createElement('div');
    row.className = 'assistant-msg ' + (role === 'user' ? 'assistant-msg-user' : 'assistant-msg-bot');
    var bubble = document.createElement('div');
    bubble.className = 'assistant-bubble';
    bubble.textContent = text;
    row.appendChild(bubble);
    messages.appendChild(row);
    scrollToBottom();
    return row;
  }

  function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = String(s == null ? '' : s);
    return div.innerHTML;
  }

  function addRating(row, messageId) {
    var wrap = document.createElement('div');
    wrap.className = 'assistant-rating';
    var up = document.createElement('button');
    up.type = 'button';
    up.textContent = '👍 ' + i18n.helpful;
    var down = document.createElement('button');
    down.type = 'button';
    down.textContent = '👎 ' + i18n.notHelpful;

    function rate(value, btn) {
      up.classList.remove('active');
      down.classList.remove('active');
      btn.classList.add('active');
      fetch(rateUrlTemplate.replace('__ID__', messageId), {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ rating: value })
      }).catch(function () {});
    }

    up.addEventListener('click', function () { rate(1, up); });
    down.addEventListener('click', function () { rate(-1, down); });
    wrap.appendChild(up);
    wrap.appendChild(down);
    row.appendChild(wrap);
  }

  function addDraftCard(draft) {
    var row = document.createElement('div');
    row.className = 'assistant-msg assistant-msg-bot';
    var card = document.createElement('div');
    card.className = 'assistant-draft-card';
    card.innerHTML =
      '<h4>' + i18n.ticketDraftHeading + '</h4>' +
      '<div class="reason">' + i18n.checkedLabel + ' ' + escapeHtml(draft.reason_not_solved || '') + '</div>' +
      '<dl>' +
        '<dt>' + i18n.fieldTitle + '</dt><dd>' + escapeHtml(draft.title || '') + '</dd>' +
        '<dt>' + i18n.fieldCategory + '</dt><dd>' + escapeHtml(draft.category_name || '') + '</dd>' +
        '<dt>' + i18n.fieldSubcategory + '</dt><dd>' + escapeHtml(draft.subcategory_name || '') + '</dd>' +
        '<dt>' + i18n.fieldDescription + '</dt><dd>' + escapeHtml(draft.description || '') + '</dd>' +
      '</dl>';

    var actions = document.createElement('div');
    actions.className = 'assistant-draft-actions';
    var sendTicketBtn = document.createElement('button');
    sendTicketBtn.type = 'button';
    sendTicketBtn.className = 'btn btn-primary';
    sendTicketBtn.textContent = i18n.send;
    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'btn btn-ghost';
    cancelBtn.textContent = i18n.cancel;

    cancelBtn.addEventListener('click', function () { actions.remove(); });

    sendTicketBtn.addEventListener('click', function () {
      sendTicketBtn.disabled = true;
      cancelBtn.disabled = true;
      sendTicketBtn.textContent = i18n.sending;

      fetch(ticketUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          title: draft.title,
          description: draft.description,
          category_id: draft.category_id,
          subcategory_id: draft.subcategory_id,
        })
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
          if (!res.ok) { throw new Error(res.data.message || i18n.sendFailedTicket); }
          actions.remove();
          var confirmEl = document.createElement('div');
          confirmEl.className = 'reason';
          confirmEl.style.marginTop = '8px';
          confirmEl.textContent = i18n.sentHeading + ' — ' + i18n.sentBody.replace(':reference', res.data.reference || '—');
          card.appendChild(confirmEl);
        })
        .catch(function (err) {
          sendTicketBtn.disabled = false;
          cancelBtn.disabled = false;
          sendTicketBtn.textContent = i18n.send;
          setError(err.message || i18n.sendFailedTicket);
        });
    });

    actions.appendChild(sendTicketBtn);
    actions.appendChild(cancelBtn);
    card.appendChild(actions);
    row.appendChild(card);
    messages.appendChild(row);
    scrollToBottom();
  }

  function send() {
    var text = input.value.trim();
    if (!text) return;

    setError('');
    addBubble('user', text);
    input.value = '';
    input.style.height = 'auto';
    sendBtn.disabled = true;
    typing.hidden = false;

    fetch(messageUrl, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ conversation_id: conversationId, message: text })
    })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
      .then(function (res) {
        typing.hidden = true;
        sendBtn.disabled = false;
        if (!res.ok) { throw new Error(res.data.message || i18n.sendFailed); }

        conversationId = res.data.conversation_id;
        var row = addBubble('bot', res.data.message.content || '');
        if (res.data.message.id) addRating(row, res.data.message.id);
        if (res.data.draft_ticket) addDraftCard(res.data.draft_ticket);
      })
      .catch(function (err) {
        typing.hidden = true;
        sendBtn.disabled = false;
        setError(err.message || i18n.sendFailed);
      });
  }

  scrollToBottom();
  sendBtn.addEventListener('click', send);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });
  input.addEventListener('input', function () {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
  });
})();
</script>
@endpush
@endif
@endsection
