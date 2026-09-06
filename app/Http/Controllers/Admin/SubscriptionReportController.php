<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Employee;
use App\Models\License;
use App\Models\LicenseAssignment;
use App\Services\Finance\CurrencyConverter;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance-facing reports over recurring licences (AI tools and any other
 * subscription).
 *
 * Two reports, because finance asks two different questions and the same
 * number cannot answer both:
 *
 *  - Usage   — who is holding a seat and what does that seat cost per month.
 *              An annual licence is divided by 12 here. This is a run rate, a
 *              budget figure. Nothing is paid on these numbers.
 *  - Payments — what actually has to leave the bank in a given month. Only
 *              licences whose renewal date lands inside that month appear, at
 *              their full charge amount. This is the payable.
 *
 * Adding a run rate to a payable would double-count, so the two never share a
 * total, and each page says which of the two it is showing.
 *
 * Per-currency subtotals are the fact and are always shown. A combined total in
 * one chosen currency is shown ALONGSIDE them, never instead: it is an estimate
 * at one rate on one day. The rate is a stored decision with a date and an owner
 * (see CurrencyConverter) — nothing fetches a live rate, because a report that
 * returns a different total on Tuesday than on Monday cannot be reconciled. Any
 * total resting on a rate nobody has reviewed says so, on screen and in the CSV.
 */
