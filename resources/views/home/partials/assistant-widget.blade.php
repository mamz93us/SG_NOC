{{--
    AI IT Assistant — floating chat widget.

    Posts to home.assistant.message, which runs AssistantAgent against the
    signed-in employee's own scoped tools (AssistantToolbox) and the
    knowledge base. A ticket is only ever DRAFTED here — nothing reaches the
    ticketing system until the employee reviews the card and taps
    "Send to IT" (home.assistant.ticket), which runs through the same
    HomeTicketSubmitter as the IT Service Desk modal.

    Kept a self-contained session (no server-side conversation restore): the
    full page at /assistant is where a longer, resumable history lives.
--}}
<button type="button" class="assistant-fab" id="assistantFab" aria-label="{{ __('home_ai.widget.launcher_label') }}">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path d="M12 3a7 7 0 0 0-7 7v3.6L3.6 17a1 1 0 0 0 .9 1.5H12a7 7 0 0 0 0-14Z" stroke-linejoin="round"/>
        <circle cx="9" cy="10" r=".9" fill="currentColor" stroke="none"/>
        <circle cx="12" cy="10" r=".9" fill="currentColor" stroke="none"/>
        <circle cx="15" cy="10" r=".9" fill="currentColor" stroke="none"/>
    </svg>
</button>

<div class="modal-overlay" id="assistantModalOverlay" aria-hidden="true">
    <div class="assistant-modal" role="dialog" aria-modal="true" aria-labelledby="assistantModalTitle">
        <div class="ticket-modal-header">
            <div class="ticket-modal-header-left">
                <button type="button" class="icon-btn" id="assistantCloseBtn" aria-label="{{ __('home_ticket_modal.close') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <h2 id="assistantModalTitle">{{ __('home_ai.widget.title') }}</h2>
            </div>
            <div class="ticket-modal-header-right">
                <a class="btn btn-ghost assistant-link-btn" href="{{ route('home.assistant.index') }}">{{ __('home_ai.widget.open_full_page') }}</a>
                <button type="button" class="icon-btn" id="assistantNewChatBtn" aria-label="{{ __('home_ai.widget.new_chat') }}" title="{{ __('home_ai.widget.new_chat') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                </button>
            </div>
        </div>

        <div class="assistant-messages" id="assistantMessages">
            <div class="assistant-msg assistant-msg-bot">
                <div class="assistant-bubble">{{ __('home_ai.widget.title') }} 👋</div>
            </div>
        </div>

        <div class="assistant-typing" id="assistantTyping" hidden>{{ __('home_ai.widget.thinking') }}</div>
        <div class="field-error" id="assistantError"></div>

        <div class="assistant-input-row">
            <textarea id="assistantInput" rows="1" placeholder="{{ __('home_ai.widget.placeholder') }}" maxlength="4000"></textarea>
            <button type="button" class="btn btn-primary" id="assistantSendBtn">{{ __('home_ai.widget.send') }}</button>
        </div>
        <p class="assistant-disclaimer">{{ __('home_ai.widget.disclaimer') }}</p>
    </div>
</div>

<style>
  .assistant-fab{
    position:fixed; bottom:26px; z-index:900;
    inset-inline-end:26px;
    width:56px; height:56px; border-radius:50%;
    background:var(--red-600); color:#fff; border:none;
    box-shadow:0 10px 26px rgba(236,32,36,.32);
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:transform .15s ease, box-shadow .15s ease;
  }
  .assistant-fab:hover{ transform:translateY(-2px); box-shadow:0 14px 30px rgba(236,32,36,.4); }
  .assistant-fab svg{ width:26px; height:26px; }

  .assistant-modal{
    background:#fff; width:100%; max-width:520px; height:min(680px, 86vh);
    border-radius:18px; box-shadow:0 30px 70px rgba(0,0,0,.28);
    overflow:hidden; display:flex; flex-direction:column;
    transform:translateY(14px); transition:transform .22s ease;
  }
  .modal-overlay.open .assistant-modal{ transform:translateY(0); }
  .assistant-link-btn{ font-size:12.5px; padding:7px 12px; text-decoration:none; }

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

