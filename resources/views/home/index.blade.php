@extends('layouts.home')

@section('title', 'Samir Group | Employee Portal')

@php
    use App\Support\HrPortal;

    $coreSystems = collect(config('home_portal.core_systems', []))
        ->map(function ($sys) {
            // `hr` has no configured URL — it resolves to the HR portal host.
            if (($sys['key'] ?? null) === 'hr') {
                $sys['url'] = HrPortal::enabled() ? HrPortal::url() : null;
            }

            return $sys;
        })
        // A tile with no destination is a dead tile. Hide it rather than ship a
        // link that does nothing. The service desk is the exception: it opens
        // the modal, so it never has a URL.
        ->filter(fn ($sys) => ($sys['key'] ?? null) === 'servicedesk' || ! empty($sys['url']))
        ->values();
@endphp

@section('content')

{{-- ─── Greeting ─────────────────────────────────────────────── --}}
<div class="greeting">
    <h2>{{ $greeting['greeting'] }}, <span class="greet-name">{{ $greeting['name'] }}</span></h2>
    <p class="greet-line">
        {{ $greeting['line'] }}
        @if($greeting['line_ar'])
            <span class="ar"> — {{ $greeting['line_ar'] }}</span>
        @endif
    </p>
    <div class="greet-meta">
        <span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 9.5h17M8 3.5v3M16 3.5v3" stroke-linecap="round"/></svg>
            {{ now()->format('l, j F Y') }}
        </span>
        @if($employee?->branch)
            <span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                {{ $employee->branch->name }}
            </span>
        @endif
        @if($employee?->job_title)
            <span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="7.5" width="18" height="12.5" rx="2"/><path d="M9 7.5V6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v1.5" stroke-linecap="round"/></svg>
                {{ $employee->job_title }}
            </span>
        @endif
    </div>
</div>

{{-- ─── Announcements slider ─────────────────────────────────── --}}
@if($announcements->isNotEmpty())
    <section class="ann-slider {{ $announcements->count() === 1 ? 'ann-single' : '' }}"
             id="annSlider"
             aria-label="Company announcements"
             aria-roledescription="carousel">
        <div class="ann-track" id="annTrack">
            @foreach($announcements as $i => $ann)
                <article class="ann-slide {{ $i === 0 ? 'active' : '' }}"
                         data-id="{{ $ann->id }}"
                         role="group"
                         aria-roledescription="slide"
                         aria-label="{{ $i + 1 }} of {{ $announcements->count() }}"
                         @if($i !== 0) aria-hidden="true" @endif>
                    <div class="ann-slide-top">
                        <span class="ann-pill {{ $ann->severityBadgeClass() }}">
                            {{ $ann->isUrgent() ? 'Important' : 'Announcement' }}
                        </span>
                        @if($ann->published_at)
                            <span class="ann-date">{{ $ann->published_at->format('j M Y') }}</span>
                        @endif
                    </div>
                    <h3>{{ $ann->title }}</h3>
                    <p>{{ $ann->excerpt(220) }}</p>
                    @if($ann->link_url)
                        <a class="ann-link" href="{{ $ann->link_url }}" target="_blank" rel="noopener noreferrer">
                            {{ $ann->link_label ?: 'Read more' }} &rarr;
                        </a>
                    @endif
                </article>
            @endforeach
        </div>

        <button type="button" class="ann-nav ann-prev" id="annPrev" aria-label="Previous announcement">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 5 8 12l7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <button type="button" class="ann-nav ann-next" id="annNext" aria-label="Next announcement">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>

        <div class="ann-dots" id="annDots" role="tablist" aria-label="Choose announcement">
            @foreach($announcements as $i => $ann)
                <button type="button"
                        class="ann-dot {{ $i === 0 ? 'active' : '' }}"
                        role="tab"
                        data-index="{{ $i }}"
                        aria-label="Announcement {{ $i + 1 }}"
                        @if($i === 0) aria-selected="true" @endif></button>
            @endforeach
        </div>
    </section>
@endif

