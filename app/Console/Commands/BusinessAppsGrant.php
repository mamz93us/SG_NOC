<?php

namespace App\Console\Commands;

use App\Models\BusinessApp;
use App\Models\Employee;
use App\Models\EmployeeAppAccount;
use App\Models\MailSender;
use App\Services\Identity\GraphService;
use App\Services\SmtpConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Bulk-grant a business app (Salesforce, Oracle, …) to a list of employees.
 *
 *   php artisan business-apps:grant salesforce --file=/tmp/sf.txt --dry-run
 *   php artisan business-apps:grant salesforce --file=/tmp/sf.txt --status=active
 *   php artisan business-apps:grant salesforce --file=/tmp/sf.txt --notify
 *
 * Two things make this safe to point at 100+ people:
 *
 *  - It is dry-run FIRST by design: run with --dry-run, read the unmatched
 *    report, fix the list, then run for real. Unmatched addresses are never
 *    silently skipped.
 *  - --notify sends ONE digest to the app's administrators, not one email per
 *    employee. Sending 131 separate "please create an account" emails would be
 *    unusable for whoever receives them.
 *
 * Default status is `active` — a bulk list is normally recording access people
 * already have. Use --status=requested when you genuinely want the app team to
 * create these accounts.
 */
class BusinessAppsGrant extends Command
{
    protected $signature = 'business-apps:grant
        {app : Business app key, e.g. salesforce or oracle}
        {--file= : Path to a file of work email addresses, one per line}
        {--status=active : active (already have it) or requested (needs creating)}
        {--notify : Send one digest email to the app administrators}
        {--skip-group : Do not touch Azure security group membership}
        {--dry-run : Report what would happen and change nothing}';

    protected $description = 'Bulk-grant a business app account to a list of employees by email';

