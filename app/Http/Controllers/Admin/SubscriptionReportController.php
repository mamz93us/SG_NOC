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

    /**
     * Cost and payments due, split by the department that holds each seat.
     *
     * Two figures per department, answering the same two different questions
     * the other reports keep apart:
     *
     *  - monthly cost: every seat's run rate, charged this month or not. What
     *    the department costs to keep running.
     *  - due this month: only seats on a licence renewing in this month, at
     *    their share of that charge.
     *
     * The second is an ALLOCATION, not a set of payments. A licence is one
     * indivisible charge on one card; splitting it across departments is a
     * bookkeeping view of that charge, and finance still pays it once. Because
     * a licence's charge is cost x seats, one seat's share is exactly the
     * per-seat cost — so department shares always add back up to the payment
     * total, which is what makes the split safe to publish.
     */
    public function byDepartment(Request $request)
    {
        $month = $this->resolveMonth($request);
        $type = $this->resolveType($request);
        $display = $this->resolveDisplayCurrency($request);

        $licenses = $this->recurringLicenses($type);
        $rows = $this->seatRows($licenses, $month);

        $groups = $rows
            ->groupBy('department_key')
            ->map(function (Collection $g, string $key) use ($display) {
                $runRate = $this->sumByCurrency($g, 'monthly_per_seat');
                $due = $this->dueByCurrency($g);

                return [
                    'key' => $key,
                    'department' => $g->first()['department'],
                    'is_bucket' => str_starts_with($key, '__'),
                    'seats' => $g->count(),
                    'people' => $g->pluck('email')->filter()->unique()->count(),
                    'services' => $g->pluck('service')->unique()->sort()->values()->all(),
                    'run_rate' => $runRate,
                    'run_rate_combined' => $this->converter->totalIn($runRate, $display),
                    'due' => $due,
                    'due_combined' => $this->converter->totalIn($due, $display),
                ];
            })
            // Biggest spender first; the catch-all buckets sink to the bottom
            // where they read as exceptions to chase, not as departments.
            ->sortBy(fn ($g) => [$g['is_bucket'] ? 1 : 0, -$g['run_rate_combined']['total']])
            ->values();

        if ($request->query('csv') === 'detail') {
            return $this->departmentSeatCsv($rows, $month, $display);
        }

        if ($request->boolean('csv')) {
            return $this->departmentSummaryCsv($groups, $month, $display);
        }

        $runRateByCurrency = $this->sumByCurrency($rows, 'monthly_per_seat');
        $dueByCurrency = $this->dueByCurrency($rows);

        return view('admin.itam.reports.subscriptions-by-department', [
            'month' => $month,
            'type' => $type,
            'groups' => $groups,
            'display' => $display,
            'displayOptions' => Currency::CODES,
            'runRateByCurrency' => $runRateByCurrency,
            'dueByCurrency' => $dueByCurrency,
            'combinedRunRate' => $this->converter->totalIn($runRateByCurrency, $display),
            'combinedDue' => $this->converter->totalIn($dueByCurrency, $display),
            'departmentCount' => $groups->reject(fn ($g) => $g['is_bucket'])->count(),
            'monthOptions' => $this->monthOptions($month),
        ]);
    }

    /**
     * A seat's share of this month's charge, per currency.
     *
     * The share IS the per-seat cost: a licence charges cost x seats, so
     * dividing that back across its seats returns the same number. Seats whose
     * licence does not renew this month contribute nothing.
     *
     * @return array<string, float>
     */
    private function dueByCurrency(Collection $rows): array
    {
        return $rows
            ->filter(fn ($r) => $r['renewal_date'] !== null)
            ->groupBy('currency')
            ->map(fn (Collection $c) => round($c->sum(fn ($r) => (float) ($r['cost_per_seat'] ?? 0)), 2))
            ->filter(fn ($v) => $v > 0)
            ->all();
    }

    private function departmentSummaryCsv(Collection $groups, CarbonImmutable $month, string $display): StreamedResponse
    {
        return $this->streamCsv(
            'subscription-cost-by-department-'.$month->format('Y-m'),
            $groups,
            ['Department', 'Seats', 'People', 'Services', 'Monthly Cost (as invoiced)',
                "Monthly Cost ({$display})", 'Due This Month (as invoiced)', "Due This Month ({$display})"],
            fn (array $g) => [
                $g['department'],
                $g['seats'],
                $g['people'],
                implode(' / ', $g['services']),
                $this->currencyList($g['run_rate']),
                number_format($g['run_rate_combined']['total'], 2, '.', ''),
                $this->currencyList($g['due']),
                number_format($g['due_combined']['total'], 2, '.', ''),
            ]
        );
    }

    private function departmentSeatCsv(Collection $rows, CarbonImmutable $month, string $display): StreamedResponse
    {
        return $this->streamCsv(
            'subscription-seats-by-department-'.$month->format('Y-m'),
            $rows->sortBy([['department', 'asc'], ['user', 'asc'], ['service', 'asc']])->values(),
            ['Department', 'User', 'Email', 'Seat Status', 'Service', 'Currency',
                'Monthly Cost', "Monthly Cost ({$display})", 'Due This Month', "Due This Month ({$display})",
                'Renewal Date', 'Rate Basis'],
            function (array $r) use ($display) {
                $due = $r['renewal_date'] ? (float) ($r['cost_per_seat'] ?? 0) : 0.0;

                return [
                    $r['department'], $r['user'], $r['email'], $r['seat_status'], $r['service'], $r['currency'],
                    $r['monthly_per_seat'],
                    $this->converter->convert((float) $r['monthly_per_seat'], $r['currency'], $display),
                    $due ?: '',
                    $due ? $this->converter->convert($due, $r['currency'], $display) : '',
                    $r['renewal_date']?->format('Y-m-d'),
                    $this->rateBasis($r['currency'], $display),
                ];
            }
        );
    }

    /** "USD 25.00 + EGP 1150.00" — keeps a CSV cell honest about mixed currencies. */
    private function currencyList(array $byCurrency): string
    {
        $parts = [];
        foreach ($byCurrency as $currency => $amount) {
            $parts[] = $currency.' '.number_format($amount, 2, '.', '');
        }

        return implode(' + ', $parts);
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
            // assignable.department feeds the by-department report; loading it
            // here keeps that grouping from firing a query per seat.
            ->with(['supplier', 'assignments.assignable' => fn ($m) => $m->morphWith([Employee::class => ['department']])])
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
                ] + $this->departmentOf($assignment));
            }

            $spare = max(0, (int) $license->seats - $license->assignments->count());
            for ($i = 0; $i < $spare; $i++) {
                $rows->push($base + [
                    'user' => '— unassigned seat —',
                    'email' => '',
                    'seat_status' => 'Unassigned',
                    'assigned_date' => null,
                    // A seat nobody holds is still invoiced, so it needs a bucket
                    // of its own rather than being dropped from the department
                    // split — otherwise the parts stop summing to the whole.
                    'department_key' => '__unassigned',
                    'department' => 'Unassigned seats',
                ]);
            }
        }

        return $rows;
    }

    /**
     * Which department carries a seat's cost.
     *
     * Everything that is not an employee in a department gets a named bucket
     * rather than being left out: a device-held seat, an employee nobody has
     * filed under a department (65 of them in production), and a deleted record
     * are all still being invoiced. Dropping any of them would make the
     * department split quietly stop adding up to the licence total, which is
     * the one property this report has to keep.
     *
     * @return array{department_key: string, department: string}
     */
    private function departmentOf(LicenseAssignment $assignment): array
    {
        $assignable = $assignment->assignable;

        if ($assignable instanceof Employee) {
            $department = $assignable->department;

            return $department
                ? ['department_key' => 'dept:'.$department->id, 'department' => $department->name]
                : ['department_key' => '__no_department', 'department' => 'No department set'];
        }

        if ($assignable instanceof Device) {
            return ['department_key' => '__device', 'department' => 'Devices (no employee)'];
        }

        return ['department_key' => '__deleted', 'department' => 'Deleted records'];
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
