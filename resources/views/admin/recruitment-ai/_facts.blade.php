{{-- An applicant's salary (from their answers) and distance to the office (the AI's estimate) on one line. Needs $screening and $job. --}}
@php
    $factsExpected = $screening->salaryFigure('expected');
    $factsCurrent = $screening->currentSalaryLabel();
    $factsKm = $screening->distanceKm();
    $factsLivesIn = $screening->livesIn();
    $factsMoves = $screening->relocationNeeded() === 'yes';
@endphp
@if ($factsExpected || $factsCurrent || $factsKm !== null || $factsLivesIn || $factsMoves)
    <div class="small mt-1 d-flex flex-wrap align-items-center column-gap-3 row-gap-1">
        @if ($factsExpected)
            <span title="Expected salary, from their answers{{ ! empty($factsExpected['from']) ? ' ('.$factsExpected['from'].')' : '' }}: {{ $factsExpected['text'] ?? '' }}">
                <i class="bi bi-cash-coin text-muted me-1"></i>Expects <strong>{{ $screening->expectedSalaryLabel() }}</strong>
                @if ($screening->aboveBudget($job))
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1">Above budget</span>
                @endif
            </span>
        @endif
        @if ($factsCurrent)
            <span title="Current salary, from their answers">Now <strong>{{ $factsCurrent }}</strong></span>
        @endif
        @if ($factsKm !== null)
            <span title="Estimated by the AI{{ $factsLivesIn ? ' from '.$factsLivesIn : '' }}">
                <i class="bi bi-geo-alt text-muted me-1"></i>~{{ number_format($factsKm) }} km to {{ $screening->distanceOffice() ?? 'the office' }}
            </span>
        @elseif ($factsLivesIn)
            <span><i class="bi bi-geo-alt text-muted me-1"></i>Lives in {{ $factsLivesIn }}</span>
        @endif
        @if ($factsMoves)
            <span class="badge bg-light text-body border">Would move city</span>
        @endif
    </div>
@endif
