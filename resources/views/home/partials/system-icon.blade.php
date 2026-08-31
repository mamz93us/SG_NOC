{{--
    Core-system tile icons, keyed off config('home_portal.core_systems.*.key').
    Inline SVG rather than an icon font: this page must render correctly on the
    first paint of a cold browser launch, with no webfont round trip.

    An unknown key falls through to a generic app icon so adding a system to
    config never produces an empty tile.
--}}
@switch($key)
    @case('servicedesk')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 6.5 12 13l9-6.5" stroke-linecap="round" stroke-linejoin="round"/><rect x="3" y="5" width="18" height="14" rx="2.2"/></svg>
        @break

    @case('oracle')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="6" r="2.1"/><circle cx="6" cy="16" r="2.1"/><circle cx="18" cy="16" r="2.1"/><path d="M12 8.2v3M9.3 14.6l-1.6-1M14.7 14.6l1.6-1M8.4 18l3.6-2M15.6 18l-3.6-2" stroke-linecap="round"/></svg>
        @break

    @case('hr')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4.5" width="18" height="15" rx="2.2"/><path d="M3 8.5h18" stroke-linecap="round"/><circle cx="9" cy="13.3" r="1.9"/><path d="M6 17.4c.5-1.6 1.7-2.4 3-2.4s2.5.8 3 2.4" stroke-linecap="round"/></svg>
        @break

    @case('salesforce')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6.4 17.6a4 4 0 0 1-.3-8 5 5 0 0 1 9.6-2.1 4.5 4.5 0 0 1 4.2 4.5 3.5 3.5 0 0 1-1 6.9" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="17.7" r=".7" fill="currentColor" stroke="none"/><circle cx="12.7" cy="17.7" r=".7" fill="currentColor" stroke="none"/><circle cx="16.4" cy="17.7" r=".7" fill="currentColor" stroke="none"/></svg>
        @break

    @case('arcmate')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 15.3a8 8 0 0 1 16 0" stroke-linecap="round"/><path d="M4 15.3h16" stroke-linecap="round"/><path d="M12 15.3 16.2 10" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="15.3" r="1.4"/></svg>
        @break

    @default
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.8"/></svg>
@endswitch
