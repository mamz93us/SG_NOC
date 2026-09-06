{{--
    Combined total in one currency, shown ALONGSIDE the per-currency subtotals
    and never instead of them: the subtotals are the fact, this is an estimate
    at one rate on one day. Every figure resting on a rate nobody reviewed says
    so — that distinction is the whole reason this partial exists.

    Expects: $combined (CurrencyConverter::totalIn output), $label.
--}}
<div class="card border-0 shadow-sm h-100 {{ $combined['all_reviewed'] ? 'border-start border-4 border-success' : '' }}">
    <div class="card-body py-3 text-center">
        <div class="h3 fw-bold mb-0 {{ $combined['all_reviewed'] ? 'text-success' : 'text-secondary' }}">
            ≈ {{ $combined['currency'] }} {{ number_format($combined['total'], 2) }}
        </div>
        <div class="small text-muted">{{ $label }} — combined</div>

        @if($combined['all_reviewed'])
            <span class="badge bg-success mt-2">Reviewed rates</span>
        @else
            <span class="badge bg-warning text-dark mt-2">Indicative rates</span>
        @endif

        @if($combined['oldest_date'])
            <div class="small text-muted mt-1">rates from {{ $combined['oldest_date']->format('d M Y') }}</div>
        @endif
    </div>
</div>

@once
@push('scripts')
<script>
// Toggling the per-currency conversion breakdown open/closed.
function toggleFxBreakdown(id) {
    const el = document.getElementById(id);
    if (el) el.hidden = !el.hidden;
}
</script>
@endpush
@endonce
