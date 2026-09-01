<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Knowbe4Score;
use App\Models\Setting;
use App\Services\KnowBe4\KnowBe4Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pulls per-user risk scores, training progress and phishing results from
 * KnowBe4 into `knowbe4_scores`.
 *
 * The home portal reads only from this table — no external call happens on a
 * page load. Matching to employees is by email, the only identifier the two
 * systems reliably share.
 *
 * **`/v1/users` carries no counts.** It returns `current_risk_score` and
 * `phish_prone_percentage` and nothing else of interest — no
 * `phish_fail_count`, no `past_due_courses_count`. Reading those keys off a
 * user (as this command used to) silently yields 0 for everybody, which looks
 * like a clean bill of health rather than a bug. The real figures are
 * aggregated here from `/v1/training/enrollments` and from the recipients of
 * every `/v1/phishing/security_tests`.
 */
class SyncKnowBe4Scores extends Command
{
    protected $signature = 'knowbe4:sync';

    protected $description = 'Sync KnowBe4 risk scores and phishing stats into knowbe4_scores';

    public function handle(KnowBe4Service $kb4): int
    {
        $settings = Setting::get();

        if (! $kb4->isConfigured($settings)) {
            $this->comment('KnowBe4 is disabled or has no token — nothing to do.');

            return self::SUCCESS;
        }

        $this->info('Fetching users from '.$kb4->baseUrl($settings).'…');

        try {
            $users = $kb4->users($settings);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            Log::error('[knowbe4:sync] '.$e->getMessage());

            return self::FAILURE;
        }

        if ($users === []) {
            $this->warn('KnowBe4 returned no users. Leaving existing scores alone.');

            return self::SUCCESS;
        }

        // One lookup for the whole run rather than a query per user.
        $employeesByEmail = Employee::query()
            ->whereNotNull('email')
            ->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [mb_strtolower($email) => $id]);

        $syncedAt = now();
        $matched = 0;
        $stored = 0;

        // ── Training ────────────────────────────────────────────────
        // Statuses seen on the live tenant: Passed, Not Started, In Progress,
        // Past Due. "Due" means Past Due — a course that is assigned but not
        // yet overdue is not something to chase someone about.
        try {
            $enrollments = $kb4->trainingEnrollments($settings);
        } catch (\Throwable $e) {
            $this->error('Training enrollments: '.$e->getMessage());
            Log::error('[knowbe4:sync] enrollments: '.$e->getMessage());
            $enrollments = [];
        }

        $pastDue = [];
        $passed = [];

        foreach ($enrollments as $enrollment) {
            $email = mb_strtolower(trim((string) ($enrollment['user']['email'] ?? '')));

            if ($email === '') {
                continue;
            }

            match ((string) ($enrollment['status'] ?? '')) {
                'Past Due' => $pastDue[$email] = ($pastDue[$email] ?? 0) + 1,
                'Passed', 'Completed' => $passed[$email] = ($passed[$email] ?? 0) + 1,
                default => null,
            };
        }

        $this->line(sprintf('  training: %d enrollment(s), %d past due across %d people',
            count($enrollments), array_sum($pastDue), count($pastDue)));

        // ── Phishing ────────────────────────────────────────────────
        // Results are per security test, so this is one call per test plus
        // paging. ~30 tests on this tenant; the service throttles itself.
        $failCount = [];
        $sentCount = [];
        $lastFailed = [];

        try {
            $tests = $kb4->securityTests($settings);
        } catch (\Throwable $e) {
            $this->error('Security tests: '.$e->getMessage());
            Log::error('[knowbe4:sync] security tests: '.$e->getMessage());
            $tests = [];
        }

        foreach ($tests as $test) {
            $pstId = (int) ($test['pst_id'] ?? 0);

            if (! $pstId) {
                continue;
            }

            try {
                $recipients = $kb4->securityTestRecipients($pstId, $settings);
            } catch (\Throwable $e) {
                // One unreadable test must not lose the other thirty.
                $this->warn("  test {$pstId}: ".$e->getMessage());

                continue;
            }

            foreach ($recipients as $recipient) {
                $email = mb_strtolower(trim((string) ($recipient['user']['email'] ?? '')));

                if ($email === '') {
                    continue;
                }

                // Counted as sent only once it actually arrived — a bounced or
                // unsent test says nothing about the person.
                if (! empty($recipient['delivered_at'])) {
                    $sentCount[$email] = ($sentCount[$email] ?? 0) + 1;
                }

                if (KnowBe4Service::recipientFailed($recipient)) {
                    $failCount[$email] = ($failCount[$email] ?? 0) + 1;

                    $failedAt = KnowBe4Service::recipientFailedAt($recipient);

                    if ($failedAt && (! isset($lastFailed[$email]) || $failedAt > $lastFailed[$email])) {
                        $lastFailed[$email] = $failedAt;
                    }
                }
            }
        }

        $this->line(sprintf('  phishing: %d test(s), %d failure(s) across %d people',
            count($tests), array_sum($failCount), count($failCount)));

        // ── Store ───────────────────────────────────────────────────
        foreach ($users as $user) {
            $email = mb_strtolower(trim((string) ($user['email'] ?? '')));

            if ($email === '') {
                continue;
            }

            $employeeId = $employeesByEmail[$email] ?? null;

            if ($employeeId) {
                $matched++;
            }

            Knowbe4Score::updateOrCreate(
                ['email' => $email],
                [
                    'kb4_user_id' => $user['id'] ?? null,
                    'employee_id' => $employeeId,
                    // The two figures /v1/users actually carries. PPP is on a
                    // 0–100 scale and is what the portal card leads with.
                    'risk_score' => $this->numeric($user['current_risk_score'] ?? null),
                    'phish_prone_percentage' => $this->numeric($user['phish_prone_percentage'] ?? null),
                    // Everything below is aggregated above, not read off the user.
                    'phish_fail_count' => $failCount[$email] ?? 0,
                    'phish_sent_count' => $sentCount[$email] ?? 0,
                    'trainings_completed' => $passed[$email] ?? 0,
                    'trainings_outstanding' => $pastDue[$email] ?? 0,
                    'status' => $user['status'] ?? null,
                    'last_phish_failed_at' => $this->date($lastFailed[$email] ?? null),
                    'synced_at' => $syncedAt,
                ]
            );

            $stored++;
        }

        $settings->knowbe4_last_sync_at = $syncedAt;
        $settings->save();

        $this->info("Stored {$stored} score(s); {$matched} matched to an employee record.");

        if ($stored > 0 && $matched === 0) {
            // Every row unmatched almost always means the two systems disagree
            // about the mail domain, not that nobody is enrolled.
            $this->warn('None matched an employee by email — check that KnowBe4 uses the same addresses as the directory.');
        }

        return self::SUCCESS;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function date(mixed $value): ?string
    {
        if (! $value || ! is_string($value)) {
            return null;
        }

        try {
            return \Carbon\CarbonImmutable::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
