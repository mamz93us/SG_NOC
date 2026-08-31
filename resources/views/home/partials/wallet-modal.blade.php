@php
    // The QR is rendered server-side with the QR library already in the project
    // (chillerlan/php-qrcode, the same helper EmployeeCardController uses).
    //
    // The design called api.qrserver.com. That would send every employee's card
    // URL to a third party on every open, and would fail whenever the internet
    // is down but the NOC is reachable — which on a branch VPN is a real state.
    // Inline SVG costs nothing and leaves the building never.
    //
    // $cardUrl comes from VCard::cardUrl(), i.e. the business-card subdomain —
    // the canonical public address for a card, and the one worth handing out.
    $qrSvg = null;

    try {
        $options = new \chillerlan\QRCode\QROptions;
        $options->eccLevel = 'M';
        $options->outputBase64 = false;
        $qrSvg = (new \chillerlan\QRCode\QRCode($options))->render($cardUrl);
    } catch (\Throwable) {
        // Render the link instead of breaking the page.
        $qrSvg = null;
    }

    $displayName = $employee?->name ?: $user->name;
@endphp

<div class="wallet-modal-overlay" id="walletModalOverlay" aria-hidden="true">
  <div class="wallet-modal" role="dialog" aria-modal="true"
       aria-labelledby="walletModalTitle" aria-describedby="walletModalDescription">
    <div class="wallet-modal-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><path d="M13.5 13.5h3v3M20.5 13.5v3M17 20.5h3.5V17M13.5 20.5h.01" stroke-linecap="round"/></svg>
    </div>
    <h2 id="walletModalTitle">My Digital Card</h2>
    <p class="wallet-modal-copy" id="walletModalDescription">
      Let someone scan this to open your business card &mdash; contact details,
      extension, and a one-tap save to their phone.
    </p>

    <div class="wallet-qr-wrap">
      @if($qrSvg)
        {!! $qrSvg !!}
      @else
        <p class="wallet-instruction" style="margin:0;">
          <a href="{{ $cardUrl }}" target="_blank" rel="noopener noreferrer">Open your card</a>
        </p>
      @endif
    </div>

    <p class="wallet-employee">{{ $displayName }}</p>
    <p class="wallet-card-url">{{ preg_replace('#^https?://#', '', $cardUrl) }}</p>
    <button type="button" class="wallet-close-btn" id="closeWalletModal">Close</button>
  </div>
</div>

@push('scripts')
<script>
(function () {
  'use strict';

  var overlay = document.getElementById('walletModalOverlay');
  var openBtn = document.getElementById('showCardQrBtn');
  var closeBtn = document.getElementById('closeWalletModal');
  if (!overlay || !openBtn) return;

  var lastFocused = null;

  function open() {
    lastFocused = document.activeElement;
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    closeBtn?.focus();
  }

  function close() {
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocused && lastFocused.focus) lastFocused.focus();
  }

  openBtn.addEventListener('click', open);
  closeBtn?.addEventListener('click', close);
  overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.classList.contains('open')) close();
  });
})();
</script>
@endpush
