<?php

namespace App\Jobs\EmailMarketing;

use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\Training\CourseCertificate;
use App\Services\EmailMarketing\MergeTagRenderer;
use App\Services\EmailMarketing\SesService;
use App\Services\EmailMarketing\SuppressionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchCampaignBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(
        public int $campaignId,
        public array $sendIds,
    ) {}

    public function handle(SesService $ses, MergeTagRenderer $renderer, SuppressionManager $suppressions): void
    {
        $campaign = EmailCampaign::with('template', 'list')->find($this->campaignId);
        if (! $campaign || empty($this->sendIds)) {
            return;
        }

        $renderedHtml = (string) ($campaign->template?->rendered_html ?? '');
        if ($renderedHtml === '') {
            Log::error("Campaign #{$campaign->id} has no rendered_html on its template");
            EmailCampaignSend::whereIn('id', $this->sendIds)->update([
                'status' => 'failed',
                'error_message' => 'Template has no rendered HTML',
            ]);

            return;
        }

        $sends = EmailCampaignSend::with('subscriber')
            ->whereIn('id', $this->sendIds)
            ->where('status', 'queued')
            ->get();

        $delivered = 0;
        $bounced = 0;
        $sentCount = 0;

        foreach ($sends as $send) {
            $subscriber = $send->subscriber;
            if (! $subscriber) {
                $send->update(['status' => 'failed', 'error_message' => 'Subscriber missing']);

                continue;
            }

            // Race-safe re-check
            if ($suppressions->isSuppressed($subscriber->email)) {
                $send->update(['status' => 'suppressed', 'error_message' => 'On suppression list at send time']);

                continue;
            }

            try {
                $html = $renderer->render($renderedHtml, $subscriber, $send, $campaign->list, $campaign);
                $subject = $campaign->subject;

                $messageId = $ses->sendCampaignEmail($send, $html, $subject);

                $send->update([
                    'ses_message_id' => $messageId,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
                $sentCount++;

                // For course campaigns, stamp sent_at on the certificate so
                // "include already-sent" filters and progress reports stay accurate.
                if ($campaign->course_id) {
                    CourseCertificate::where('course_id', $campaign->course_id)
                        ->whereRaw('LOWER(email) = ?', [strtolower((string) $subscriber->email)])
                        ->update(['sent_at' => now()]);
                }
            } catch (\Throwable $e) {
                $send->update([
                    'status' => 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 1000),
                ]);
                $bounced++;
                Log::warning("Campaign send #{$send->id} failed: ".$e->getMessage());
            }
        }

        // Aggregate counters — single atomic update
        if ($sentCount > 0 || $bounced > 0) {
            DB::table('email_campaigns')->where('id', $campaign->id)->update([
                'total_sent' => DB::raw('total_sent + '.$sentCount),
                'total_bounces' => DB::raw('total_bounces + '.$bounced),
                'updated_at' => now(),
            ]);
        }
    }
}
