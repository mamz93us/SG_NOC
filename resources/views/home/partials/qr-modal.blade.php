@php
    /**
     * Reusable QR modal.
     *
     * Params: $id, $triggerId, $title, $description, $url, $caption, $footnote.
     *
     * The QR is rendered server-side with the library already in the project
     * (chillerlan/php-qrcode, the same helper EmployeeCardController uses). The
     * original design called api.qrserver.com, which would send an employee's
     * card URL to a third party on every open and would fail whenever the
     * internet is down but the NOC is reachable — a real state on a branch VPN.
     */
    $qrSvg = null;

    try {
        $options = new \chillerlan\QRCode\QROptions;
        $options->eccLevel = 'M';
        $options->outputBase64 = false;
        $qrSvg = (new \chillerlan\QRCode\QRCode($options))->render($url);
    } catch (\Throwable) {
        // Fall back to a plain link rather than breaking the page.
        $qrSvg = null;
    }

    $footnote = $footnote ?? null;
@endphp

<div class="wallet-modal-overlay" id="{{ $id }}" aria-hidden="true" data-trigger="{{ $triggerId }}">
  <div class="wallet-modal" role="dialog" aria-modal="true"
       aria-labelledby="{{ $id }}Title" aria-describedby="{{ $id }}Desc">
    <div class="wallet-modal-icon">
      {{ $icon ?? '' }}
      @if(empty($icon))
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><path d="M13.5 13.5h3v3M20.5 13.5v3M17 20.5h3.5V17M13.5 20.5h.01" stroke-linecap="round"/></svg>
      @endif
    </div>

    <h2 id="{{ $id }}Title">{{ $title }}</h2>
    <p class="wallet-modal-copy" id="{{ $id }}Desc">{{ $description }}</p>

    <div class="wallet-qr-wrap">
      @if($qrSvg)
        {!! $qrSvg !!}
      @else
        <p class="wallet-instruction" style="margin:0;">
          <a href="{{ $url }}">Open the link</a>
        </p>
      @endif
    </div>

    <p class="wallet-employee">{{ $caption }}</p>

    @if($footnote)
      <p class="wallet-instruction">{{ $footnote }}</p>
    @endif

    <button type="button" class="wallet-close-btn" data-close-modal>Close</button>
  </div>
</div>