<div class="portal-layout">
<div class="portal-main">

    {{-- ─── Core systems ──────────────────────────────────────── --}}
    <p class="section-label">Core systems</p>
    <div class="grid-primary">
        @foreach($coreSystems as $sys)
            {{-- The service desk opens a modal, so it is a <button>; every other
                 tile navigates, so it is an <a>. Written out twice rather than
                 interpolating the tag name — a dynamic closing tag is the kind
                 of thing that renders fine until someone edits one half of it. --}}
            @if($sys['key'] === 'servicedesk')
                <button type="button" class="card" id="itServiceDeskCard"
                        aria-label="Open {{ $sys['name'] }}">
                    <div class="icon-wrap">
                        @include('home.partials.system-icon', ['key' => $sys['key']])
                    </div>
                    <h3>{{ $sys['name'] }}</h3>
                    @if(! empty($sys['meta']))
                        <p class="meta">{{ $sys['meta'] }}</p>
                    @endif
                </button>
            @else
                <a class="card" href="{{ $sys['url'] }}" target="_blank" rel="noopener noreferrer"
                   aria-label="Open {{ $sys['name'] }}">
                    <div class="icon-wrap">
                        @include('home.partials.system-icon', ['key' => $sys['key']])
                    </div>
                    <h3>{{ $sys['name'] }}</h3>
                    @if(! empty($sys['meta']))
                        <p class="meta">{{ $sys['meta'] }}</p>
                    @endif
                </a>
            @endif
        @endforeach
    </div>

    {{-- ─── Quick access ──────────────────────────────────────── --}}
    <p class="section-label" style="margin-top:34px;">Quick access</p>
    <div class="grid-secondary">

        <a class="card payroll-card span-2" href="{{ $payrollUrl }}" target="_blank" rel="noopener noreferrer"
           aria-label="View payroll details">
            <div class="payroll-left">
                <h3>My Payroll</h3>
                <p class="meta">
                    @if($payday['days_left'] === 0)
                        Payday is today
                    @elseif($payday['days_left'] === 1)
                        Payday is tomorrow
                    @else
                        {{ $payday['days_left'] }} days until payday
                    @endif
                </p>
                <p class="sub">{{ $payday['date']->format('l, j F') }}</p>
            </div>
            <div class="ring-wrap">
                <svg width="96" height="96" viewBox="0 0 96 96" aria-hidden="true">
                    <circle class="ring-bg" cx="48" cy="48" r="40"></circle>
                    {{-- 2πr ≈ 251.2. Offset is the REMAINING arc, so a full ring
                         means payday has arrived. --}}
                    <circle class="ring-fg" cx="48" cy="48" r="40"
                            stroke-dasharray="251.2"
                            stroke-dashoffset="{{ round(251.2 * (1 - $payday['progress']), 1) }}"></circle>
                </svg>
                <div class="ring-center">
                    <span class="num">{{ $payday['days_left'] }}</span>
                    <span class="lbl">days left</span>
                </div>
            </div>
        </a>

        <a class="card span-1" href="{{ route('home.announcements') }}" aria-label="Open Company Calendar">
            <div class="icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 9.5h17M8 3.5v3M16 3.5v3" stroke-linecap="round"/></svg>
            </div>
            <h3>Company Calendar</h3>
            @if($events->isNotEmpty())
                <ul class="event-list">
                    @foreach($events->take(3) as $event)
                        <li>
                            <span class="ev-when">{{ $event->isToday() ? 'Today' : $event->starts_at->format('j M') }}</span>
                            <span class="ev-what">{{ $event->subject }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="meta">Company events &amp; holidays</p>
            @endif
        </a>

        <a class="card span-1" href="{{ route('public.contacts') }}" aria-label="Open Employees Directory">
            <div class="icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="9" cy="8.5" r="2.6"/><path d="M4 18.2c.7-3 2.6-4.6 5-4.6s4.3 1.6 5 4.6" stroke-linecap="round"/><path d="M15.5 8.5h4.5M15.5 12h4.5M15.5 15.5h3" stroke-linecap="round"/></svg>
            </div>
            <h3>Employees Directory</h3>
            <p class="meta">Search staff &amp; extensions</p>
        </a>

        <a class="card span-2 announcement-card" href="{{ route('home.announcements') }}"
           aria-label="View Announcements">
            <div class="icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 10v4a1.4 1.4 0 0 0 1.4 1.4H6l4.4 3.4V5.2L6 8.6H4.4A1.4 1.4 0 0 0 3 10Z" stroke-linejoin="round"/><path d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11" stroke-linecap="round"/></svg>
                @if($unreadCount > 0)
                    <span class="badge red bell-badge pulse">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" aria-hidden="true"><path d="M12 4.5a4.5 4.5 0 0 0-4.5 4.5v2.6c0 .7-.3 1.4-.8 1.9l-.2.2h11l-.2-.2c-.5-.5-.8-1.2-.8-1.9V9A4.5 4.5 0 0 0 12 4.5Z" stroke-linejoin="round"/></svg>
                    </span>
                @endif
            </div>
            <h3>Announcements</h3>
            <div class="announcement-preview">
                @if($unreadCount > 0)
                    <span class="new-pill">{{ $unreadCount }} NEW</span>
                @endif
                <p class="meta" style="margin-top:0;">
                    {{ $announcements->first()?->title ?: 'Nothing new right now' }}
                </p>
            </div>
        </a>

        @if($security)
            <div class="card span-2 security-card" tabindex="0" role="group" aria-label="Your security score">
                <div class="icon-wrap security-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 3.5 19 6v5.4c0 4.3-2.9 7.5-7 8.6-4.1-1.1-7-4.3-7-8.6V6l7-2.5Z" stroke-linejoin="round"/><path d="M9 12.2l2 2 4-4.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <h3>Security Score</h3>
                <div class="security-stats">
                    <div class="stat">
                        <span class="stat-num {{ $security->riskClass() }}">{{ $security->riskLabel() }}</span>
                        <span class="stat-lbl">Risk Score</span>
                    </div>
                    <div class="stat">
                        <span class="stat-num">{{ $security->phish_fail_count }}</span>
                        <span class="stat-lbl">Phishing Fails</span>
                    </div>
                    @if($security->trainings_outstanding > 0)
                        <div class="stat">
                            <span class="stat-num risk-medium">{{ $security->trainings_outstanding }}</span>
                            <span class="stat-lbl">Training Due</span>
                        </div>
                    @endif
                </div>
                <p class="meta small">Source: KnowBe4 · only you can see this</p>
            </div>
        @endif

    </div>
</div>

{{-- ─── Employee ID card ──────────────────────────────────────── --}}
<aside class="id-card-aside">
    <div class="id-card">
        <div class="id-card-top">
            <img src="{{ asset('images/brand/samir-logo.png') }}" alt="Samir Group" class="id-card-logo">
            <button type="button" class="eye-toggle" id="idCardEyeToggle" aria-pressed="false"
                    aria-label="Reveal ID card details">
                <svg class="eye-icon eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.8"/></svg>
                <svg class="eye-icon eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 3l18 18" stroke-linecap="round"/><path d="M10.6 6.1A9.7 9.7 0 0 1 12 6c6 0 9.5 6 9.5 6a17 17 0 0 1-3.4 4M6.4 8.1A17 17 0 0 0 2.5 12S6 18 12 18a9.4 9.4 0 0 0 3.3-.6"/></svg>
            </button>
        </div>

        <div class="id-card-label">NAME</div>
        <div class="id-card-name id-blur">{{ $employee?->name ?: $user->name }}</div>

        @if($employee?->job_title)
            <div class="id-card-field">
                <div class="id-card-label">TITLE</div>
                <div class="id-card-value id-blur">{{ $employee->job_title }}</div>
            </div>
        @endif

        @if($employee?->department || $employee?->branch)
            <div class="id-card-field">
                <div class="id-card-label">DEPT</div>
                <div class="id-card-value id-blur">
                    {{ collect([$employee->department?->name, $employee->branch?->name])->filter()->implode(' — ') }}
                </div>
            </div>
        @endif

        <div class="id-card-field">
            <div class="id-card-label">EMAIL</div>
            <div class="id-card-value id-blur">{{ $employee?->email ?: $user->email }}</div>
        </div>

        @if($employee?->office_location)
            <div class="id-card-field">
                <div class="id-card-label">OFFICE</div>
                <div class="id-card-value id-blur">{{ $employee->office_location }}</div>
            </div>
        @endif

        @if($employee?->extension_number)
            <div class="id-card-field">
                <div class="id-card-label">EXTENSION</div>
                <div class="id-card-value id-blur">{{ $employee->extension_number }}</div>
            </div>
        @endif

        @if($cardToken)
            <div class="wallet-section">
                {{-- Apple Wallet: a direct .pkpass download. Hidden rather than
                     shown-and-broken when the pass certificates are not set up,
                     since a 503 from a big red button reads as a fault. --}}
                @if($walletAvailable)
                    <a class="wallet-btn" href="{{ route('home.card.wallet') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 10h18M16.5 14.5h2" stroke-linecap="round"/></svg>
                        Add to Apple Wallet
                    </a>
                @endif

                {{-- The QR points at the business-card subdomain, which is the
                     canonical public URL for a card and the one to hand out. --}}
                <button type="button" class="wallet-btn wallet-btn-secondary" id="showCardQrBtn"
                        aria-haspopup="dialog" aria-controls="walletModalOverlay">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><path d="M13.5 13.5h3v3M20.5 13.5v3M17 20.5h3.5V17M13.5 20.5h.01" stroke-linecap="round"/></svg>
                    Share My Card (QR)
                </button>
            </div>
        @endif

        <p class="id-card-hint">
            @if($employee)
                Tap the eye icon to reveal your ID
            @else
                We could not find your HR record yet — showing your sign-in details
            @endif
        </p>
    </div>
</aside>

</div>

@include('home.partials.ticket-modal')

@if($cardToken)
    @include('home.partials.wallet-modal', ['cardUrl' => $cardUrl, 'employee' => $employee, 'user' => $user])
@endif

@endsection

@push('scripts')
<script>
(function () {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  // ─── ID card reveal ──────────────────────────────────────────
  // Blurred by default: this page sits open on unattended desks, and the card
  // carries a name, title, office and extension.
  var eyeToggle = document.getElementById('idCardEyeToggle');
  if (eyeToggle) {
    eyeToggle.addEventListener('click', function () {
      var revealed = eyeToggle.classList.toggle('revealed');
      eyeToggle.setAttribute('aria-pressed', revealed ? 'true' : 'false');
      eyeToggle.setAttribute('aria-label', revealed ? 'Hide ID card details' : 'Reveal ID card details');
      document.querySelectorAll('.id-blur').forEach(function (el) {
        el.classList.toggle('revealed', revealed);
      });
    });
  }

  // ─── Announcement slider ─────────────────────────────────────
  var slider = document.getElementById('annSlider');
  if (slider) {
    var slides = Array.prototype.slice.call(slider.querySelectorAll('.ann-slide'));
    var dots = Array.prototype.slice.call(slider.querySelectorAll('.ann-dot'));
    var current = 0;
    var timer = null;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var seen = Object.create(null);
    var pendingRead = [];

    function markSeen(index) {
      var slide = slides[index];
      if (!slide) return;
      var id = slide.getAttribute('data-id');
      if (!id || seen[id]) return;
      seen[id] = true;
      pendingRead.push(Number(id));
    }

    // Batched, and only on the way out — marking each slide read as it rotates
    // would be one request per slide per visit for the whole company.
    function flushRead() {
      if (!pendingRead.length || !csrf) return;
      var ids = pendingRead.splice(0, pendingRead.length);
      var body = JSON.stringify({ ids: ids });
      var url = @json(route('home.announcements.read'));

      if (navigator.sendBeacon) {
        // sendBeacon cannot set headers, so the token rides in the body and the
        // route reads it from there via VerifyCsrfToken's _token fallback.
        var form = new FormData();
        form.append('_token', csrf);
        ids.forEach(function (id) { form.append('ids[]', id); });
        navigator.sendBeacon(url, form);
        return;
      }

      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: body,
        keepalive: true
      }).catch(function () { /* read state is not worth bothering anyone about */ });
    }

    function show(index) {
      current = (index + slides.length) % slides.length;
      slides.forEach(function (slide, i) {
        var active = i === current;
        slide.classList.toggle('active', active);
        if (active) {
          slide.removeAttribute('aria-hidden');
        } else {
          slide.setAttribute('aria-hidden', 'true');
        }
      });
      dots.forEach(function (dot, i) {
        dot.classList.toggle('active', i === current);
        if (i === current) {
          dot.setAttribute('aria-selected', 'true');
        } else {
          dot.removeAttribute('aria-selected');
        }
      });
      markSeen(current);
    }

    function start() {
      if (reduceMotion || slides.length < 2) return;
      stop();
      timer = setInterval(function () { show(current + 1); }, 7000);
    }
    function stop() {
      if (timer) { clearInterval(timer); timer = null; }
    }

    document.getElementById('annPrev')?.addEventListener('click', function () { show(current - 1); start(); });
    document.getElementById('annNext')?.addEventListener('click', function () { show(current + 1); start(); });
    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        show(Number(dot.getAttribute('data-index')) || 0);
        start();
      });
    });

    slider.addEventListener('mouseenter', stop);
    slider.addEventListener('mouseleave', start);
    slider.addEventListener('focusin', stop);
    slider.addEventListener('focusout', start);
    slider.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { show(current - 1); start(); }
      if (e.key === 'ArrowRight') { show(current + 1); start(); }
    });

    // Pause while the tab is hidden — this page stays open all day.
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { stop(); flushRead(); } else { start(); }
    });
    window.addEventListener('pagehide', flushRead);

    markSeen(0);
    start();
  }
})();
</script>
@endpush
