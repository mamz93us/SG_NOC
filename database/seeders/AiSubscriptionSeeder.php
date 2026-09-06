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
 * `payment_method` is deliberately left unset. Nobody told us which card or
 * account pays these, and inventing that would put a wrong instruction in front
 * of finance. The payments report flags every unset one until it is filled in
 * from Software Licences.
 */
class AiSubscriptionSeeder extends Seeder
{
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

        $this->report('warn', 'Payment method is NOT set on any of these — set Credit Card or Wire Transfer on each '
            .'licence at /admin/itam/licenses, or the payments report will flag them as unactionable.');
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
