<?php

namespace App\Console\Commands\EmailMarketing;

use App\Models\EmailMarketing\EmailCampaign;
use App\Services\EmailMarketing\CampaignDispatcher;
use App\Services\EmailMarketing\EmailMarketingNotConfiguredException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DispatchScheduledCampaignsCommand extends Command
{
    protected $signature = 'email-marketing:dispatch-scheduled';

    protected $description = 'Spend the SES send budget on scheduled or in-progress email campaigns.';

    public function handle(CampaignDispatcher $dispatcher): int
    {
        // Bring scheduled campaigns whose time has come into 'sending'
        $now = now();
        $campaigns = EmailCampaign::query()
            ->whereIn('status', ['scheduled', 'sending'])
            ->where(function ($q) use ($now) {
                $q->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', $now);
            })
            ->orderBy('scheduled_at')
            ->get();

        if ($campaigns->isEmpty()) {
            return Command::SUCCESS;
        }

        try {
            $budget = $dispatcher->perMinuteBudget();
        } catch (EmailMarketingNotConfiguredException $e) {
            Log::warning('Email marketing dispatcher skipped: '.$e->getMessage());

            return Command::SUCCESS;
        }

        $this->info("Per-minute budget: {$budget}");

        foreach ($campaigns as $campaign) {
            if ($budget <= 0) {
                break;
            }
            try {
                $spent = $dispatcher->tick($campaign, $budget);
                $this->info("Campaign #{$campaign->id}: dispatched {$spent}");
                $budget -= $spent;
            } catch (\Throwable $e) {
                Log::error("Dispatcher tick failed for campaign #{$campaign->id}: ".$e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
