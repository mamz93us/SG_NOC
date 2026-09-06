<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\License;
use App\Models\LicenseAssignment;
use Illuminate\Database\Seeder;

/**
 * The AI tool subscriptions as supplied by IT, September 2026.
 *
 * Re-runnable: licences are matched by name and updated in place, assignments
 * are created only when missing. Running it twice changes nothing.
 *
 * One licence row per (service × plan × price), because `cost` on a licence is
 * one price for one seat — Claude's Standard and Premium seats are different
 * prices and so are two rows, not one row of seven seats.
 *
 * Every one of these is paid by company credit card (confirmed by IT), so they
 * land in the payments report's self-charging group — visible to finance, but
 * needing no payment raised. The card itself is not recorded: `payment_account`
 * stays empty until somebody says which card, because a wrong card number in
 * front of finance is worse than a blank one.
 */
class AiSubscriptionSeeder extends Seeder
{
    /** Confirmed by IT: the whole AI basket is on a company card. */
    private const DEFAULT_PAYMENT_METHOD = 'credit_card';

    /**
     * Licences outside the AI sheet that IT has confirmed are card-paid too.
     * Matched by name against what is already in the system — this pass only
     * ever UPDATES an existing licence, it never creates one, because the cost,
     * seat count and renewal date of these are not ours to invent.
     */
    private const ALSO_CARD_PAID = ['Adobe'];

    /**
     * Renewal dates are the anchor the reports project forward from — a monthly
     * subscription anchored on the 20th is charged on the 20th of every month.
     */
    private const SUBSCRIPTIONS = [
        [
            'name' => 'Claude (Standard)',
            'vendor' => 'Anthropic',
            'cost' => 25.00,
            'currency' => 'USD',
            'renews_on' => '2026-09-20',
            'holders' => [
                ['Mohammad Salameh', 'mohammad.salameh@samirgroup.com'],
                ['Alaa Sakr', 'alaa.sakr@sssegypt.com'],
                ['Hussien Shallaly', 'Hussien.Elsayed@sssegypt.com'],
                ['Marina George', 'marina.george@sssegypt.com'],
                ['Mohamed Zahran', 'mohamed.zahran@sssegypt.com'],
                ['Merola Osama', 'merola.osama@sssegypt.com'],
            ],
        ],
        [
            'name' => 'Claude (Premium)',
            'vendor' => 'Anthropic',
            'cost' => 100.00,
            'currency' => 'USD',
            'renews_on' => '2026-09-20',
            'holders' => [
                ['Rana Osama', 'rana.osama@sssegypt.com'],
            ],
        ],
        [
            'name' => 'ChatGPT (Standard)',
            'vendor' => 'OpenAI',
            'cost' => 1150.00,
            'currency' => 'EGP',
            'renews_on' => '2026-09-20',
            'holders' => [
                ['Mohamed Elkhodary', 'mohamed.elkhodary@sssegypt.com'],
                ['Maria Metry', 'maria.metry@sssegypt.com'],
                ['Marina George', 'marina.george@sssegypt.com'],
                ['Mohammad Salameh', 'mohammad.salameh@samirgroup.com'],
                // Shared departmental mailbox, not a person — expected to stay
                // unassigned, and to show as an idle seat on the usage report.
                ['Specialized Seamless Services', 'ai-subscriptions@sssegypt.com'],
            ],
        ],
        [
            'name' => 'Runway AI (Pro)',
            'vendor' => 'Runway',
            'cost' => 39.90,
            'currency' => 'USD',
            'renews_on' => '2026-09-13',
            'notes' => 'Source sheet lists the user as Maria Metry but the seat email as farah.nasser@samirgroup.com. Seated on the email — confirm with IT which is correct.',
            'holders' => [
                ['Maria Metry', 'farah.nasser@samirgroup.com'],
            ],
        ],
        [
            'name' => 'CapCut (Standard)',
            'vendor' => 'CapCut',
            'cost' => 79.99,
            'currency' => 'SAR',
            'renews_on' => '2026-09-21',
            'holders' => [
                ['Merola Osama', 'merola.osama@sssegypt.com'],
            ],
        ],
        [
            'name' => 'Magnific AI (Pro)',
            'vendor' => 'Magnific',
            'cost' => 135.70,
            'currency' => 'EUR',
            'renews_on' => '2026-09-21',
            'holders' => [
                ['Mohamed Elkhodary', 'mohamed.elkhodary@sssegypt.com'],
            ],
        ],
        [
            'name' => 'Semrush (Pro)',
            'vendor' => 'Semrush',
            'cost' => 236.75,
            'currency' => 'USD',
            'renews_on' => '2026-09-04',
            'holders' => [
                ['Merola Osama', 'merola.osama@sssegypt.com'],
            ],
        ],
    ];

