@php
    // The QR is rendered server-side with the QR library already in the project
    // (chillerlan/php-qrcode, same helper EmployeeCardController uses).
    //
    // The design called api.qrserver.com. That would send every employee's card
    // URL to a third party on every open, and would fail whenever the internet
    // is down but the NOC is reachable — which on a branch VPN is a real state.
    // Inline SVG costs nothing and leaves the building never.
    $cardUrl = url('/card/'.$cardToken);
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
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 10h18M16.5 14.5h2" stroke-linecap="round"/></svg>
    </div>
    <h2 id="walletModalTitle">Add Employee Card</h2>
    <p class="wallet-modal-copy" id="walletModalDescription">
      Scan this QR code with your phone to open your digital employee card, then add it to your wallet.
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
    <p class="wallet-instruction">Open your phone camera and scan the code.</p>
    <button type="button" class="wallet-close-btn" id="closeWalletModal">Close</button>
  </div>
</div>

@push('scripts')
<script>
(function () {
  'use strict';

  var overlay = document.getElementById('walletModalOverlay');
  var openBtn = document.getElementById('addToWalletBtn');
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
