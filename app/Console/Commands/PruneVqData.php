<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SwitchDropStat;
use App\Models\VoiceQualityReport;
use App\Models\VqAlertEvent;
use App\Models\WorkflowRequest;
use Illuminate\Console\Command;

class PruneVqData extends Command
{
    protected $signature   = 'data:prune {--dry-run : Show counts without deleting}';
    protected $description = 'Prune voice quality reports, switch drop stats, and workflow requests per retention settings';

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $settings = Setting::get();

        if ($dryRun) {
            $this->warn('[data:prune] DRY RUN — no records will be deleted');
        }

        // ── Voice Quality Reports ──────────────────────────────────────────────
        $vqDays  = max(1, (int) ($settings->vq_retention_days ?: 90));
        $vqCutoff = now()->subDays($vqDays);

        $vqQuery     = VoiceQualityReport::where('created_at', '<', $vqCutoff);
        $vqAlertQuery = VqAlertEvent::where('source_type', 'voice')->where('created_at', '<', $vqCutoff);

        $vqCount      = $vqQuery->count();
        $vqAlertCount = $vqAlertQuery->count();

        if ($dryRun) {
            $this->line(sprintf(
                '[data:prune] VQ reports:       would delete %s records older than %d days (before %s)',
                number_format($vqCount), $vqDays, $vqCutoff->toDateString()
            ));
            $this->line(sprintf(
                '[data:prune] VQ alert events:  would delete %s records older than %d days',
                number_format($vqAlertCount), $vqDays
            ));
        } else {
            $vqQuery->delete();
            $vqAlertQuery->delete();
            $this->info(sprintf(
                '[data:prune] VQ reports: deleted %s records older than %d days',
                number_format($vqCount), $vqDays
            ));
            $this->info(sprintf(
                '[data:prune] VQ alert events: deleted %s records older than %d days',
                number_format($vqAlertCount), $vqDays
            ));
        }

        // ── Switch Drop Stats ─────────────────────────────────────────────────
        $swDays   = max(1, (int) ($settings->switch_drop_retention_days ?: 30));
        $swCutoff = now()->subDays($swDays);
        $swQuery  = SwitchDropStat::where('polled_at', '<', $swCutoff);
        $swCount  = $swQuery->count();

        if ($dryRun) {
            $this->line(sprintf(
                '[data:prune] Switch drops:     would delete %s records older than %d days (before %s)',
                number_format($swCount), $swDays, $swCutoff->toDateString()
            ));
        } else {
            $swQuery->delete();
            $this->info(sprintf(
                '[data:prune] Switch drops: deleted %s records older than %d days',
                number_format($swCount), $swDays
            ));
        }

        // ── Workflow Requests (terminal states only — never delete pending/executing) ──
        $wfDays   = max(1, (int) ($settings->workflow_retention_days ?: 365));
        $wfCutoff = now()->subDays($wfDays);
        $wfQuery  = WorkflowRequest::whereIn('status', ['completed', 'rejected', 'failed'])
            ->where('created_at', '<', $wfCutoff);
        $wfCount  = $wfQuery->count();

        if ($dryRun) {
            $this->line(sprintf(
                '[data:prune] Workflow requests: would delete %s records older than %d days (before %s)',
                number_format($wfCount), $wfDays, $wfCutoff->toDateString()
            ));
        } else {
            $wfQuery->delete();
            $this->info(sprintf(
                '[data:prune] Workflow requests: deleted %s records older than %d days',
                number_format($wfCount), $wfDays
            ));
        }

        return 0;
    }
}
