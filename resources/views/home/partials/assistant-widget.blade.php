{{--
    Samir AI Assistant — floating launcher and chat window.

    Posts to home.assistant.message, which runs AssistantAgent against the
    signed-in employee's own scoped tools (AssistantToolbox) and the
    knowledge base. A ticket is only ever DRAFTED here — nothing reaches the
    ticketing system until the employee reviews the card and taps
    "Send to IT" (home.assistant.ticket), which runs through the same
    HomeTicketSubmitter as the IT Service Desk modal.

    Kept a self-contained session (no server-side conversation restore): the
    full page at /assistant is where a longer, resumable history lives. The
    conversation inside the window is home.partials.assistant-chat, shared
    with that page.
--}}
@include('home.partials.assistant-chat')

<button type="button" class="assistant-fab" id="assistantFab" aria-label="{{ __('home_ai.widget.launcher_label') }}">
    <span class="assistant-fab-icon">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M10.5 3.5c.6 3.7 2.4 5.5 7 7-4.6 1.5-6.4 3.3-7 7-.6-3.7-2.4-5.5-7-7 4.6-1.5 6.4-3.3 7-7Z"/>
            <path d="M18 15c.3 1.6 1 2.3 2.5 2.5-1.5.2-2.2.9-2.5 2.5-.3-1.6-1-2.3-2.5-2.5 1.5-.2 2.2-.9 2.5-2.5Z"/>
        </svg>
    </span>
    <span class="assistant-fab-label">{{ __('home_ai.widget.fab_label') }}</span>
</button>

<div class="modal-overlay" id="assistantModalOverlay" aria-hidden="true">
    <div class="assistant-modal" role="dialog" aria-modal="true" aria-labelledby="assistantModalTitle">
        <div class="assistant-head">
            <span class="assistant-avatar lg" aria-hidden="true"><img src="{{ asset('images/brand/samir-mark.png') }}" alt=""></span>
            <div class="assistant-head-title">
                <h2 id="assistantModalTitle">{{ __('home_ai.widget.title') }}</h2>
                <div class="assistant-status" id="assistantStatus" data-state="ready">
                    <span class="assistant-status-dot" aria-hidden="true"></span>
                    <span class="assistant-status-text">{{ __('home_ai.widget.online') }}</span>
                </div>
            </div>
            <a class="assistant-head-btn" href="{{ route('home.assistant.index') }}" aria-label="{{ __('home_ai.widget.open_full_page') }}" title="{{ __('home_ai.widget.open_full_page') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 4h6v6M20 4l-7 7M10 20H4v-6M4 20l7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <button type="button" class="assistant-head-btn" id="assistantNewChatBtn" aria-label="{{ __('home_ai.widget.new_chat') }}" title="{{ __('home_ai.widget.new_chat') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
            </button>
            <button type="button" class="assistant-head-btn" id="assistantCloseBtn" aria-label="{{ __('home_ticket_modal.close') }}" title="{{ __('home_ticket_modal.close') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke-linecap="round"/></svg>
            </button>
        </div>

        <div class="assistant-messages" id="assistantMessages" role="log"></div>

        <div class="field-error assistant-error" id="assistantError"></div>

        <div class="assistant-input-row">
            <textarea id="assistantInput" rows="1" placeholder="{{ __('home_ai.widget.placeholder') }}" maxlength="4000"></textarea>
            <button type="button" class="btn btn-primary" id="assistantSendBtn">{{ __('home_ai.widget.send') }}</button>
        </div>
        <p class="assistant-disclaimer">{{ __('home_ai.widget.disclaimer') }}</p>
    </div>
</div>

<style>
  /* A labelled pill rather than a bare icon, so the assistant is found by name. */
  .assistant-fab{
    position:fixed; bottom:26px; inset-inline-end:26px; z-index:900;
    display:flex; align-items:center; gap:10px;
    height:54px; padding:0; padding-inline:7px 20px;
    border:none; border-radius:999px; cursor:pointer;
    background:linear-gradient(135deg, var(--red-500) 0%, var(--red-600) 55%, var(--red-700) 100%);
    color:#fff; font-family:inherit; font-size:14px; font-weight:700; letter-spacing:.2px; white-space:nowrap;
    box-shadow:0 10px 26px rgba(236,32,36,.34);
    transition:transform .15s ease, box-shadow .15s ease;
  }
  .assistant-fab:hover{ transform:translateY(-2px); box-shadow:0 14px 30px rgba(236,32,36,.42); }
  .assistant-fab:focus-visible{ outline:2px solid var(--red-600); outline-offset:3px; }
  .assistant-fab-icon{
    flex-shrink:0; width:40px; height:40px; border-radius:50%;
    background:rgba(255,255,255,.18);
    display:flex; align-items:center; justify-content:center;
  }
  .assistant-fab-icon svg{ width:22px; height:22px; }
  @media (max-width:640px){
    .assistant-fab{ padding-inline:7px; }
    .assistant-fab-label{ display:none; }
  }

  .assistant-modal{
    background:#fff; width:100%; max-width:520px; height:min(680px, 86vh);
    border-radius:18px; box-shadow:0 30px 70px rgba(0,0,0,.28);
    overflow:hidden; display:flex; flex-direction:column;
    transform:translateY(14px); transition:transform .22s ease;
  }
  .modal-overlay.open .assistant-modal{ transform:translateY(0); }
</style>

@push('scripts')
<script>
(function () {
  'use strict';

  var fab = document.getElementById('assistantFab');
  var overlay = document.getElementById('assistantModalOverlay');
  if (!fab || !overlay || !window.SamirAssistant) return;

  var input = document.getElementById('assistantInput');
  var lastFocused = null;

  var chat = window.SamirAssistant.chat({
    messages: document.getElementById('assistantMessages'),
    input: input,
    sendBtn: document.getElementById('assistantSendBtn'),
    errorEl: document.getElementById('assistantError'),
    statusEl: document.getElementById('assistantStatus'),
    conversationId: null,
  });
  chat.greet();

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
    chat.reset();
    input.focus();
  });
})();
</script>
@endpush
