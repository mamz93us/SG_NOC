<?php

namespace App\Console\Commands\EmailMarketing;

use App\Models\EmailMarketing\EmailCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the pre-aggregated counter columns on email_campaigns from
 * the underlying email_events + email_campaign_sends tables. Useful when
 * counters drift (early dispatch bug, replayed events, etc.).
 *
 * Idempotent. Safe to run any time.
 */
class RecalcCampaignCountersCommand extends Command
{
    protected $signature = 'email-marketing:recalc-counters
                            {campaign? : Optional campaign ID to recalc (omit for all)}
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Recompute campaign counter columns from email_events / email_campaign_sends.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $query = EmailCampaign::query();
        if ($id = $this->argument('campaign')) {
            $query->where('id', $id);
        }

        $campaigns = $query->get();
        if ($campaigns->isEmpty()) {
            $this->warn('No campaigns matched.');

            return Command::SUCCESS;
        }

        foreach ($campaigns as $c) {
            $sends = DB::table('email_campaign_sends')->where('email_campaign_id', $c->id);
            $totalSent      = (clone $sends)->whereNotIn('status', ['queued', 'suppressed'])->count();
            $totalDelivered = (clone $sends)->where('status', 'delivered')->count();
            $totalBounced   = (clone $sends)->where('status', 'bounced')->count();
            $totalComplained = (clone $sends)->where('status', 'complained')->count();

            $events = DB::table('email_events as e')
                ->join('email_campaign_sends as s', 's.id', '=', 'e.email_campaign_send_id')
                ->where('s.email_campaign_id', $c->id);

            $totalOpens        = (clone $events)->where('e.event_type', 'Open')->count();
            $totalUniqueOpens  = (clone $events)->where('e.event_type', 'Open')
                ->distinct('e.email_campaign_send_id')->count('e.email_campaign_send_id');
            $totalClicks       = (clone $events)->where('e.event_type', 'Click')->count();
            $totalUniqueClicks = (clone $events)->where('e.event_type', 'Click')
                ->distinct('e.email_campaign_send_id')->count('e.email_campaign_send_id');

            $new = [
                'total_sent'           => $totalSent,
                'total_delivered'      => $totalDelivered,
                'total_bounces'        => $totalBounced,
                'total_complaints'     => $totalComplained,
                'total_opens'          => $totalOpens,
                'total_unique_opens'   => $totalUniqueOpens,
                'total_clicks'         => $totalClicks,
                'total_unique_clicks'  => $totalUniqueClicks,
            ];

            $diff = [];
            foreach ($new as $k => $v) {
                if ((int) $c->{$k} !== (int) $v) {
                    $diff[$k] = $c->{$k}.' → '.$v;
                }
            }

            if (empty($diff)) {
                $this->line("#{$c->id} \"{$c->name}\" — already correct");
                continue;
            }

            $this->line(($dry ? '[dry-run] ' : 'fixed ')."#{$c->id} \"{$c->name}\":");
            foreach ($diff as $col => $change) {
                $this->line("    {$col}: {$change}");
            }

            if (! $dry) {
                $c->update($new);
            }
        }

        return Command::SUCCESS;
    }
}
