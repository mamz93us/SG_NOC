<?php

namespace App\Jobs\EmailMarketing;

use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\EmailMarketing\EmailEvent;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Services\EmailMarketing\SuppressionManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists a single SES notification (already verified by the controller)
 * to the email_events table, updates the matching campaign_send + campaign
 * counters, and updates suppressions for hard bounces / complaints.
 *
 * Designed to be called inline from the SNS controller (synchronous)
 * for MVP, but lives as a Job so it can be moved to a queue later
 * with zero refactor.
 */
class ProcessSnsEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $sesMessage) {}

    public function handle(SuppressionManager $suppressions): void
    {
        $payload = $this->sesMessage;
        $eventType = (string) ($payload['eventType'] ?? $payload['notificationType'] ?? '');
        $mail = $payload['mail'] ?? [];
        $messageId = (string) ($mail['messageId'] ?? '');

        // Resolve campaign_send by SES message id, then by tags.
        $send = null;
        if ($messageId) {
            $send = EmailCampaignSend::where('ses_message_id', $messageId)->first();
        }
        if (! $send) {
            $tags = $mail['tags'] ?? [];
            $sendId = $this->extractTag($tags, 'send_id');
            $campaignId = $this->extractTag($tags, 'campaign_id');
            $subscriberId = $this->extractTag($tags, 'subscriber_id');
            if ($sendId) {
                $send = EmailCampaignSend::find((int) $sendId);
            } elseif ($campaignId && $subscriberId) {
                $send = EmailCampaignSend::where('email_campaign_id', (int) $campaignId)
                    ->where('email_subscriber_id', (int) $subscriberId)
                    ->first();
            }
        }

        // Recipient email for suppression handling
        $recipientEmail = null;
        $dests = $mail['destination'] ?? [];
        if (is_array($dests) && ! empty($dests)) {
            $recipientEmail = (string) $dests[0];
        }
        if (! $recipientEmail && $send) {
            $recipientEmail = $send->subscriber?->email;
        }

        $subscriberId = $send?->email_subscriber_id;
        if (! $subscriberId && $recipientEmail) {
            $subscriberId = EmailSubscriber::where('email', strtolower($recipientEmail))->value('id');
        }

        // Capture event row
        $eventRow = [
            'ses_message_id' => $messageId ?: null,
            'email_campaign_send_id' => $send?->id,
            'email_subscriber_id' => $subscriberId,
            'event_type' => $this->normalizeEventType($eventType),
            'raw_payload' => $payload,
            'created_at' => now(),
        ];

        // Per-type extra fields
        switch ($eventRow['event_type']) {
            case 'Click':
                $eventRow['url'] = (string) ($payload['click']['link'] ?? '');
                $eventRow['user_agent'] = (string) ($payload['click']['userAgent'] ?? '');
                $eventRow['ip_address'] = (string) ($payload['click']['ipAddress'] ?? '');
                break;
            case 'Open':
                $eventRow['user_agent'] = (string) ($payload['open']['userAgent'] ?? '');
                $eventRow['ip_address'] = (string) ($payload['open']['ipAddress'] ?? '');
                break;
            case 'Bounce':
                $eventRow['bounce_type'] = (string) ($payload['bounce']['bounceType'] ?? '');
                $eventRow['bounce_subtype'] = (string) ($payload['bounce']['bounceSubType'] ?? '');
                break;
            case 'Complaint':
                $eventRow['complaint_type'] = (string) ($payload['complaint']['complaintFeedbackType'] ?? '');
                break;
        }

        // Write event (insert via Eloquent so casts apply)
        EmailEvent::create($eventRow);

        if (! $send) {
            Log::info('SNS event for unknown SES message id', ['msg_id' => $messageId, 'type' => $eventType]);

            return;
        }

        // Update send status + campaign counters
        $this->applyToSendAndCampaign($send, $eventRow, $recipientEmail, $suppressions);
    }

    private function applyToSendAndCampaign(EmailCampaignSend $send, array $event, ?string $recipientEmail, SuppressionManager $suppressions): void
    {
        $campaignId = $send->email_campaign_id;
        $type = $event['event_type'];

        switch ($type) {
            case 'Delivery':
                if ($send->status !== 'delivered') {
                    $send->update(['status' => 'delivered', 'delivered_at' => now()]);
                    $this->bump($campaignId, 'total_delivered', 1);
                }
                break;

            case 'Bounce':
                if ($send->status !== 'bounced') {
                    $send->update(['status' => 'bounced', 'error_message' => $event['bounce_type'].'/'.$event['bounce_subtype']]);
                    $this->bump($campaignId, 'total_bounces', 1);
                }
                if ($recipientEmail && $event['bounce_type'] === 'Permanent') {
                    $suppressions->add($recipientEmail, 'hard_bounce', 'sns_event');
                    $suppressions->syncSubscriberStatus($recipientEmail, 'hard_bounce');
                }
                break;

            case 'Complaint':
                if ($send->status !== 'complained') {
                    $send->update(['status' => 'complained']);
                    $this->bump($campaignId, 'total_complaints', 1);
                }
                if ($recipientEmail) {
                    $suppressions->add($recipientEmail, 'complaint', 'sns_event');
                    $suppressions->syncSubscriberStatus($recipientEmail, 'complaint');
                }
                break;

            case 'Open':
                $this->bump($campaignId, 'total_opens', 1);
                // Count unique opens by checking for prior Open on this send
                $hasPrior = EmailEvent::where('email_campaign_send_id', $send->id)
                    ->where('event_type', 'Open')
                    ->where('id', '!=', $event['id'] ?? 0)
                    ->exists();
                if (! $hasPrior) {
                    $this->bump($campaignId, 'total_unique_opens', 1);
                }
                break;

            case 'Click':
                $this->bump($campaignId, 'total_clicks', 1);
                $hasPrior = EmailEvent::where('email_campaign_send_id', $send->id)
                    ->where('event_type', 'Click')
                    ->where('id', '!=', $event['id'] ?? 0)
                    ->exists();
                if (! $hasPrior) {
                    $this->bump($campaignId, 'total_unique_clicks', 1);
                }
                break;
        }
    }

    private function bump(int $campaignId, string $column, int $by): void
    {
        DB::table('email_campaigns')
            ->where('id', $campaignId)
            ->update([$column => DB::raw("{$column} + {$by}"), 'updated_at' => now()]);
    }

    private function normalizeEventType(string $raw): string
    {
        // SES sends "Bounce" or "Complaint" in notificationType, and
        // "Send"/"Delivery"/"Open"/"Click"/"Reject"/"RenderingFailure" in eventType.
        $allowed = ['Send', 'Delivery', 'Open', 'Click', 'Bounce', 'Complaint', 'Reject', 'RenderingFailure'];
        foreach ($allowed as $type) {
            if (strcasecmp($raw, $type) === 0) {
                return $type;
            }
        }

        return 'Send';
    }

    private function extractTag(array $tags, string $name): ?string
    {
        if (isset($tags[$name])) {
            $v = $tags[$name];

            return is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        }

        return null;
    }
}
