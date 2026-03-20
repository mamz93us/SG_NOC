{{--
    Toner Gauge Component
    ─────────────────────────────────────────────────────────────────────────
    Renders a vertical ink-cartridge gauge for a single toner colour.

    Props:
        $color   (string)      — CSS colour for the fill (e.g. '#212529', '#0dcaf0')
        $label   (string)      — Display label ('Black', 'Cyan', …)
        $key     (string)      — Short key  ('K', 'C', 'M', 'Y')
        $level   (int|null)    — Fill level 0–100, null = unknown
        $width   (int)         — Column width in px (default 48)

    Usage:
        <x-toner-gauge color="#212529" label="Black" key="K" :level="$toner['K']" />
--}}

@props([
    'color' => '#212529',
    'label' => '',
    'key'   => '',
    'level' => null,
    'width' => 48,
])

@php
    $pct        = $level !== null ? max(0, min(100, (int) $level)) : null;
    $statusCls  = match(true) {
        $pct === null       => 'text-muted',
        $pct < 10           => 'text-danger fw-bold',
        $pct < 25           => 'text-warning fw-bold',
        default             => 'text-success',
    };
    $statusIcon = match(true) {
        $pct === null       => 'bi-question-circle',
        $pct < 10           => 'bi-exclamation-triangle-fill',
        $pct < 25           => 'bi-exclamation-circle-fill',
        default             => 'bi-check-circle-fill',
    };
    $bgColor    = ($key === 'K') ? '#343a40' : $color;
@endphp

<div class="toner-gauge-wrap text-center" style="width: {{ $width }}px;">
    {{-- Percentage label --}}
    <div class="small fw-bold mb-1 {{ $statusCls }}" style="font-size:.75rem; line-height:1.2;">
        @if($pct !== null)
            {{ $pct }}<span style="font-size:.6rem">%</span>
        @else
            <span class="text-muted">—</span>
        @endif
    </div>

    {{-- Cartridge tube --}}
    <div class="mx-auto position-relative overflow-hidden"
         style="width: 28px; height: 110px; border-radius: 14px 14px 8px 8px;
                background: #e9ecef; border: 2px solid rgba(0,0,0,.15);
                box-shadow: inset 0 2px 6px rgba(0,0,0,.1);">

        {{-- Fill --}}
        @if($pct !== null)
        <div class="position-absolute bottom-0 start-0 end-0 transition-all"
             style="height: {{ $pct }}%;
                    background: {{ $bgColor }};
                    opacity: .85;
                    transition: height .7s cubic-bezier(.25,.8,.25,1);
                    border-radius: 0 0 6px 6px;">
        </div>
        @endif

        {{-- 25% / 50% / 75% tick marks --}}
        @foreach([75,50,25] as $tick)
        <div class="position-absolute start-0 end-0"
             style="bottom: {{ $tick }}%; height: 1px; background: rgba(255,255,255,.35); z-index:1;"></div>
        @endforeach

        {{-- Shine overlay --}}
        <div class="position-absolute top-0 start-0 bottom-0"
             style="width: 30%; border-radius: 14px 0 0 8px;
                    background: linear-gradient(to right, rgba(255,255,255,.25), transparent);
                    pointer-events: none;"></div>
    </div>

    {{-- Status icon --}}
    <div class="{{ $statusCls }} mt-1" style="font-size:.75rem;">
        <i class="bi {{ $statusIcon }}"></i>
    </div>

    {{-- Colour key badge --}}
    <div class="toner-label mt-1" style="font-size:.65rem; font-weight:700; color:#6c757d; text-transform:uppercase; letter-spacing:.05em;">
        {{ $label }}
    </div>
</div>
