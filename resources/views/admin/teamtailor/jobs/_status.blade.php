{{-- A Teamtailor job's status as a badge. $jobStatus is TeamtailorApiService::jobStatus()'s word, or one stored before it ("open"). --}}
@php
    $st = \App\Services\Teamtailor\TeamtailorApiService::jobStatus(['status' => $jobStatus ?? null]);
    $class = match ($st) {
        'published' => 'bg-success-subtle text-success border border-success-subtle',
        'unlisted' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
        'archived' => 'bg-dark-subtle text-dark border',
        'draft', 'scheduled' => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
        default => 'bg-light text-dark border',
    };
@endphp
@if ($st === null)
    <span class="text-muted">—</span>
@else
    <span class="badge {{ $class }}">{{ ucfirst($st) }}</span>
@endif