    public function run(): void
    {
        $unmatched = [];
        $nameMismatches = [];

        foreach (self::SUBSCRIPTIONS as $spec) {
            $license = License::firstOrNew(['license_name' => $spec['name']]);

            $license->fill([
                'vendor' => $spec['vendor'],
                'license_type' => 'ai',
                'billing_cycle' => 'monthly',
                'cost' => $spec['cost'],
                'currency' => $spec['currency'],
                'payment_method' => $spec['payment_method'] ?? self::DEFAULT_PAYMENT_METHOD,
                'expiry_date' => $spec['renews_on'],
                'seats' => count($spec['holders']),
                'notes' => $spec['notes'] ?? null,
            ]);
            $license->save();

            foreach ($spec['holders'] as [$sheetName, $email]) {
                $employee = $this->findEmployee($email);

                if (! $employee) {
                    $unmatched[] = "{$spec['name']}: {$sheetName} <{$email}>";

                    continue;
                }

                // The sheet's name and the seat's email disagree in at least one
                // case. The email is the account that is actually billed, so it
                // wins — but the discrepancy gets reported rather than buried.
                if (! $this->namesLookAlike($sheetName, $employee->name)) {
                    $nameMismatches[] = "{$spec['name']}: sheet says '{$sheetName}', {$email} belongs to '{$employee->name}'";
                }

                LicenseAssignment::firstOrCreate(
                    [
                        'license_id' => $license->id,
                        'assignable_type' => Employee::class,
                        'assignable_id' => $employee->id,
                    ],
                    [
                        'assigned_date' => $spec['renews_on'],
                        'notes' => 'Imported from the AI subscriptions sheet',
                    ]
                );
            }

            $this->report('info', "  {$spec['name']} — {$spec['currency']} ".number_format($spec['cost'], 2)
                .'/seat/month × '.count($spec['holders']).' seats, renews '.date('d M Y', strtotime($spec['renews_on'])));
        }

        $this->report('info', 'AI subscriptions seeded: '.count(self::SUBSCRIPTIONS).' services.');

        foreach ($nameMismatches as $line) {
            $this->report('warn', "Name/email mismatch — {$line}");
        }

        if ($unmatched) {
            $this->report('warn', 'No employee record matched these seats — the licence and its seat count are still '
                .'correct, the seat just shows as unassigned until the person exists in Employees:');
            foreach ($unmatched as $line) {
                $this->report('warn', "  · {$line}");
            }
        }

        $this->report('info', 'Payment method set to Credit Card on all '.count(self::SUBSCRIPTIONS)
            .' — they appear on the payments report as self-charging, needing no payment raised.');

        $this->markAlsoCardPaid();
    }

    /**
     * Set Credit Card on non-AI licences IT has confirmed are card-paid.
     *
     * Update-only by design: if no such licence exists yet this reports it and
     * moves on, rather than inventing a row whose cost, seats and renewal date
     * nobody has given us — a made-up amount on a payments report is worse than
     * a missing one.
     */
    private function markAlsoCardPaid(): void
    {
        foreach (self::ALSO_CARD_PAID as $name) {
            $matches = License::where('license_name', 'like', "%{$name}%")->get();

            if ($matches->isEmpty()) {
                $this->report('warn', "No licence matching '{$name}' exists yet — nothing to mark as card-paid. "
                    .'Add it at /admin/itam/licenses with its cost, seats, billing cycle and renewal date, '
                    .'then set Payment Method to Credit Card.');

                continue;
            }

            foreach ($matches as $license) {
                $license->update(['payment_method' => 'credit_card']);
                $this->report('info', "  {$license->license_name} — marked Credit Card.");
            }
        }
    }

    /**
     * Match on email, case-insensitively — the source sheet mixes cases and
     * SQLite (the test connection) compares strings case-sensitively.
     * Prefers an active record when a person has more than one row.
     */
    private function findEmployee(string $email): ?Employee
    {
        return Employee::whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->first();
    }

    /** Loose check: do the two names share any word? Catches renames and reorderings. */
    private function namesLookAlike(string $a, string $b): bool
    {
        $words = fn (string $s) => array_filter(preg_split('/\s+/', strtolower(trim($s))));
        $shared = array_intersect($words($a), $words($b));

        return count($shared) > 0;
    }

    private function report(string $level, string $message): void
    {
        $this->command?->{$level}($message);
    }
}
