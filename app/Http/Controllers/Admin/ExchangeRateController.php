<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Services\Finance\CurrencyConverter;
use App\Support\Currency;
use Illuminate\Http\Request;

/**
 * The rates behind the subscription reports' combined totals.
 *
 * Deliberately a keyed-in table and not a feed. Two reasons: the NOC cannot
 * reach most public hosts (split-brain DNS), and a report whose totals move on
 * their own between two runs cannot be reconciled by finance. A rate here is a
 * decision, with the date it applies to and the person who entered it.
 *
 * Until a currency is entered, the report falls back to the indicative figure
 * in config/currency.php and labels every total built on it as unreviewed.
 */
class ExchangeRateController extends Controller
{
    public function index(CurrencyConverter $converter)
    {
        $stored = ExchangeRate::with('updatedBy')->get()->keyBy(fn ($r) => strtoupper($r->currency));

        return view('admin.itam.exchange-rates', [
            'base' => strtoupper((string) config('currency.base', 'USD')),
            'currencies' => Currency::CODES,
            'stored' => $stored,
            'rates' => $converter->rates(),
        ]);
    }

    public function update(Request $request)
    {
        $base = strtoupper((string) config('currency.base', 'USD'));

        $data = $request->validate([
            'rates' => 'required|array',
            'rates.*.units_per_base' => 'nullable|numeric|gt:0',
            'rates.*.rate_date' => 'nullable|date',
            'rates.*.source' => 'nullable|string|max:150',
        ]);

        $saved = [];
        $cleared = [];

        foreach ($data['rates'] as $currency => $row) {
            $currency = strtoupper((string) $currency);
            if (! in_array($currency, Currency::CODES, true)) {
                continue;
            }

            // A blank rate means "forget what was keyed in and fall back to the
            // indicative default" — the report will then flag it as unreviewed
            // again, which is the honest state.
            if (($row['units_per_base'] ?? null) === null || $row['units_per_base'] === '') {
                if (ExchangeRate::where('currency', $currency)->delete()) {
                    $cleared[] = $currency;
                }

                continue;
            }

            // The base is 1 of itself by definition; storing anything else here
            // would make every cross-rate wrong at once.
            $value = $currency === $base ? 1.0 : (float) $row['units_per_base'];

            ExchangeRate::updateOrCreate(
                ['currency' => $currency],
                [
                    'units_per_base' => $value,
                    'rate_date' => $row['rate_date'] ?: now()->toDateString(),
                    'source' => $row['source'] ?: null,
                    'updated_by_user_id' => $request->user()?->id,
                ]
            );

            $saved[] = "{$currency}={$value}";
        }

        ActivityLog::log('Updated exchange rates', ['saved' => $saved, 'cleared' => $cleared]);

        $message = $saved ? 'Saved: '.implode(', ', $saved).'.' : 'No rates saved.';
        if ($cleared) {
            $message .= ' Reverted to indicative defaults: '.implode(', ', $cleared).'.';
        }

        return back()->with('success', $message);
    }
}
