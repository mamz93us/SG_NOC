<?php

namespace App\Services\Finance;

use App\Models\ExchangeRate;
use Illuminate\Support\Carbon;

/**
 * Converts the finance reports' per-currency subtotals into one combined total.
 *
 * Rates come from `exchange_rates` — what finance actually keyed in. Where a
 * currency has no row, the indicative figure in config/currency.php is used and
 * the result is flagged `reviewed = false` all the way to the screen and the
 * CSV, so a number nobody has checked can never be mistaken for one that has.
 *
 * Nothing here calls out to a rate API, deliberately. The NOC cannot resolve
 * most public hosts (split-brain DNS, see CLAUDE.md), and more importantly a
 * report that quietly returns a different total on Tuesday than it did on
 * Monday is not something finance can reconcile against. The rate is a stored
 * decision with a date and an owner.
 *
 * A combined total is always shown ALONGSIDE the per-currency subtotals, never
 * instead of them: the subtotals are the fact, the combined figure is an
 * estimate at one rate on one day.
 */
class CurrencyConverter
{
    /** @var array<string, array{rate: float, reviewed: bool, date: ?Carbon, source: ?string}>|null */
    private ?array $rates = null;

    /**
     * Every known rate, keyed by currency: units of that currency per 1 base.
     *
     * @return array<string, array{rate: float, reviewed: bool, date: ?Carbon, source: ?string}>
     */
    public function rates(): array
    {
        if ($this->rates !== null) {
            return $this->rates;
        }

        $rates = [];

        foreach ((array) config('currency.indicative_rates', []) as $currency => $rate) {
            $rates[strtoupper($currency)] = [
                'rate' => (float) $rate,
                'reviewed' => false,
                'date' => null,
                'source' => 'Indicative default (config/currency.php)',
            ];
        }

        // A keyed-in rate always beats the config fallback.
        foreach (ExchangeRate::all() as $row) {
            $rate = (float) $row->units_per_base;
            if ($rate <= 0) {
                continue;
            }

            $rates[strtoupper($row->currency)] = [
                'rate' => $rate,
                'reviewed' => true,
                'date' => $row->rate_date,
                'source' => $row->source,
            ];
        }

        return $this->rates = $rates;
    }

    public function has(string $currency): bool
    {
        return isset($this->rates()[strtoupper($currency)]);
    }

    /** True when a rate exists AND a human entered it. */
    public function isReviewed(string $currency): bool
    {
        return $this->rates()[strtoupper($currency)]['reviewed'] ?? false;
    }

    /** Convert one amount, or null when either side has no usable rate. */
    public function convert(float $amount, string $from, string $to): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return round($amount, 2);
        }

        $rates = $this->rates();
        if (! isset($rates[$from], $rates[$to]) || $rates[$from]['rate'] <= 0) {
            return null;
        }

        // Through the base: amount -> base -> target.
        return round($amount / $rates[$from]['rate'] * $rates[$to]['rate'], 2);
    }

    /**
     * Convert a currency => amount map into a single total.
     *
     * Reports the parts as well as the sum, because a total that quietly
     * omitted an unconvertible currency would understate what is owed. When
     * anything is missing, `total` is still the sum of what converted — the
     * caller is expected to show `missing` next to it rather than hide it.
     *
     * @param  array<string, float>  $amountsByCurrency
     * @return array{
     *     total: float,
     *     currency: string,
     *     lines: array<int, array{currency: string, amount: float, converted: ?float, rate: ?float, reviewed: bool, date: ?Carbon}>,
     *     missing: string[],
     *     all_reviewed: bool,
     *     oldest_date: ?Carbon
     * }
     */
    public function totalIn(array $amountsByCurrency, string $to): array
    {
        $to = strtoupper($to);
        $rates = $this->rates();

        $total = 0.0;
        $lines = [];
        $missing = [];
        $oldest = null;
        // Which rates this particular total actually leans on. An amount already
        // in the target currency leans on none — it is not converted at all.
        $ratesUsed = [];

        foreach ($amountsByCurrency as $currency => $amount) {
            $currency = strtoupper((string) $currency);
            $converted = $this->convert((float) $amount, $currency, $to);

            if ($converted === null) {
                $missing[] = $currency;
            } else {
                $total += $converted;

                if ($currency !== $to) {
                    // Both sides matter: everything is priced through the target,
                    // so an unreviewed target taints every converted line.
                    $ratesUsed[] = $currency;
                    $ratesUsed[] = $to;
                }
            }

            $date = $rates[$currency]['date'] ?? null;
            if ($date && (! $oldest || $date->lt($oldest))) {
                $oldest = $date;
            }

            $lines[] = [
                'currency' => $currency,
                'amount' => round((float) $amount, 2),
                'converted' => $converted,
                'rate' => $rates[$currency]['rate'] ?? null,
                'reviewed' => $rates[$currency]['reviewed'] ?? false,
                'date' => $date,
            ];
        }

        $allReviewed = $missing === [];
        foreach (array_unique($ratesUsed) as $used) {
            $allReviewed = $allReviewed && ($rates[$used]['reviewed'] ?? false);
        }

        return [
            'total' => round($total, 2),
            'currency' => $to,
            'lines' => $lines,
            'missing' => array_values(array_unique($missing)),
            'all_reviewed' => $allReviewed,
            'oldest_date' => $oldest,
        ];
    }
}
