{{--
    Samir AI Assistant — the conversation itself, shared by the floating
    widget (home.partials.assistant-widget) and the full page
    (home.assistant). Each of those draws its own frame (header, message
    list, input) and hands the elements to SamirAssistant.chat(); everything
    that happens inside the conversation lives here once, so the two cannot
    drift apart.

    A reply arrives whole from home.assistant.message and is typed out here.
    That is presentation only: it is paced to finish within about three
    seconds whatever its length, and sending the next question lands the
    current reply in full first, so nobody waits on the animation.

    A ticket, email or meeting is only ever DRAFTED in this chat — nothing is
    real until the employee confirms its card (see AssistantToolbox and
    AssistantController for why that split exists).
--}}
@once
<style>
  /* ===== Samir AI Assistant ===== */
  .assistant-avatar{
    flex:0 0 auto; width:30px; height:30px; border-radius:50%;
    background:linear-gradient(140deg, var(--gray-900) 0%, var(--gray-700) 100%);
    display:flex; align-items:center; justify-content:center;
  }
  .assistant-avatar img{ width:62%; height:auto; display:block; }
  .assistant-avatar.lg{ width:42px; height:42px; }

  /* Header: who you are talking to, and what it is doing right now. */
  .assistant-head{
    position:relative; flex-shrink:0; overflow:hidden;
    display:flex; align-items:center; gap:10px; padding:14px 16px;
    background:linear-gradient(120deg, var(--gray-900) 0%, var(--gray-800) 55%, var(--gray-700) 100%);
    color:#fff;
  }
  .assistant-head::before{
    content:""; position:absolute; inset:0; pointer-events:none;
    background:radial-gradient(420px 170px at 92% -40%, rgba(236,32,36,.30), transparent 62%);
  }
  .assistant-head > *{ position:relative; }
  .assistant-head .assistant-avatar{ background:#fff; box-shadow:0 0 0 3px rgba(255,255,255,.14); margin-inline-end:2px; }
  .assistant-head-title{ flex:1; min-width:0; }
  .assistant-head-title h2{ font-size:16px; font-weight:700; letter-spacing:.2px; line-height:1.25; color:#fff; }
  .assistant-status{ display:flex; align-items:center; gap:7px; margin-top:3px; font-size:12px; color:rgba(255,255,255,.7); }
  .assistant-status-dot{
    flex-shrink:0; width:7px; height:7px; border-radius:50%;
    background:#22C55E; box-shadow:0 0 0 3px rgba(34,197,94,.22);
  }
  .assistant-status[data-state="thinking"] .assistant-status-dot,
  .assistant-status[data-state="typing"] .assistant-status-dot{
    background:var(--red-500); box-shadow:0 0 0 3px rgba(241,51,55,.28);
    animation:assistantPulse 1.1s ease-in-out infinite;
  }
  .assistant-head-btn{
    flex-shrink:0; width:32px; height:32px; border-radius:9px;
    border:1px solid rgba(255,255,255,.16); background:rgba(255,255,255,.08); color:rgba(255,255,255,.82);
    display:flex; align-items:center; justify-content:center; cursor:pointer; text-decoration:none;
    transition:background .15s ease, color .15s ease;
  }
  .assistant-head-btn:hover{ background:rgba(255,255,255,.18); color:#fff; }
  .assistant-head-btn:focus-visible{ outline:2px solid #fff; outline-offset:2px; }
  .assistant-head-btn svg{ width:16px; height:16px; }

  .assistant-messages{ flex:1; overflow-y:auto; padding:18px 20px; display:flex; flex-direction:column; gap:14px; }
  .assistant-msg{ display:flex; align-items:flex-start; gap:10px; }
  .assistant-msg-user{ justify-content:flex-end; }
  .assistant-msg-body{ min-width:0; max-width:85%; display:flex; flex-direction:column; align-items:flex-start; }
  .assistant-bubble{
    max-width:82%; padding:10px 14px; border-radius:16px; font-size:13.5px; line-height:1.55;
    white-space:pre-wrap; word-break:break-word;
  }
  .assistant-msg-body .assistant-bubble{ max-width:100%; }
  .assistant-msg-bot .assistant-bubble{ background:var(--bg); color:var(--ink); border-start-start-radius:4px; }
  .assistant-msg-user .assistant-bubble{ background:var(--red-600); color:#fff; border-end-end-radius:4px; }

  /* Thinking: dots in a bubble of their own until the reply arrives. */
  .assistant-thinking{ display:inline-flex; align-items:center; gap:10px; color:var(--ink-soft); font-size:12.5px; }
  .assistant-dots{ display:inline-flex; align-items:center; gap:4px; height:16px; }
  .assistant-dots i{
    width:7px; height:7px; border-radius:50%; background:var(--gray-500);
    animation:assistantBounce 1.2s ease-in-out infinite;
  }
  .assistant-dots i:nth-child(2){ animation-delay:.15s; }
  .assistant-dots i:nth-child(3){ animation-delay:.3s; }

  /* Typing: a caret that rides the end of the reply while it is typed out. */
  .assistant-bubble.is-typing::after{
    content:""; display:inline-block; width:2px; height:1.1em; margin-inline-start:2px;
    vertical-align:text-bottom; border-radius:1px; background:var(--red-600);
    animation:assistantCaret 1s step-end infinite;
  }

  /* Under each reply: copy, helpful, not helpful — small icons, as in any chat app. */
  .assistant-actions{ display:flex; align-items:center; gap:2px; margin-top:4px; animation:assistantFadeIn .25s ease backwards; }
  .assistant-action{
    width:28px; height:28px; padding:0; border:0; border-radius:8px; background:transparent;
    color:var(--gray-600); display:inline-flex; align-items:center; justify-content:center; cursor:pointer;
    transition:background .15s ease, color .15s ease;
  }
  .assistant-action:hover{ background:var(--bg); color:var(--ink); }
  .assistant-action:focus-visible{ outline:2px solid var(--red-500); outline-offset:1px; }
  .assistant-action svg{ width:15px; height:15px; }
  /* A chosen rating is a filled thumb; its last path is the cuff line, kept white. */
  .assistant-action[aria-pressed="true"]{ color:var(--ink); }
  .assistant-action[aria-pressed="true"] path{ fill:currentColor; }
  .assistant-action[aria-pressed="true"] path:last-child{ stroke:#fff; }
  .assistant-action.is-done{ color:var(--green); }

  .field-error.assistant-error{ margin:0; padding:0 20px 8px; }
  .field-error.assistant-error:empty{ display:none; }

  .assistant-input-row{ display:flex; gap:10px; padding:14px 20px; border-top:1px solid var(--line); align-items:flex-end; }
  .assistant-input-row textarea{
    flex:1; resize:none; max-height:120px; font-family:var(--font-sans); font-size:13.5px;
    border:1px solid var(--line); border-radius:10px; padding:10px 12px; color:var(--ink);
  }
  .assistant-input-row textarea:focus{ outline:none; border-color:var(--red-600); box-shadow:0 0 0 3px var(--red-100); }
  .assistant-disclaimer{ font-size:11px; color:var(--ink-soft); text-align:center; padding:0 20px 14px; }

  .assistant-draft-card{
    align-self:stretch; margin-top:8px;
    background:var(--bg); border:1px solid var(--line); border-radius:12px; padding:14px; font-size:13px;
  }
  .assistant-draft-card h4{ font-size:13.5px; font-weight:700; color:var(--ink); margin-bottom:6px; }
  .assistant-draft-card .reason{ color:var(--ink-soft); font-size:12px; margin-bottom:10px; }
  .assistant-draft-card dl{ display:grid; grid-template-columns:auto 1fr; gap:4px 10px; margin-bottom:12px; }
  .assistant-draft-card dt{ color:var(--ink-soft); }
  .assistant-draft-card dd{ margin:0; color:var(--ink); }
  .assistant-draft-actions{ display:flex; gap:8px; }
  .assistant-draft-actions button{ font-size:12.5px; padding:7px 12px; }

  @keyframes assistantBounce{ 0%, 60%, 100%{ transform:translateY(0); opacity:.4; } 30%{ transform:translateY(-4px); opacity:1; } }
  @keyframes assistantCaret{ 50%{ opacity:0; } }
  @keyframes assistantPulse{ 50%{ opacity:.35; } }
  @keyframes assistantFadeIn{ from{ opacity:0; transform:translateY(-3px); } }

  @media (prefers-reduced-motion:reduce){
    .assistant-dots i,
    .assistant-status-dot,
    .assistant-bubble.is-typing::after,
    .assistant-actions{ animation:none !important; }
  }
</style>

{{--
    Inline, NOT @push('scripts'). Blade keeps a stack's pushes grouped by
    include depth rather than in the order they ran: on home.index the ticket
    modal's push (depth 2) came first, the widget's own push (depth 2 too) was
    appended to it, and this engine, one include deeper, was output after both
    — so the widget's script found no window.SamirAssistant and "Ask Samir AI"
    did nothing. Defined here, where the partial is included, it needs no
    stack order at all; it touches nothing on the page until chat() is called.
--}}
<script>
(function () {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  var messageUrl = @json(route('home.assistant.message'));
  var ticketUrl = @json(route('home.assistant.ticket'));
  var emailUrl = @json(route('home.assistant.email'));
  var calendarEventUrl = @json(route('home.assistant.calendar-event'));
  var rateUrlTemplate = @json(route('home.assistant.rate', ['message' => '__ID__']));
  var avatarUrl = @json(asset('images/brand/samir-mark.png'));
  var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

  var i18n = {
    greeting: @json(__('home_ai.widget.greeting')),
    thinking: @json(__('home_ai.widget.thinking')),
    status: {
      ready: @json(__('home_ai.widget.online')),
      thinking: @json(__('home_ai.widget.thinking')),
      typing: @json(__('home_ai.widget.typing')),
    },
    copy: @json(__('home_ai.widget.copy')),
    copied: @json(__('home_ai.widget.copied')),
    helpful: @json(__('home_ai.rating.helpful')),
    notHelpful: @json(__('home_ai.rating.not_helpful')),
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
    emailHeading: @json(__('home_ai.email_draft.heading')),
    emailFieldTo: @json(__('home_ai.email_draft.fields.to')),
    emailFieldSubject: @json(__('home_ai.email_draft.fields.subject')),
    emailFieldBody: @json(__('home_ai.email_draft.fields.body')),
    emailSend: @json(__('home_ai.email_draft.send')),
    emailSending: @json(__('home_ai.email_draft.sending')),
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
    calCreatedEvent: @json(__('home_ai.calendar_draft.created_event')),
    calCreatedMeeting: @json(__('home_ai.calendar_draft.created_meeting')),
    calJoinLink: @json(__('home_ai.calendar_draft.join_link')),
    calCreateFailed: @json(__('home_ai.calendar_draft.create_failed')),
  };

  var icons = {
    copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="8.5" y="8.5" width="12" height="12" rx="2.2"/><path d="M15.5 8.5V5.7a2.2 2.2 0 0 0-2.2-2.2H5.7a2.2 2.2 0 0 0-2.2 2.2v7.6a2.2 2.2 0 0 0 2.2 2.2h2.8"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12.5 10 17 19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    up: thumb(''),
    down: thumb(' transform="rotate(180 12 12)"'),
  };

  // Thumbs down is the same hand turned over.
  function thumb(transform) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">' +
      '<g' + transform + '>' +
        '<path d="M7 10l3.3-6.6a1.9 1.9 0 0 1 3.5 1.4L13 10h5.5a2 2 0 0 1 2 2.4l-1.3 6.8a2.2 2.2 0 0 1-2.2 1.8H7Z"/>' +
        '<path d="M7 10H4.5A1.5 1.5 0 0 0 3 11.5v8A1.5 1.5 0 0 0 4.5 21H7Z"/>' +
        '<path d="M7 11v9"/>' +
      '</g></svg>';
  }

  function jsonHeaders() {
    return { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' };
  }

  // An HTML error page (a 504 from nginx, an expired-session 419) is not JSON;
  // it must still end in the friendly message, not a parser error.
  function readJson(r) {
    return r.json()
      .catch(function () { return {}; })
      .then(function (d) { return { ok: r.ok, data: d || {} }; });
  }

  // A message meant for the employee. Anything else thrown on the way
  // ("Failed to fetch") is replaced with the translated fallback.
  function userError(message) {
    var e = new Error(message);
    e.shown = true;
    return e;
  }

  function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = String(s == null ? '' : s);
    return div.innerHTML;
  }

  function copyText(text) {
    return navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject(new Error('no clipboard'));
  }

  function actionButton(icon, label) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'assistant-action';
    setAction(btn, icon, label);
    return btn;
  }

  function setAction(btn, icon, label) {
    btn.innerHTML = icon;
    btn.title = label;
    btn.setAttribute('aria-label', label);
  }

  window.SamirAssistant = {
    // el: { messages, input, sendBtn, errorEl, statusEl, conversationId }
    chat: function (el) {
      var messages = el.messages;
      var input = el.input;
      var sendBtn = el.sendBtn;
      var errorEl = el.errorEl;
      var statusEl = el.statusEl;
      var conversationId = el.conversationId || null;
      var generation = 0; // bumped by reset(), so a reply to a cleared chat is dropped
      var typing = null;  // the reply being typed out, while it is

      function setError(msg) { errorEl.textContent = msg || ''; }
      function scrollToBottom() { messages.scrollTop = messages.scrollHeight; }
      function nearBottom() { return messages.scrollHeight - messages.scrollTop - messages.clientHeight < 60; }

      // ready / thinking / typing, under the name in the header.
      function setStatus(state) {
        if (!statusEl) return;
        statusEl.setAttribute('data-state', state);
        statusEl.querySelector('.assistant-status-text').textContent = i18n.status[state];
      }

      function avatar() {
        var span = document.createElement('span');
        span.className = 'assistant-avatar';
        span.setAttribute('aria-hidden', 'true');
        var img = document.createElement('img');
        img.src = avatarUrl;
        img.alt = '';
        span.appendChild(img);
        return span;
      }

      function bubble(text) {
        var div = document.createElement('div');
        div.className = 'assistant-bubble';
        div.textContent = text;
        return div;
      }

      function addUserBubble(text) {
        var row = document.createElement('div');
        row.className = 'assistant-msg assistant-msg-user';
        row.appendChild(bubble(text));
        messages.appendChild(row);
        scrollToBottom();
      }

      // One assistant turn: the avatar beside a column holding the reply, any
      // draft card, and the action icons.
      function addBotTurn() {
        var row = document.createElement('div');
        row.className = 'assistant-msg assistant-msg-bot';
        var body = document.createElement('div');
        body.className = 'assistant-msg-body';
        row.appendChild(avatar());
        row.appendChild(body);
        messages.appendChild(row);
        return { row: row, body: body };
      }

      function greet() {
        addBotTurn().body.appendChild(bubble(i18n.greeting));
      }

      function addThinking() {
        var turn = addBotTurn();
        var dots = bubble('');
        dots.classList.add('assistant-thinking');
        dots.innerHTML = '<span class="assistant-dots" aria-hidden="true"><i></i><i></i><i></i></span>';
        var label = document.createElement('span');
        label.textContent = i18n.thinking;
        dots.appendChild(label);
        turn.body.appendChild(dots);
        scrollToBottom();
        return turn.row;
      }

      // Types a reply out a word at a time — whole words, so Arabic is never
      // shown in the half-joined letter forms of a partial word. Paced by the
      // clock rather than by frames: a tab in the background, where frames
      // stop, catches up at once instead of resuming mid-sentence.
      function typeOut(target, text, onDone) {
        var stopped = false;
        var started = null;
        var perSecond = Math.max(60, text.length / 3);

        var job = {
          finish: function () {
            if (stopped) return;
            stopped = true;
            typing = null;
            // Measured before the rest of the text lands: a reply that arrives
            // all at once (reduced motion, a tab catching up) is taller than
            // "near the bottom" allows, and would be left cut off below.
            var follow = nearBottom();
            target.textContent = text;
            target.classList.remove('is-typing');
            onDone();
            if (follow) scrollToBottom();
          },
          cancel: function () {
            stopped = true;
            typing = null;
          },
        };

        if (reduceMotion || text.length < 2) {
          job.finish();
          return;
        }

        typing = job;
        target.classList.add('is-typing');

        function frame(now) {
          if (stopped) return;
          if (started === null) started = now;

          var end = Math.floor((now - started) / 1000 * perSecond);
          var limit = Math.min(text.length, end + 24); // a long URL still arrives in pieces
          while (end < limit && !/\s/.test(text.charAt(end))) end++;
          if (end >= text.length) {
            job.finish();
            return;
          }
          if (/[\uDC00-\uDFFF]/.test(text.charAt(end))) end++; // never split an emoji

          var follow = nearBottom();
          target.textContent = text.slice(0, end);
          if (follow) scrollToBottom();
          requestAnimationFrame(frame);
        }

        requestAnimationFrame(frame);
      }

      // Copy / Helpful / Not helpful under a reply. "Not helpful" on an answer
      // from the knowledge base also files its question under Knowledge gaps
      // (AssistantController::rate).
      function addActions(body, messageId, rating, text) {
        var bar = document.createElement('div');
        bar.className = 'assistant-actions';
        body.appendChild(bar);

        var copyBtn = actionButton(icons.copy, i18n.copy);
        var copyTimer = null;
        copyBtn.addEventListener('click', function () {
          copyText(text).then(function () {
            setAction(copyBtn, icons.check, i18n.copied);
            copyBtn.classList.add('is-done');
            clearTimeout(copyTimer);
            copyTimer = setTimeout(function () {
              setAction(copyBtn, icons.copy, i18n.copy);
              copyBtn.classList.remove('is-done');
            }, 1600);
          }).catch(function () {});
        });
        bar.appendChild(copyBtn);

        if (!messageId) return;

        var current = rating === 1 || rating === -1 ? rating : 0;
        var up = actionButton(icons.up, i18n.helpful);
        var down = actionButton(icons.down, i18n.notHelpful);

        var paint = function () {
          up.setAttribute('aria-pressed', current === 1 ? 'true' : 'false');
          down.setAttribute('aria-pressed', current === -1 ? 'true' : 'false');
        };

        var rate = function (value) {
          if (current === value) return;
          var previous = current;
          current = value;
          paint();
          fetch(rateUrlTemplate.replace('__ID__', messageId), {
            method: 'POST',
            headers: jsonHeaders(),
            body: JSON.stringify({ rating: value })
          })
            .then(function (r) { if (!r.ok) throw new Error('rating not saved'); })
            .catch(function () {
              // Not recorded, so do not show it as if it were — unless a
              // later click has already moved on.
              if (current === value) { current = previous; paint(); }
            });
        };

        paint();
        up.addEventListener('click', function () { rate(1); });
        down.addEventListener('click', function () { rate(-1); });
        bar.appendChild(up);
        bar.appendChild(down);
      }

      // One card + confirm/cancel actions, shared by every draft type — only
      // what's inside the card (fields shown, confirm request/response)
      // differs per type. Nothing here is real until the confirm button's
      // fetch() succeeds; see the AssistantToolbox / AssistantController
      // docblocks for why that split exists.
      function buildDraftCard(body) {
        var card = document.createElement('div');
        card.className = 'assistant-draft-card';
        var actions = document.createElement('div');
        actions.className = 'assistant-draft-actions';
        card.appendChild(actions);
        body.appendChild(card);
        return { card: card, actions: actions };
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

      function addTicketDraftCard(body, draft) {
        var built = buildDraftCard(body);
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
            headers: jsonHeaders(),
            body: JSON.stringify({
              title: draft.title,
              description: draft.description,
              category_id: draft.category_id,
              subcategory_id: draft.subcategory_id,
            })
          })
            .then(readJson)
            .then(function (res) {
              if (!res.ok) { throw userError(res.data.message || i18n.sendFailedTicket); }
              onOk(i18n.sentHeading + ' — ' + i18n.sentBody.replace(':reference', res.data.reference || '—'));
            })
            .catch(function (err) { onErr(err && err.shown ? err.message : i18n.sendFailedTicket); });
        });
      }

      function addEmailDraftCard(body, draft) {
        var built = buildDraftCard(body);
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
            headers: jsonHeaders(),
            body: JSON.stringify({ to: draft.to, subject: draft.subject, body: draft.body })
          })
            .then(readJson)
            .then(function (res) {
              if (!res.ok) { throw userError(res.data.message || i18n.emailSendFailed); }
              onOk(i18n.emailSent);
            })
            .catch(function (err) { onErr(err && err.shown ? err.message : i18n.emailSendFailed); });
        });
      }

      function addCalendarDraftCard(body, draft) {
        var built = buildDraftCard(body);
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
            headers: jsonHeaders(),
            body: JSON.stringify({
              subject: draft.subject,
              start: draft.start,
              end: draft.end,
              attendees: draft.attendees,
              body: draft.body,
              is_teams_meeting: isTeams,
            })
          })
            .then(readJson)
            .then(function (res) {
              if (!res.ok) { throw userError(res.data.message || i18n.calCreateFailed); }
              var text = isTeams ? i18n.calCreatedMeeting : i18n.calCreatedEvent;
              if (res.data.join_url) { text += ' ' + i18n.calJoinLink + ': ' + res.data.join_url; }
              onOk(text);
            })
            .catch(function (err) { onErr(err && err.shown ? err.message : i18n.calCreateFailed); });
        });
      }

      function addDraftCard(body, draft) {
        if (draft.type === 'email') { return addEmailDraftCard(body, draft); }
        if (draft.type === 'calendar_event') { return addCalendarDraftCard(body, draft); }
        return addTicketDraftCard(body, draft);
      }

      function send() {
        var text = input.value.trim();
        if (!text || sendBtn.disabled) return;
        if (typing) typing.finish();

        setError('');
        addUserBubble(text);
        input.value = '';
        input.style.height = 'auto';
        sendBtn.disabled = true;
        setStatus('thinking');
        var thinkingRow = addThinking();
        var sentIn = generation;

        fetch(messageUrl, {
          method: 'POST',
          headers: jsonHeaders(),
          body: JSON.stringify({ conversation_id: conversationId, message: text })
        })
          .then(readJson)
          .then(function (res) {
            if (sentIn !== generation) return;
            thinkingRow.remove();
            sendBtn.disabled = false;

            var reply = res.data.message;
            if (!res.ok || !reply || typeof reply !== 'object') {
              throw userError(typeof reply === 'string' ? reply : i18n.sendFailed);
            }

            conversationId = res.data.conversation_id;
            var content = reply.content || '';
            var turn = addBotTurn();
            var answer = bubble('');
            turn.body.appendChild(answer);
            scrollToBottom();

            setStatus('typing');
            messages.setAttribute('aria-busy', 'true');
            typeOut(answer, content, function () {
              messages.setAttribute('aria-busy', 'false');
              setStatus('ready');
              if (res.data.draft) addDraftCard(turn.body, res.data.draft);
              addActions(turn.body, reply.id, 0, content);
            });
          })
          .catch(function (err) {
            if (sentIn !== generation) return;
            thinkingRow.remove();
            sendBtn.disabled = false;
            messages.setAttribute('aria-busy', 'false');
            setStatus('ready');
            setError(err && err.shown ? err.message : i18n.sendFailed);
          });
      }

      function reset() {
        generation++;
        if (typing) typing.cancel();
        conversationId = null;
        messages.innerHTML = '';
        messages.setAttribute('aria-busy', 'false');
        sendBtn.disabled = false;
        setStatus('ready');
        setError('');
        greet();
      }

      // History the full page rendered gets the same icons as a new reply.
      Array.prototype.forEach.call(messages.querySelectorAll('.assistant-msg-bot[data-message-id]'), function (row) {
        addActions(
          row.querySelector('.assistant-msg-body'),
          row.getAttribute('data-message-id'),
          parseInt(row.getAttribute('data-rating'), 10) || 0,
          row.querySelector('.assistant-bubble').textContent
        );
      });

      sendBtn.addEventListener('click', send);
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
      });
      // scrollHeight leaves out the border and the portal is border-box
      // throughout, so without adding it back the box sits 2px short and
      // shows a scrollbar on a single line.
      input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight + input.offsetHeight - input.clientHeight, 120) + 'px';
      });
      scrollToBottom();

      return { greet: greet, reset: reset };
    }
  };
})();
</script>
@endonce
