{{--
    One year's Oracle balance: last year, this year so far, used and remaining,
    with whatever the parts do not explain. Shared by the Vacations person page
    and the Employee profile.

    $balance  ?\App\Models\Vacation\VacationBalance
    $year     int
    $compact  optional: two tiles a row, for a narrow column
--}}
@php
    $days = fn ($value) => \App\Models\Vacation\VacationBalance::days($value === null ? null : (float) $value);
    $adjustment = $balance?->otherAdjustments();
    $tileClass = ($compact ?? false) ? 'col-6' : 'col-6 col-md-3';
@endphp
@if ($balance && $balance->hasBalance())
    <div class="row g-3 text-center">
        @foreach ([
            ['Last year', 'carried over into '.$year, $balance->carryover, $balance->carryover < 0 ? 'text-danger' : ''],
            ['This year so far', 'earned up to '.$balance->as_of->format('d M'), $balance->accrued, ''],
            ['Used', 'days taken in '.$year, $balance->used, ''],
            ['Remaining', 'days left', $balance->balance, $balance->balance < 0 ? 'text-danger' : 'text-success'],
        ] as [$label, $hint, $value, $class])
            <div class="{{ $tileClass }}">
                <div class="border rounded-3 py-3 h-100">
                    <div class="small text-muted">{{ $label }}</div>
                    <div class="fs-3 fw-bold {{ $class }}">{{ $days($value) }}</div>
                    <div class="small text-muted">{{ $hint }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="small text-muted mt-3">
        <span class="font-monospace">
            {{ $days($balance->carryover ?? 0) }} + {{ $days($balance->accrued ?? 0) }} − {{ $days($balance->used ?? 0) }}
            @if ($adjustment)
                {{ $adjustment > 0 ? '+' : '−' }} {{ $days(abs($adjustment)) }}
            @endif
            = {{ $days($balance->balance) }}
        </span>
        (last year + this year so far − used{{ $adjustment ? ' + other adjustments' : '' }} = remaining)
    </div>

    @if ($adjustment)
        <div class="alert alert-info small mt-3 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            Oracle's remaining balance includes {{ $adjustment > 0 ? '+' : '' }}{{ $days($adjustment) }} days that are
            not in its carried-over, earned or used columns — an adjustment recorded in Oracle. Check it there
            before relying on it.
        </div>
    @endif
    @if ($balance->isStale())
        <div class="alert alert-warning small mt-3 mb-0">
            <i class="bi bi-hourglass-bottom me-1"></i>
            This balance is {{ (int) $balance->as_of->diffInDays(now()->startOfDay()) }} days old. Oracle adds leave every
            month, so what this person has earned — and what is left — has grown since.
        </div>
    @endif
@elseif ($balance)
    <div class="text-muted">
        Oracle has no leave plan for this person yet: every figure in its balance sheet is empty.
    </div>
@else
    <div class="text-muted">
        This person is not in Oracle's balance sheet for {{ $year }} — only their leave records came through.
    </div>
@endif
