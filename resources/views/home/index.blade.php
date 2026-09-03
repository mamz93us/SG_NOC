@extends('layouts.home')

@section('title', 'Samir Group | Employee Portal')

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
    @php
        // The service desk is rendered by the combined card in Quick access
        // instead — raising a ticket and tracking one belong together — so it
        // is filtered out here rather than removed from config, which the
        // Settings screen also reads.
        $serviceDesk = collect($coreSystems)->firstWhere('key', 'servicedesk');
        $otherSystems = collect($coreSystems)->reject(fn ($s) => $s['key'] === 'servicedesk');
    @endphp

    @if($otherSystems->isNotEmpty() || $webmailUrl)
        <p class="section-label">Quick access</p>
        <div class="grid-primary">
            @foreach($otherSystems as $sys)
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
            @endforeach

            {{-- Mail sits with the line-of-business systems rather than further
                 down: it is the one application everybody opens every day,
                 whatever they do. Blank in config hides it rather than
                 shipping a tile that goes nowhere. --}}
            @if($webmailUrl)
                <a class="card" href="{{ $webmailUrl }}" target="_blank" rel="noopener noreferrer"
                   aria-label="Open Outlook Mail on the web">
                    <div class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="2.2"/><path d="M3.2 6.6 12 12.8l8.8-6.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <h3>Outlook Mail</h3>
                    <p class="meta">Your inbox in the browser</p>
                </a>
            @endif
        </div>
    @endif

    {{-- ─── IT & Support ──────────────────────────────────────────
         Everything IT owns, in one place: raise a ticket, read the policy you
         are held to, find the manual, and see what is signed out to you. --}}
    <p class="section-label" style="margin-top:34px;">IT &amp; support</p>
    <div class="grid-secondary">

        {{-- Raising a ticket and tracking one are the same errand, so they share
             a card. A <div>, not a link: it holds two separate actions, and
             nesting a button inside an anchor is invalid and breaks keyboard
             use. The modal binds to #itServiceDeskCard, which is the button. --}}
        <div class="svc-card">
            <a class="svc-count" href="{{ route('home.tickets.index') }}"
               aria-label="View my tickets">
                <span class="n {{ $openTicketCount > 0 ? 'has-open' : '' }}">{{ $openTicketCount }}</span>
                <span class="l">{{ $openTicketCount === 1 ? 'Open ticket' : 'Open tickets' }}</span>
            </a>
            <div class="svc-body">
                <h3>IT Service Desk</h3>
                <p>
                    @if($openTicketCount > 0)
                        {{ $openTicketCount }} {{ \Illuminate\Support\Str::plural('request', $openTicketCount) }}
                        still with IT. Raise another, or see where yours stand.
                    @else
                        Nothing open right now. Raise a ticket and track it here.
                    @endif
                </p>
                <div class="svc-actions">
                    @if($serviceDesk)
                        <button type="button" class="svc-btn primary" id="itServiceDeskCard">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
                            Raise a ticket
                        </button>
                    @endif
                    <a class="svc-btn" href="{{ route('home.tickets.index') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h10" stroke-linecap="round"/></svg>
                        My tickets
                    </a>
                </div>
            </div>
        </div>

        {{-- Security score, next to the service desk: both are "how am I doing
             with IT" rather than a place to go. --}}
        @if($security)
            @php
                // Phish-Prone Percentage: the share of simulated phishing
                // emails this person failed, 0–100 straight from KnowBe4.
                // HIGHER IS WORSE, so the arc fills as it worsens.
                // r=44 → circumference 2πr ≈ 276.5.
                $ppp = $security->phish_prone_percentage;
                $pppDash = round(276.5 * (($ppp === null ? 0 : max(0, min(100, $ppp))) / 100), 1);
                $phished = $security->hasBeenPhished();
            @endphp
            <div class="risk-card" tabindex="0" role="group" aria-label="Your phishing test results">
                <div class="risk-head">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 3.5 19 6v5.4c0 4.3-2.9 7.5-7 8.6-4.1-1.1-7-4.3-7-8.6V6l7-2.5Z" stroke-linejoin="round"/><path d="M9 12.2l2 2 4-4.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <h3>Phish-Prone Score</h3>
                    <span class="src">KnowBe4 · only you see this</span>
                </div>

                <div class="risk-main">
                    <div class="risk-gauge">
                        <svg width="104" height="104" viewBox="0 0 104 104" aria-hidden="true">
                            <circle class="g-bg" cx="52" cy="52" r="44"></circle>
                            <circle class="g-fg {{ $security->pppClass() }}" cx="52" cy="52" r="44"
                                    stroke-dasharray="{{ $pppDash }} 276.5"></circle>
                        </svg>
                        <div class="g-center">
                            <span class="v {{ $security->pppClass() }}">{{ $security->pppLabel() }}@if($ppp !== null)<span style="font-size:15px">%</span>@endif</span>
                            <span class="u">phish-prone</span>
                        </div>
                    </div>

                    <div class="risk-side">
                        <div class="risk-band {{ $security->pppClass() }}">
                            @switch($security->pppBand())
                                @case('low') Low @break
                                @case('medium') Room to improve @break
                                @case('high') Needs attention @break
                                @default Not tested yet
                            @endswitch
                            <span class="hint">
                                @if(! $phished)
                                    You have not been sent a simulated phishing email yet.
                                @else
                                    You clicked, replied to or entered details on
                                    <strong>{{ $security->phish_fail_count }}</strong>
                                    of <strong>{{ $security->phish_sent_count }}</strong>
                                    simulated phishing {{ \Illuminate\Support\Str::plural('email', $security->phish_sent_count) }}.
                                @endif
                            </span>
                        </div>
                        <div class="risk-figs">
                            <div class="stat">
                                <span class="stat-num {{ $security->phish_fail_count > 0 ? 'risk-high' : '' }}">{{ $security->phish_fail_count }}</span>
                                <span class="stat-lbl">Phishing Fails</span>
                            </div>
                            <div class="stat">
                                <span class="stat-num {{ $security->trainings_outstanding > 0 ? 'risk-medium' : '' }}">{{ $security->trainings_outstanding }}</span>
                                <span class="stat-lbl">Training Due</span>
                            </div>
                        </div>
                    </div>
                </div>

                @if($security->trainings_outstanding > 0)
                    <div class="training-due">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 8.5v4.5M12 16.2v.05" stroke-linecap="round"/><path d="M10.3 4.3 2.9 17.1A2 2 0 0 0 4.6 20h14.8a2 2 0 0 0 1.7-2.9L13.7 4.3a2 2 0 0 0-3.4 0Z" stroke-linejoin="round"/></svg>
                        <span>
                            You have <strong>{{ $security->trainings_outstanding }}</strong>
                            security {{ \Illuminate\Support\Str::plural('course', $security->trainings_outstanding) }} outstanding.
                            @if($trainingUrl)
                                <a href="{{ $trainingUrl }}" target="_blank" rel="noopener noreferrer">Start now &rarr;</a>
                            @else
                                Check your KnowBe4 email for the link.
                            @endif
                        </span>
                    </div>
                @endif
            </div>
        @endif

        {{-- Documentation, manuals and the IT policies. Same page, same
             library — the two cards differ only by the category they open,
             because "where is the manual" and "what am I allowed to do" are
             two different errands even when the shelf is one. --}}
        <a class="card span-1" href="{{ route('home.documents') }}" aria-label="Open Documentation and Manuals">
            <div class="icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4H10l1.6 2H19a1.5 1.5 0 0 1 1.5 1.5v10A1.5 1.5 0 0 1 19 19H5.5A1.5 1.5 0 0 1 4 17.5Z" stroke-linejoin="round"/><path d="M8 11.5h8M8 14.5h5" stroke-linecap="round"/></svg>
            </div>
            <h3>Documentation &amp; Manuals</h3>
            <p class="meta">
                @if($docCounts['documents'] > 0)
                    {{ $docCounts['documents'] }} {{ \Illuminate\Support\Str::plural('document', $docCounts['documents']) }} — guides, manuals &amp; forms
                @else
                    How-to guides, manuals &amp; forms
                @endif
            </p>
        </a>

        <a class="card span-1" href="{{ route('home.documents', ['category' => 'policy']) }}"
           aria-label="Open IT Policies">
            <div class="icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 3.5 19 6v5.4c0 4.3-2.9 7.5-7 8.6-4.1-1.1-7-4.3-7-8.6V6l7-2.5Z" stroke-linejoin="round"/><path d="M9.2 11.8h5.6M9.2 14.4h3.6" stroke-linecap="round"/></svg>
                @if($docCounts['policies'] > 0)
                    <span class="badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" aria-hidden="true"><path d="M5 12.5 10 17 19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                @endif
            </div>
            <h3>IT Policy</h3>
            <p class="meta">
                @if($docCounts['policies'] > 0)
                    {{ $docCounts['policies'] }} {{ \Illuminate\Support\Str::plural('policy', $docCounts['policies']) }} that apply to you
                @else
                    Acceptable use &amp; security rules
                @endif
            </p>
        </a>

        <a class="card span-2" href="{{ route('home.assets') }}" aria-label="View My Assets">
            <div class="icon-wrap">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="2.5" y="4.5" width="19" height="12" rx="2"/><path d="M2 19.5h20" stroke-linecap="round"/></svg>
                @if($assetCount > 0)
                    <span class="badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" aria-hidden="true"><path d="M5 12.5 10 17 19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                @endif
            </div>
            <h3>My Assets</h3>
            <p class="meta">
                @if($assetCount > 0)
                    {{ $assetCount }} {{ \Illuminate\Support\Str::plural('item', $assetCount) }} assigned to you — laptops, phones, accessories &amp; licences
                @else
                    Devices &amp; equipment assigned to you
                @endif
            </p>
        </a>

    </div>

    {{-- ─── Company ───────────────────────────────────────────────
         The people-and-place half of the portal: what is being announced,
         when payday is, what is on the calendar, and who to call. --}}
    <p class="section-label" style="margin-top:34px;">Company</p>
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

        <a class="card span-2" href="{{ route('home.announcements') }}" aria-label="Open Company Calendar">
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

        <a class="card span-2" href="{{ route('home.directory') }}" aria-label="Open Employees Directory">
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
                {{-- A .pkpass is useless on the Windows PC this page is open on —
                     the pass has to reach the handset. So this opens a QR the
                     phone scans, rather than downloading here. The direct link
                     is still offered inside the modal for anyone already on a
                     Mac or iPhone. --}}
                @if($walletAvailable && $walletQrUrl)
                    <button type="button" class="wallet-btn" id="addToWalletBtn"
                            aria-haspopup="dialog" aria-controls="walletQrModal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 10h18M16.5 14.5h2" stroke-linecap="round"/></svg>
                        Add to Apple Wallet
                    </button>
                @endif

                {{-- Samsung, on the same terms as Apple: the card only means
                     anything on the handset, so this opens a QR too. --}}
                @if($samsungAvailable && $samsungQrUrl)
                    <button type="button" class="wallet-btn" id="addToSamsungBtn"
                            aria-haspopup="dialog" aria-controls="samsungQrModal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 10h18M16.5 14.5h2" stroke-linecap="round"/></svg>
                        Add to Samsung Wallet
                    </button>
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
    @include('home.partials.qr-modal', [
        'id' => 'shareCardModal',
        'triggerId' => 'showCardQrBtn',
        'title' => 'My Digital Card',
        'description' => 'Let someone scan this to open your business card — contact details, extension, and a one-tap save to their phone.',
        'url' => $cardUrl,
        'caption' => $employee?->name ?: $user->name,
        'footnote' => preg_replace('#^https?://#', '', $cardUrl),
    ])

    @if($walletAvailable && $walletQrUrl)
        @include('home.partials.qr-modal', [
            'id' => 'walletQrModal',
            'triggerId' => 'addToWalletBtn',
            'title' => 'Add to Apple Wallet',
            'description' => 'Scan this with your iPhone camera to add your employee card to Apple Wallet. The pass cannot be added from this computer — it has to go on the phone.',
            'url' => $walletQrUrl,
            'caption' => $employee?->name ?: $user->name,
            'footnote' => 'This code is personal to you and expires in 15 minutes. Reload the page for a fresh one.',
        ])
    @endif

    @if($samsungAvailable && $samsungQrUrl)
        @include('home.partials.qr-modal', [
            'id' => 'samsungQrModal',
            'triggerId' => 'addToSamsungBtn',
            'title' => 'Add to Samsung Wallet',
            'description' => 'Scan this with your Samsung phone to add your employee card to Samsung Wallet. It cannot be added from this computer — the card has to go on the phone.',
            'url' => $samsungQrUrl,
            'caption' => $employee?->name ?: $user->name,
            'footnote' => 'This code is personal to you and expires in 15 minutes. Reload the page for a fresh one.',
        ])
    @endif
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

  // ─── QR modals (share card + Apple Wallet) ───────────────────
  // One handler for every .wallet-modal-overlay; each carries the id of the
  // button that opens it, so adding another modal needs no new JS.
  document.querySelectorAll('.wallet-modal-overlay').forEach(function (overlay) {
    var trigger = document.getElementById(overlay.getAttribute('data-trigger'));
    if (!trigger) return;

    var lastFocused = null;

    function open() {
      lastFocused = document.activeElement;
      overlay.classList.add('open');
      overlay.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      overlay.querySelector('[data-close-modal]')?.focus();
    }

    function close() {
      overlay.classList.remove('open');
      overlay.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (lastFocused && lastFocused.focus) lastFocused.focus();
    }

    trigger.addEventListener('click', open);
    overlay.querySelector('[data-close-modal]')?.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay.classList.contains('open')) close();
    });
  });

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
