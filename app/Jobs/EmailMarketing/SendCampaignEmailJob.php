<?php

namespace App\Jobs\EmailMarketing;

use App\Models\EmailMarketing\EmailCampaignSend;
use App\Services\EmailMarketing\MergeTagRenderer;
use App\Services\EmailMarketing\SesService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Single-recipient send. Mostly kept as a building block for tests and
 * the "Send test" flow — production volume goes through DispatchCampaignBatchJob.
 */
class SendCampaignEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public int $sendId) {}

    public function handle(SesService $ses, MergeTagRenderer $renderer): void
    {
        $send = EmailCampaignSend::with(['subscriber', 'campaign.template', 'campaign.list'])
            ->find($this->sendId);
        if (! $send || $send->status !== 'queued') {
            return;
        }

        $campaign = $send->campaign;
        $renderedHtml = (string) ($campaign?->template?->rendered_html ?? '');
        if ($renderedHtml === '') {
            $send->update(['status' => 'failed', 'error_message' => 'Template has no rendered HTML']);

            return;
        }

        try {
            $html = $renderer->render($renderedHtml, $send->subscriber, $send, $campaign->list, $campaign);
            $messageId = $ses->sendCampaignEmail($send, $html, $campaign->subject);
            $send->update([
                'ses_message_id' => $messageId,
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $send->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            Log::warning("SendCampaignEmailJob #{$send->id}: ".$e->getMessage());
        }
    }
}
