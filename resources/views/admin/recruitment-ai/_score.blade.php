{{-- A screening score as a coloured badge, in the same bands as the fit labels. --}}
@php
    $scoreValue = (int) $score;
    $scoreClass = match (true) {
        $scoreValue >= 85 => 'bg-success',
        $scoreValue >= 70 => 'bg-primary',
        $scoreValue >= 50 => 'bg-warning text-dark',
        default => 'bg-secondary',
    };
@endphp
<span class="badge {{ $scoreClass }} {{ $size ?? '' }}" title="{{ $title ?? 'AI screening score (0–100)' }}">{{ $scoreValue }}</span>
