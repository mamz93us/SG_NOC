{{--
    The rate provenance behind the combined total: which rate converted what,
    where it came from, and whether a human stands behind it.

    Expects: $combined (CurrencyConverter::totalIn output), $id (unique per page).
--}}
@if(count($combined['lines']) > 1 || ! $combined['all_reviewed'] || $combined['missing'])

@if($combined['missing'])
<div class="alert alert-danger py-2 small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>No exchange rate for {{ implode(', ', $combined['missing']) }}</strong> —
    {{ count($combined['missing']) === 1 ? 'that currency is' : 'those currencies are' }} left out of the combined
    total, so it is <strong>understated</strong>. The per-currency subtotals above are still complete.
    <a href="{{ route('admin.itam.exchange-rates.index') }}" class="alert-link">Set the rate</a>.
</div>
@elseif(! $combined['all_reviewed'])
<div class="alert alert-warning py-2 small d-print-none">
    <i class="bi bi-exclamation-triangle me-1"></i>
    The combined total uses <strong>indicative rates nobody has reviewed</strong> — fine for a rough figure, not for
    sending to finance. <a href="{{ route('admin.itam.exchange-rates.index') }}" class="alert-link">Enter the rates
    you book at</a>, then this total is marked reviewed.
</div>
@endif

<div class="mb-3">
    <button class="btn btn-sm btn-outline-secondary d-print-none" type="button" onclick="toggleFxBreakdown('{{ $id }}')">
        <i class="bi bi-calculator me-1"></i>How the combined total was reached
    </button>
    <div id="{{ $id }}" hidden class="mt-2">
        <table class="table table-sm table-bordered mb-0 bg-white" style="max-width:760px">
            <thead class="table-light">
                <tr>
                    <th>Currency</th>
                    <th class="text-end">Subtotal</th>
                    <th class="text-end">Rate (per 1 {{ config('currency.base', 'USD') }})</th>
                    <th class="text-end">In {{ $combined['currency'] }}</th>
                    <th>Basis</th>
                </tr>
            </thead>
            <tbody>
                @foreach($combined['lines'] as $line)
                <tr>
                    <td class="fw-semibold">{{ $line['currency'] }}</td>
                    <td class="text-end font-monospace">{{ number_format($line['amount'], 2) }}</td>
                    <td class="text-end font-monospace">{{ $line['rate'] !== null ? rtrim(rtrim(number_format($line['rate'], 6, '.', ''), '0'), '.') : '—' }}</td>
                    <td class="text-end font-monospace">
                        {{ $line['converted'] !== null ? number_format($line['converted'], 2) : '—' }}
                    </td>
                    <td class="small">
                        @if($line['currency'] === $combined['currency'])
                            <span class="text-muted">no conversion</span>
                        @elseif($line['converted'] === null)
                            <span class="badge bg-danger">no rate</span>
                        @elseif($line['reviewed'])
                            <span class="badge bg-success">reviewed</span>
                            @if($line['date'])<span class="text-muted">{{ $line['date']->format('d M Y') }}</span>@endif
                        @else
                            <span class="badge bg-warning text-dark">indicative</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="table-light">
                <tr>
                    <th colspan="3" class="text-end">Combined</th>
                    <th class="text-end font-monospace">{{ number_format($combined['total'], 2) }}</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endif
