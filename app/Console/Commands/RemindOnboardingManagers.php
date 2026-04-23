<?php

namespace App\Console\Commands;

use App\Jobs\SendOnboardingManagerFormJob;
use App\Models\OnboardingManagerToken;
use App\Models\WorkflowLog;
use App\Models\WorkflowRequest;
use Illuminate\Console\Command;

class RemindOnboardingManagers extends Command
{
    protected $signature   = 'onboarding:remind-managers
                              {--max-reminders=3 : Stop reminding after this many emails}
                              {--dry-run : Show what would be reminded without sending}';

    protected $description = 'Send daily reminder emails to managers who have not filled the onboarding setup form.';

    public function handle(): int
    {
        $maxReminders = max(1, (int) $this->option('max-reminders'));
        $dryRun       = (bool) $this->option('dry-run');

        $workflows = WorkflowRequest::where('status', 'awaiting_manager_form')
            ->where('type', 'create_user')
            ->get();

        if ($workflows->isEmpty()) {
            $this->info('[onboarding:remind-managers] No workflows awaiting manager form.');
            return 0;
        }

        $reminded = 0;
        $skipped  = 0;

        foreach ($workflows as $workflow) {
            $token = OnboardingManagerToken::where('workflow_id', $workflow->id)
                ->whereNull('responded_at')
                ->whereNull('used_at')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->latest()
                ->first();

            if (! $token) {
                $skipped++;
                continue;
            }

            // Don't remind more than once per 24h
            if ($token->reminded_at && $token->reminded_at->greaterThan(now()->subHours(20))) {
                $skipped++;
                continue;
            }

            // Respect the max-reminders cap
            if (($token->reminder_count ?? 0) >= $maxReminders) {
                $skipped++;
                continue;
            }

            // Only remind if the initial email went out at least 24h ago
            // (created_at is our best proxy for first-email-sent time).
            if ($token->created_at && $token->created_at->greaterThan(now()->subHours(20))) {
                $skipped++;
                continue;
            }

            $managerEmail = $token->manager_email ?: ($workflow->payload['manager_email'] ?? null);
            if (! $managerEmail) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] Would remind %s for workflow #%d (reminder %d/%d)',
                    $managerEmail,
                    $workflow->id,
                    ($token->reminder_count ?? 0) + 1,
                    $maxReminders
                ));
                $reminded++;
                continue;
            }

            try {
                SendOnboardingManagerFormJob::dispatch($workflow->id)->onQueue('emails');

                $token->update([
                    'reminded_at'    => now(),
                    'reminder_count' => ($token->reminder_count ?? 0) + 1,
                ]);

                WorkflowLog::create([
                    'workflow_id' => $workflow->id,
                    'level'       => 'info',
                    'message'     => sprintf(
                        'Reminder %d/%d sent to manager (%s) — still awaiting setup form.',
                        $token->reminder_count,
                        $maxReminders,
                        $managerEmail
                    ),
                    'created_at'  => now(),
                ]);

                $this->info(sprintf(
                    'Reminded %s for workflow #%d (%d/%d)',
                    $managerEmail,
                    $workflow->id,
                    $token->reminder_count,
                    $maxReminders
                ));

                $reminded++;
            } catch (\Throwable $e) {
                $this->error("Failed to remind workflow #{$workflow->id}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->info("[onboarding:remind-managers] Done. Reminded {$reminded}, skipped {$skipped}.");
        return 0;
    }
}