@push('scripts')
<script>
(function () {
  'use strict';

  var fab = document.getElementById('assistantFab');
  var overlay = document.getElementById('assistantModalOverlay');
  if (!fab || !overlay) return;

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  var messageUrl = @json(route('home.assistant.message'));
  var ticketUrl = @json(route('home.assistant.ticket'));
  var emailUrl = @json(route('home.assistant.email'));
  var calendarEventUrl = @json(route('home.assistant.calendar-event'));
  var rateUrlTemplate = @json(route('home.assistant.rate', ['message' => '__ID__']));

  var i18n = {
    thinking: @json(__('home_ai.widget.thinking')),
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
    emailHeading: @json(__('home_ai.email_draft.heading')),
    emailFieldTo: @json(__('home_ai.email_draft.fields.to')),
    emailFieldSubject: @json(__('home_ai.email_draft.fields.subject')),
    emailFieldBody: @json(__('home_ai.email_draft.fields.body')),
    emailSend: @json(__('home_ai.email_draft.send')),
    emailSending: @json(__('home_ai.email_draft.sending')),
    emailCancel: @json(__('home_ai.email_draft.cancel')),
    emailSent: @json(__('home_ai.email_draft.sent')),
    emailSendFailed: @json(__('home_ai.email_draft.send_failed')),
    calHeadingEvent: @json(__('home_ai.calendar_draft.heading_event')),
    calHeadingMeeting: @json(__('home_ai.calendar_draft.heading_meeting')),
    calFieldSubject: @json(__('home_ai.calendar_draft.fields.subject')),
    calFieldWhen: @json(__('home_ai.calendar_draft.fields.when')),
    calFieldAttendees: @json(__('home_ai.calendar_draft.fields.attendees')),
    calFieldBody: @json(__('home_ai.calendar_draft.fields.body')),
    calCreate: @json(__('home_ai.calendar_draft.create')),
    calCreating: @json(__('home_ai.calendar_draft.creating')),
    calCancel: @json(__('home_ai.calendar_draft.cancel')),
    calCreatedEvent: @json(__('home_ai.calendar_draft.created_event')),
    calCreatedMeeting: @json(__('home_ai.calendar_draft.created_meeting')),
    calJoinLink: @json(__('home_ai.calendar_draft.join_link')),
    calCreateFailed: @json(__('home_ai.calendar_draft.create_failed')),
  };

  var messages = document.getElementById('assistantMessages');
  var typing = document.getElementById('assistantTyping');
  var errorEl = document.getElementById('assistantError');
  var input = document.getElementById('assistantInput');
  var sendBtn = document.getElementById('assistantSendBtn');
  var conversationId = null;
  var lastFocused = null;

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

  // One row + card + confirm/cancel actions, shared by every draft type —
  // only what's inside the card (fields shown, confirm request/response)
  // differs per type. Nothing here is real until the confirm button's
  // fetch() succeeds; see the AssistantToolbox / AssistantController
  // docblocks for why that split exists.
  function buildDraftCard() {
    var row = document.createElement('div');
    row.className = 'assistant-msg assistant-msg-bot';
    var card = document.createElement('div');
    card.className = 'assistant-draft-card';
    var actions = document.createElement('div');
    actions.className = 'assistant-draft-actions';
    card.appendChild(actions);
    row.appendChild(card);
    messages.appendChild(row);
    return { row: row, card: card, actions: actions };
  }

  function addConfirmActions(card, actions, confirmLabel, confirmingLabel, onConfirm) {
    var confirmBtn = document.createElement('button');
    confirmBtn.type = 'button';
    confirmBtn.className = 'btn btn-primary';
    confirmBtn.textContent = confirmLabel;
    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'btn btn-ghost';
    cancelBtn.textContent = i18n.cancel;

    cancelBtn.addEventListener('click', function () { actions.remove(); });

    confirmBtn.addEventListener('click', function () {
      confirmBtn.disabled = true;
      cancelBtn.disabled = true;
      confirmBtn.textContent = confirmingLabel;
      onConfirm(
        function (successText) {
          actions.remove();
          var confirmEl = document.createElement('div');
          confirmEl.className = 'reason';
          confirmEl.style.marginTop = '8px';
          confirmEl.textContent = successText;
          card.appendChild(confirmEl);
        },
        function (errorMessage) {
          confirmBtn.disabled = false;
          cancelBtn.disabled = false;
          confirmBtn.textContent = confirmLabel;
          setError(errorMessage);
        }
      );
    });

    actions.appendChild(confirmBtn);
    actions.appendChild(cancelBtn);
  }

  function addTicketDraftCard(draft) {
    var built = buildDraftCard();
    built.card.insertAdjacentHTML('afterbegin',
      '<h4>' + i18n.ticketDraftHeading + '</h4>' +
      '<div class="reason">' + i18n.checkedLabel + ' ' + escapeHtml(draft.reason_not_solved || '') + '</div>' +
      '<dl>' +
        '<dt>' + i18n.fieldTitle + '</dt><dd>' + escapeHtml(draft.title || '') + '</dd>' +
        '<dt>' + i18n.fieldCategory + '</dt><dd>' + escapeHtml(draft.category_name || '') + '</dd>' +
        '<dt>' + i18n.fieldSubcategory + '</dt><dd>' + escapeHtml(draft.subcategory_name || '') + '</dd>' +
        '<dt>' + i18n.fieldDescription + '</dt><dd>' + escapeHtml(draft.description || '') + '</dd>' +
      '</dl>'
    );

    addConfirmActions(built.card, built.actions, i18n.send, i18n.sending, function (onOk, onErr) {
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
          onOk(i18n.sentHeading + ' — ' + i18n.sentBody.replace(':reference', res.data.reference || '—'));
        })
        .catch(function (err) { onErr(err.message || i18n.sendFailedTicket); });
    });

    scrollToBottom();
  }

  function addEmailDraftCard(draft) {
    var built = buildDraftCard();
    var to = Array.isArray(draft.to) ? draft.to.join(', ') : '';
    built.card.insertAdjacentHTML('afterbegin',
      '<h4>' + i18n.emailHeading + '</h4>' +
      '<dl>' +
        '<dt>' + i18n.emailFieldTo + '</dt><dd>' + escapeHtml(to) + '</dd>' +
        '<dt>' + i18n.emailFieldSubject + '</dt><dd>' + escapeHtml(draft.subject || '') + '</dd>' +
        '<dt>' + i18n.emailFieldBody + '</dt><dd>' + escapeHtml(draft.body || '') + '</dd>' +
      '</dl>'
    );

    addConfirmActions(built.card, built.actions, i18n.emailSend, i18n.emailSending, function (onOk, onErr) {
      fetch(emailUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ to: draft.to, subject: draft.subject, body: draft.body })
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
          if (!res.ok) { throw new Error(res.data.message || i18n.emailSendFailed); }
          onOk(i18n.emailSent);
        })
        .catch(function (err) { onErr(err.message || i18n.emailSendFailed); });
    });

    scrollToBottom();
  }

  function addCalendarDraftCard(draft) {
    var built = buildDraftCard();
    var isTeams = !!draft.is_teams_meeting;
    var attendees = Array.isArray(draft.attendees) ? draft.attendees.join(', ') : '';
    var when = escapeHtml(draft.start || '') + ' — ' + escapeHtml(draft.end || '');

    built.card.insertAdjacentHTML('afterbegin',
      '<h4>' + (isTeams ? i18n.calHeadingMeeting : i18n.calHeadingEvent) + '</h4>' +
      '<dl>' +
        '<dt>' + i18n.calFieldSubject + '</dt><dd>' + escapeHtml(draft.subject || '') + '</dd>' +
        '<dt>' + i18n.calFieldWhen + '</dt><dd>' + when + '</dd>' +
        (attendees ? '<dt>' + i18n.calFieldAttendees + '</dt><dd>' + escapeHtml(attendees) + '</dd>' : '') +
        (draft.body ? '<dt>' + i18n.calFieldBody + '</dt><dd>' + escapeHtml(draft.body) + '</dd>' : '') +
      '</dl>'
    );

    addConfirmActions(built.card, built.actions, i18n.calCreate, i18n.calCreating, function (onOk, onErr) {
      fetch(calendarEventUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          subject: draft.subject,
          start: draft.start,
          end: draft.end,
          attendees: draft.attendees,
          body: draft.body,
          is_teams_meeting: isTeams,
        })
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
          if (!res.ok) { throw new Error(res.data.message || i18n.calCreateFailed); }
          var text = isTeams ? i18n.calCreatedMeeting : i18n.calCreatedEvent;
          if (res.data.join_url) { text += ' ' + i18n.calJoinLink + ': ' + res.data.join_url; }
          onOk(text);
        })
        .catch(function (err) { onErr(err.message || i18n.calCreateFailed); });
    });

    scrollToBottom();
  }

  function addDraftCard(draft) {
    if (draft.type === 'email') { return addEmailDraftCard(draft); }
    if (draft.type === 'calendar_event') { return addCalendarDraftCard(draft); }
    return addTicketDraftCard(draft);
  }

  function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = String(s == null ? '' : s);
    return div.innerHTML;
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
        if (res.data.draft) addDraftCard(res.data.draft);
      })
      .catch(function (err) {
        typing.hidden = true;
        sendBtn.disabled = false;
        setError(err.message || i18n.sendFailed);
      });
  }

  function open() {
    lastFocused = document.activeElement;
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    input.focus();
  }

  function close() {
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocused && lastFocused.focus) lastFocused.focus();
  }

  fab.addEventListener('click', open);
  document.getElementById('assistantCloseBtn').addEventListener('click', close);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.classList.contains('open')) close();
  });

  document.getElementById('assistantNewChatBtn').addEventListener('click', function () {
    conversationId = null;
    messages.innerHTML = '';
    addBubble('bot', @json(__('home_ai.widget.title')) + ' 👋');
    setError('');
  });

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
