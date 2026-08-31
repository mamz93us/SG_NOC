<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Knowbe4Score;
use App\Models\Setting;
use App\Services\KnowBe4\KnowBe4Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pulls per-user risk scores from KnowBe4 into `knowbe4_scores`.
 *
 * The home portal reads only from this table — no external call happens on a
 * page load. Matching to employees is by email, the only identifier the two
 * systems reliably share.
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
                    // KnowBe4 nests risk under current_risk_score on /v1/users.
                    'risk_score' => $this->numeric($user['current_risk_score'] ?? null),
                    'phish_fail_count' => (int) ($user['phish_fail_count'] ?? 0),
                    'phish_sent_count' => (int) ($user['phish_sent_count'] ?? 0),
                    'trainings_completed' => (int) ($user['completed_courses_count'] ?? 0),
                    'trainings_outstanding' => (int) ($user['past_due_courses_count'] ?? 0),
                    'status' => $user['status'] ?? null,
                    'last_phish_failed_at' => $this->date($user['last_phish_failed'] ?? null),
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