class SubscriptionReportController extends Controller
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    /**
     * Seat-level usage and monthly run rate.
     */
    public function usage(Request $request)
    {
        $month = $this->resolveMonth($request);
        $type = $this->resolveType($request);
        $display = $this->resolveDisplayCurrency($request);

        $licenses = $this->recurringLicenses($type);
        $rows = $this->seatRows($licenses, $month);

        if ($request->boolean('csv')) {
            return $this->streamCsv(
                'ai-subscription-usage-'.$month->format('Y-m'),
                $rows,
                ['Service', 'Vendor', 'Plan', 'User', 'Email', 'Seat Status', 'Currency',
                    'Cost / Seat / Cycle', 'Billing Cycle', 'Monthly Cost / Seat',
                    "Monthly Cost / Seat ({$display})", 'Rate Basis', 'Renews This Month',
                    'Payment Method', 'Paid From'],
                fn (array $r) => [
                    $r['service'], $r['vendor'], $r['plan'], $r['user'], $r['email'], $r['seat_status'],
                    $r['currency'], $r['cost_per_seat'], $r['billing_cycle'], $r['monthly_per_seat'],
                    $this->converter->convert((float) $r['monthly_per_seat'], $r['currency'], $display),
                    $this->rateBasis($r['currency'], $display),
                    $r['renewal_date']?->format('Y-m-d'), $r['payment_method'], $r['payment_account'],
                ]
            );
        }

        // Run rate for the month, per currency — an annual licence contributes
        // a twelfth. Counts every purchased seat, not just the assigned ones:
        // an empty seat is still invoiced.
        $runRateByCurrency = $this->sumByCurrency($rows, 'monthly_per_seat');
        $summary = [
            'services' => $licenses->count(),
            'seats' => $rows->count(),
            'assigned_seats' => $rows->where('seat_status', 'Assigned')->count(),
            'unassigned_seats' => $rows->where('seat_status', 'Unassigned')->count(),
        ];

        // One person can hold seats on several services — this is the "what is
        // each employee costing us in AI tools" cut finance always asks for next.
        $byUser = $rows
            ->groupBy(fn ($r) => $r['email'] ?: $r['user'])
            ->map(fn (Collection $g) => [
                'user' => $g->first()['user'],
                'email' => $g->first()['email'],
                'services' => $g->pluck('service')->all(),
                'by_currency' => $this->sumByCurrency($g, 'monthly_per_seat'),
            ])
            ->sortBy('user')
            ->values();

        return view('admin.itam.reports.subscriptions-usage', [
            'month' => $month,
            'type' => $type,
            'rows' => $rows->sortBy([['service', 'asc'], ['user', 'asc']])->values(),
            'byUser' => $byUser,
            'runRateByCurrency' => $runRateByCurrency,
            'summary' => $summary,
            'monthOptions' => $this->monthOptions($month),
            'display' => $display,
            'displayOptions' => Currency::CODES,
            'combined' => $this->converter->totalIn($runRateByCurrency, $display),
            'converter' => $this->converter,
        ]);
    }

    /**
     * What is actually charged in the selected month, split by how it is paid.
     */
    public function payments(Request $request)
    {
        $month = $this->resolveMonth($request);
        $type = $this->resolveType($request, default: 'all');
        $display = $this->resolveDisplayCurrency($request);

        $licenses = $this->recurringLicenses($type);

        // Licences that cannot be scheduled at all — no renewal date means no
        // month can ever claim them. Surfaced rather than silently dropped.
        $unscheduled = $licenses->filter(fn (License $l) => ! $l->expiry_date)->values();

        $due = $licenses
            ->map(function (License $l) use ($month) {
                $date = $l->renewalDateIn($month);
                if (! $date) {
                    return null;
                }

                return [
                    'license' => $l,
                    'due_date' => $date,
                    'service' => $l->license_name,
                    'vendor' => $l->vendorDisplay() ?: '—',
                    'seats' => (int) $l->seats,
                    'currency' => $l->currency ?: Currency::DEFAULT,
                    'amount' => $l->chargeAmount() ?? 0.0,
                    'cost_per_seat' => $l->cost !== null ? (float) $l->cost : null,
                    'billing_cycle' => $l->billingCycleLabel(),
                    'payment_method' => $l->paymentMethodLabel(),
                    'payment_method_key' => $l->payment_method,
                    'payment_account' => $l->payment_account,
                    'auto_charged' => $l->isAutoCharged(),
                    'type' => $l->typeLabel(),
                ];
            })
            ->filter()
            ->sortBy('due_date')
            ->values();

        if ($request->boolean('csv')) {
            return $this->streamCsv(
                'subscription-payments-'.$month->format('Y-m'),
                $due,
                ['Due Date', 'Service', 'Vendor', 'Type', 'Billing Cycle', 'Seats',
                    'Cost / Seat', 'Currency', 'Amount Due', "Amount Due ({$display})", 'Rate Basis',
                    'Payment Method', 'Paid From', 'Action Needed'],
                fn (array $r) => [
                    $r['due_date']->format('Y-m-d'), $r['service'], $r['vendor'], $r['type'],
                    $r['billing_cycle'], $r['seats'], $r['cost_per_seat'], $r['currency'],
                    number_format($r['amount'], 2, '.', ''),
                    $this->converter->convert((float) $r['amount'], $r['currency'], $display),
                    $this->rateBasis($r['currency'], $display),
                    $r['payment_method'] ?? 'NOT SET',
                    $r['payment_account'] ?? '',
                    $r['payment_method'] === null ? 'Set payment method' : ($r['auto_charged'] ? 'No — card auto-charges' : 'Yes — raise payment'),
                ]
            );
        }

        // Grouped the way the payment run is actually executed: everything on a
        // card needs nothing but a funded card, everything else needs somebody
        // to raise a transfer. Missing methods sort first — they are the blocker.
        $groups = $due
            ->groupBy(fn ($r) => $r['payment_method_key'] ?? '__unset')
            ->map(fn (Collection $g, $key) => [
                'key' => $key,
                'label' => $key === '__unset' ? 'Payment method not set' : ($g->first()['payment_method'] ?? ucfirst((string) $key)),
                'auto_charged' => $key !== '__unset' && $g->first()['auto_charged'],
                'rows' => $g->values(),
                'by_currency' => $this->sumByCurrency($g, 'amount'),
                'combined' => $this->converter->totalIn($this->sumByCurrency($g, 'amount'), $display),
            ])
            ->sortBy(fn ($g) => $g['key'] === '__unset' ? 0 : ($g['auto_charged'] ? 2 : 1))
            ->values();

        return view('admin.itam.reports.subscription-payments', [
            'month' => $month,
            'type' => $type,
            'due' => $due,
            'groups' => $groups,
            'totalByCurrency' => $totalByCurrency = $this->sumByCurrency($due, 'amount'),
            'actionByCurrency' => $actionByCurrency = $this->sumByCurrency($due->reject(fn ($r) => $r['auto_charged']), 'amount'),
            'display' => $display,
            'displayOptions' => Currency::CODES,
            'combined' => $this->converter->totalIn($totalByCurrency, $display),
            'combinedAction' => $this->converter->totalIn($actionByCurrency, $display),
            'converter' => $this->converter,
            'missingMethod' => $due->whereNull('payment_method_key')->values(),
            'unscheduled' => $unscheduled,
            'monthOptions' => $this->monthOptions($month),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Shared
    // ─────────────────────────────────────────────────────────────

    /** Every recurring licence, with its assignments eager-loaded. */
    private function recurringLicenses(string $type): Collection
    {
        return License::query()
            ->recurring()
            ->when($type !== 'all', fn ($q) => $q->where('license_type', $type))
            ->with(['supplier', 'assignments.assignable'])
            ->orderBy('license_name')
            ->get();
    }

    /**
     * One row per purchased seat: the assignments first, then a placeholder row
     * for each seat nobody holds. Unassigned seats are rows and not a footnote
     * because they are billed at exactly the same rate as used ones — they are
     * the whole reason this report gets read.
     */
    private function seatRows(Collection $licenses, CarbonImmutable $month): Collection
    {
        $rows = collect();

        foreach ($licenses as $license) {
            $base = [
                'service' => $license->license_name,
                'vendor' => $license->vendorDisplay() ?: '—',
                'plan' => $license->typeLabel(),
                'currency' => $license->currency ?: Currency::DEFAULT,
                'cost_per_seat' => $license->cost !== null ? (float) $license->cost : null,
                'billing_cycle' => $license->billingCycleLabel(),
                'monthly_per_seat' => $license->monthlyRunRatePerSeat() ?? 0.0,
                'renewal_date' => $license->renewalDateIn($month),
                'payment_method' => $license->paymentMethodLabel(),
                'payment_account' => $license->payment_account,
                'license_id' => $license->id,
            ];

            foreach ($license->assignments as $assignment) {
                $rows->push($base + [
                    'user' => $this->assigneeName($assignment),
                    'email' => $this->assigneeEmail($assignment),
                    'seat_status' => 'Assigned',
                    'assigned_date' => $assignment->assigned_date,
                ]);
            }

            $spare = max(0, (int) $license->seats - $license->assignments->count());
            for ($i = 0; $i < $spare; $i++) {
                $rows->push($base + [
                    'user' => '— unassigned seat —',
                    'email' => '',
                    'seat_status' => 'Unassigned',
                    'assigned_date' => null,
                ]);
            }
        }

        return $rows;
    }

    private function assigneeName(LicenseAssignment $assignment): string
    {
        $a = $assignment->assignable;
        if ($a instanceof Employee || $a instanceof Device) {
            return (string) $a->name;
        }

        return '— deleted record —';
    }

    private function assigneeEmail(LicenseAssignment $assignment): string
    {
        $a = $assignment->assignable;

        return $a instanceof Employee ? (string) ($a->email ?? '') : '';
    }

    /**
     * Sum one numeric key per currency, ordered the way the currency list is.
     * Currencies are never merged — see the class docblock.
     *
     * @return array<string, float>
     */
    private function sumByCurrency(Collection $rows, string $key): array
    {
        $sums = $rows
            ->groupBy('currency')
            ->map(fn (Collection $g) => round($g->sum(fn ($r) => (float) ($r[$key] ?? 0)), 2))
            ->filter(fn ($v) => $v > 0)
            ->all();

        uksort($sums, function ($a, $b) {
            $order = array_flip(Currency::CODES);

            return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
        });

        return $sums;
    }

    /** Which currency the combined totals are expressed in. */
    private function resolveDisplayCurrency(Request $request): string
    {
        $code = strtoupper((string) $request->query('display', ''));

        if (in_array($code, Currency::CODES, true)) {
            return $code;
        }

        $default = strtoupper((string) config('currency.display_default', Currency::DEFAULT));

        return in_array($default, Currency::CODES, true) ? $default : Currency::DEFAULT;
    }

    /**
     * Says, per row, whether its converted figure rests on a rate a human
     * entered. Goes into the CSV so the distinction survives the export —
     * finance reads the spreadsheet, not the screen it came from.
     */
    private function rateBasis(string $from, string $to): string
    {
        if (strtoupper($from) === strtoupper($to)) {
            return 'No conversion';
        }
        if (! $this->converter->has($from) || ! $this->converter->has($to)) {
            return 'NO RATE — not converted';
        }

        return $this->converter->isReviewed($from) && $this->converter->isReviewed($to)
            ? 'Reviewed rate'
            : 'INDICATIVE rate — not reviewed';
    }

    private function resolveMonth(Request $request): CarbonImmutable
    {
        $raw = (string) $request->query('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01')->startOfMonth();
            } catch (\Throwable) {
                // fall through to the current month
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    private function resolveType(Request $request, string $default = 'ai'): string
    {
        $type = (string) $request->query('type', $default);

        return in_array($type, array_merge(['all'], License::TYPES), true) ? $type : $default;
    }

    /** Six months either side of the selected one, for the month picker. */
    private function monthOptions(CarbonImmutable $selected): Collection
    {
        $anchor = CarbonImmutable::now()->startOfMonth();

        return collect(range(-6, 6))
            ->map(fn (int $offset) => $anchor->addMonths($offset))
            ->push($selected)
            ->unique(fn (CarbonImmutable $m) => $m->format('Y-m'))
            ->sortBy(fn (CarbonImmutable $m) => $m->format('Y-m'))
            ->values();
    }

    private function streamCsv(string $filename, $rows, array $headers, callable $rowMap): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows, $headers, $rowMap) {
            $h = fopen('php://output', 'w');
            // BOM so Excel opens the file as UTF-8 — these reports carry
            // employee names that are not all ASCII.
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, $headers);
            foreach ($rows as $row) {
                fputcsv($h, $rowMap($row));
            }
            fclose($h);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