    public function handle(SmtpConfigService $smtp): int
    {
        $appKey = $this->argument('app');
        $status = $this->option('status');
        $dry = (bool) $this->option('dry-run');

        if (! in_array($status, [EmployeeAppAccount::STATUS_ACTIVE, EmployeeAppAccount::STATUS_REQUESTED], true)) {
            $this->error("--status must be 'active' or 'requested'.");

            return self::FAILURE;
        }

        $app = BusinessApp::with('identityGroup')->where('key', $appKey)->first();
        if (! $app) {
            $this->error("No business app with key '{$appKey}'. Known: "
                .BusinessApp::pluck('key')->implode(', '));

            return self::FAILURE;
        }

        $emails = $this->readEmails();
        if ($emails === null) {
            return self::FAILURE;
        }

        $this->info("App:    {$app->name}");
        $this->info('Group:  '.($app->identityGroup?->display_name ?? '(none configured)'));
        $this->info("Status: {$status}");
        $this->info('Emails: '.count($emails).($dry ? '   [DRY RUN]' : ''));
        $this->newLine();

        // ── Resolve ──────────────────────────────────────────────
        $matched = [];
        $notFound = [];
        $already = [];

        foreach ($emails as $email) {
            $employee = Employee::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

            if (! $employee) {
                $notFound[] = $email;

                continue;
            }

            $existing = EmployeeAppAccount::where('employee_id', $employee->id)
                ->where('business_app_id', $app->id)
                ->first();

            if ($existing && $existing->status !== EmployeeAppAccount::STATUS_REVOKED) {
                $already[] = $email;

                continue;
            }

            $matched[] = [$employee, $existing];
        }

        $this->line('  <fg=green>match</>      '.count($matched));
        $this->line('  <fg=yellow>already</>    '.count($already));
        $this->line('  <fg=red>not found</>  '.count($notFound));
        $this->newLine();

        if ($notFound) {
            $this->warn('No employee record for these addresses — check for typos or unsynced staff:');
            foreach ($notFound as $e) {
                $this->line("    {$e}");
            }
            $this->newLine();
        }

        if ($dry) {
            $this->info('Dry run — nothing changed. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        if (empty($matched)) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply to '.count($matched).' employee(s)?', true)) {
            return self::SUCCESS;
        }

        // ── Apply ────────────────────────────────────────────────
        $graph = $this->option('skip-group') ? null : new GraphService;
        $groupId = $app->identityGroup?->azure_id;
        $granted = [];
        $groupFailures = [];

        $bar = $this->output->createProgressBar(count($matched));
        $bar->start();

        foreach ($matched as [$employee, $existing]) {
            if ($graph && $groupId && $employee->azure_id) {
                try {
                    $graph->addUserToGroup($employee->azure_id, $groupId);
                    // Graph throttles hard on bulk membership writes.
                    usleep(400_000);
                } catch (\Throwable $e) {
                    if (! \App\Services\Identity\GraphService::isAlreadyMemberError($e)) {
                        $groupFailures[] = "{$employee->email}: ".$e->getMessage();
                    }
                }
            }

            $account = $existing ?: new EmployeeAppAccount([
                'employee_id' => $employee->id,
                'business_app_id' => $app->id,
            ]);

            $account->fill([
                'status' => $status,
                'requested_at' => now(),
                'activated_at' => $status === EmployeeAppAccount::STATUS_ACTIVE ? now() : null,
                'revoked_at' => null,
                'notes' => 'Bulk-granted via business-apps:grant',
            ])->save();

            $granted[] = $employee;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Granted: '.count($granted));

        if ($groupFailures) {
            $this->warn('Azure group could not be updated for '.count($groupFailures).':');
            foreach ($groupFailures as $f) {
                $this->line("    {$f}");
            }
        }

        // ── One digest, not N emails ─────────────────────────────
        if ($this->option('notify')) {
            $this->sendDigest($smtp, $app, $granted, $status);
        } else {
            $this->comment('No email sent (pass --notify to send one digest to the app administrators).');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,string>|null
     */
    private function readEmails(): ?array
    {
        $path = $this->option('file');

        if (! $path || ! is_readable($path)) {
            $this->error('--file is required and must be readable.');

            return null;
        }

        $emails = collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ->map(fn ($l) => trim($l))
            ->reject(fn ($l) => $l === '' || str_starts_with($l, '#'))
            ->unique()
            ->values();

        $invalid = $emails->reject(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($invalid->isNotEmpty()) {
            $this->error('These are not valid email addresses:');
            foreach ($invalid as $e) {
                $this->line("    {$e}");
            }

            return null;
        }

        return $emails->all();
    }

    /**
     * One message listing everyone, so the app administrators get a workable
     * list instead of a hundred separate requests.
     */
    private function sendDigest(SmtpConfigService $smtp, BusinessApp $app, array $granted, string $status): void
    {
        $recipients = $app->requestRecipients();

        if (empty($recipients)) {
            $this->warn("No request recipients configured for {$app->name} — digest not sent.");

            return;
        }

        $smtp->loadFromSettings();

        $verb = $status === EmployeeAppAccount::STATUS_ACTIVE
            ? 'have been recorded as having'
            : 'need';

        $lines = collect($granted)->map(function (Employee $e) {
            return sprintf('%-40s %-34s %s',
                $e->name,
                $e->email,
                trim(($e->job_title ?: '').($e->branch?->name ? ' — '.$e->branch->name : ''))
            );
        })->implode("\n");

        $body = count($granted)." employees {$verb} {$app->name} access.\n\n"
            ."They have been added to the security group where one is configured.\n\n"
            .$lines."\n\n"
            ."Sent by SG NOC (business-apps:grant).\n";

        $subject = "{$app->name} access — ".count($granted).' employees';

        try {
            Mail::raw($body, function ($message) use ($recipients, $subject) {
                $message->to($recipients)->subject($subject);
                MailSender::applyToMessage($message, MailSender::ONBOARDING);
            });
            $this->info('Digest emailed to: '.implode(', ', $recipients));
        } catch (\Throwable $e) {
            $this->error('Digest send failed: '.$e->getMessage());
        }
    }
}
