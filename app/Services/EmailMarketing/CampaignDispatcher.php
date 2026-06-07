<?php

namespace App\Services\EmailMarketing;

use App\Jobs\EmailMarketing\DispatchCampaignBatchJob;
use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSegment;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailSuppression;
use App\Models\Setting;
use App\Models\Training\CourseCertificate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the per-campaign send pipeline:
 *
 *   1. Resolve recipients from list_id or segment_id.
 *   2. Populate email_campaign_sends with one row per recipient
 *      (insert-or-ignore so resumption is safe).
 *   3. Filter against suppressions.
 *   4. Spend the per-minute SES budget by dispatching batches
 *      to DispatchCampaignBatchJob until budget or queue is empty.
 *   5. When all queued rows are processed, flip campaign to 'sent'.
 */
class CampaignDispatcher
{
    public function __construct(
        private SesService $ses,
        private SuppressionManager $suppressions,
    ) {}

    /**
     * Spend up to the per-minute budget on this campaign. Caller
     * (DispatchScheduledCampaignsCommand) loops over campaigns and
     * exits when the global budget for the minute is exhausted.
     *
     * Returns number of sends dispatched this call.
     */
    public function tick(EmailCampaign $campaign, int $remainingBudget): int
    {
        if ($remainingBudget <= 0) {
            return 0;
        }

        // Defense in depth: a campaign still flagged for approval must never send,
        // even if its status was set to 'scheduled' by some other path. Park it.
        if ($campaign->requires_approval && $campaign->approved_at === null) {
            if ($campaign->status === 'scheduled') {
                $campaign->update(['status' => 'pending_approval']);
            }

            return 0;
        }

        if ($campaign->status === 'scheduled') {
            $campaign->status = 'sending';
            $campaign->started_at = $campaign->started_at ?? now();
            $campaign->save();
            $this->ensureSendsExist($campaign);
        }

        if ($campaign->status !== 'sending') {
            return 0;
        }

        // Already-populated suppressions
        $this->markSuppressed($campaign);

        $batchSize = (int) config('email_marketing.batch_size', 50);
        $dispatched = 0;

        while ($remainingBudget > 0) {
            $take = min($batchSize, $remainingBudget);
            $sendIds = EmailCampaignSend::where('email_campaign_id', $campaign->id)
                ->where('status', 'queued')
                ->limit($take)
                ->pluck('id')
                ->all();

            if (empty($sendIds)) {
                break;
            }

            try {
                (new DispatchCampaignBatchJob($campaign->id, $sendIds))->handle(
                    $this->ses,
                    new MergeTagRenderer,
                    $this->suppressions
                );
            } catch (\Throwable $e) {
                Log::error("DispatchCampaignBatchJob failed for campaign #{$campaign->id}: ".$e->getMessage());
                break;
            }

            $dispatched += count($sendIds);
            $remainingBudget -= count($sendIds);
        }

        // If nothing left queued, mark campaign complete.
        $queuedLeft = EmailCampaignSend::where('email_campaign_id', $campaign->id)
            ->where('status', 'queued')->exists();

        if (! $queuedLeft && $campaign->status === 'sending') {
            $campaign->status = 'sent';
            $campaign->sent_at = now();
            $campaign->save();
        }

        return $dispatched;
    }

    /**
     * Populate email_campaign_sends in chunks. Idempotent — if rows
     * already exist (e.g. previous tick was interrupted), skip.
     */
    public function ensureSendsExist(EmailCampaign $campaign): int
    {
        // If we already have any sends, assume populated.
        if (EmailCampaignSend::where('email_campaign_id', $campaign->id)->exists()) {
            return 0;
        }

        $recipientIds = $this->resolveRecipientIds($campaign);
        if (empty($recipientIds)) {
            $campaign->total_recipients = 0;
            $campaign->save();

            return 0;
        }

        $now = now();
        $count = 0;
        foreach (array_chunk($recipientIds, 1000) as $chunk) {
            $rows = array_map(fn ($id) => [
                'email_campaign_id' => $campaign->id,
                'email_subscriber_id' => $id,
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            DB::table('email_campaign_sends')->insertOrIgnore($rows);
            $count += count($rows);
        }

        $campaign->total_recipients = $count;
        $campaign->save();

        return $count;
    }

    /**
     * Read-only recipient email addresses for this campaign — used by the approval
     * gate to classify internal vs external recipients. Unlike resolveRecipientIds()
     * this NEVER creates subscriber rows (course campaigns read certificate emails
     * directly).
     *
     * @return \Illuminate\Support\Collection<int,string>
     */
    public function recipientEmails(EmailCampaign $campaign): \Illuminate\Support\Collection
    {
        if ($campaign->course_id) {
            return CourseCertificate::where('course_id', $campaign->course_id)
                ->whereNotNull('employee_id')
                ->pluck('email')
                ->filter()
                ->values();
        }

        $ids = $this->resolveRecipientIds($campaign);

        return empty($ids)
            ? collect()
            : EmailSubscriber::whereIn('id', $ids)->pluck('email')->filter()->values();
    }

    private function resolveRecipientIds(EmailCampaign $campaign): array
    {
        // Course campaigns: recipients are the holders of certificates for the
        // course, regardless of any list/segment selection. Orphaned certificates
        // (employee_id IS NULL) are skipped — they have no validated recipient.
        if ($campaign->course_id) {
            return $this->resolveCourseRecipients($campaign);
        }

        if ($campaign->email_list_id) {
            $list = EmailList::find($campaign->email_list_id);
            if (! $list) {
                return [];
            }

            return $list->subscribers()
                ->wherePivotNull('unsubscribed_at')
                ->where('email_subscribers.status', 'subscribed')
                ->pluck('email_subscribers.id')
                ->all();
        }

        if ($campaign->email_segment_id) {
            $segment = EmailSegment::find($campaign->email_segment_id);

            return $segment ? $this->resolveSegment($segment) : [];
        }

        return [];
    }

    /**
     * For a course campaign: ensure an EmailSubscriber exists for each
     * certificate holder and return their subscriber ids. Mirrors the dynamic
     * list pattern — auto-creates as 'subscribed' with source='certificate'.
     */
    private function resolveCourseRecipients(EmailCampaign $campaign): array
    {
        $certs = CourseCertificate::where('course_id', $campaign->course_id)
            ->whereNotNull('employee_id')
            ->get(['id', 'email']);

        if ($certs->isEmpty()) {
            return [];
        }

        $ids = [];
        foreach ($certs as $cert) {
            $email = strtolower(trim((string) $cert->email));
            if ($email === '') {
                continue;
            }
            $sub = EmailSubscriber::firstOrCreate(
                ['email' => $email],
                [
                    'status' => 'subscribed',
                    'source' => 'certificate',
                    'confirmed_at' => now(),
                ]
            );
            $ids[$sub->id] = true;
        }

        return array_keys($ids);
    }

    /**
     * Minimal segment evaluator. Supports operator + conditions:
     *   {field, op, value} with fields: status, tags, attributes.*
     */
    private function resolveSegment(EmailSegment $segment): array
    {
        $rules = $segment->rules ?? [];
        $operator = strtoupper($rules['operator'] ?? 'AND');
        $conditions = $rules['conditions'] ?? [];

        $query = EmailSubscriber::query()->where('status', 'subscribed');

        $applyCondition = function ($q, array $cond) {
            $field = strtolower((string) ($cond['field'] ?? ''));
            $op = (string) ($cond['op'] ?? '=');
            $value = $cond['value'] ?? null;

            if ($field === 'status') {
                $q->where('status', $value);
            } elseif ($field === 'tags' && $op === 'includes') {
                $ids = is_array($value) ? $value : [$value];
                $q->whereHas('tags', fn ($t) => $t->whereIn('email_tags.id', $ids));
            } elseif (str_starts_with($field, 'attributes.')) {
                $key = substr($field, strlen('attributes.'));
                // MySQL JSON contains
                if ($op === '=') {
                    $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(attributes, ?)) = ?', ['$.'.$key, $value]);
                } elseif ($op === 'contains') {
                    $q->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(attributes, ?)) LIKE ?', ['$.'.$key, '%'.$value.'%']);
                }
            }
        };

        if ($operator === 'OR' && ! empty($conditions)) {
            $query->where(function ($q) use ($conditions, $applyCondition) {
                foreach ($conditions as $cond) {
                    $q->orWhere(function ($sub) use ($cond, $applyCondition) {
                        $applyCondition($sub, $cond);
                    });
                }
            });
        } else {
            foreach ($conditions as $cond) {
                $applyCondition($query, $cond);
            }
        }

        return $query->pluck('id')->all();
    }

    private function markSuppressed(EmailCampaign $campaign): void
    {
        // Mark queued rows whose subscriber email is on suppression list
        // (or who has flipped status since the campaign was prepped).
        DB::transaction(function () use ($campaign) {
            // Qualify every column with its table — both email_campaign_sends and
            // email_subscribers have a `status` column, so an unqualified `where('status', …)`
            // throws "Column 'status' is ambiguous" once the join is applied.
            $queued = EmailCampaignSend::query()
                ->join('email_subscribers', 'email_subscribers.id', '=', 'email_campaign_sends.email_subscriber_id')
                ->where('email_campaign_sends.email_campaign_id', $campaign->id)
                ->where('email_campaign_sends.status', 'queued')
                ->select(
                    'email_campaign_sends.id as send_id',
                    'email_subscribers.email',
                    'email_subscribers.status as subscriber_status'
                )
                ->get();

            $emails = $queued->pluck('email')->all();
            if (empty($emails)) {
                return;
            }

            $suppressed = EmailSuppression::whereIn('email', $emails)->pluck('email')->all();
            $suppressedSet = array_flip($suppressed);

            foreach ($queued as $row) {
                if (isset($suppressedSet[$row->email]) || $row->subscriber_status !== 'subscribed') {
                    EmailCampaignSend::where('id', $row->send_id)->update([
                        'status' => 'suppressed',
                        'error_message' => isset($suppressedSet[$row->email])
                            ? 'On global suppression list'
                            : "Subscriber status: {$row->subscriber_status}",
                    ]);
                }
            }
        });
    }

    /**
     * Per-minute send budget derived from SES quota (cached) and an
     * optional manual override in Settings.
     */
    public function perMinuteBudget(): int
    {
        try {
            $quota = $this->ses->getSendQuota();
        } catch (\Throwable $e) {
            // If quota lookup fails, fall back to a conservative default.
            $quota = ['MaxSendRate' => 1.0];
        }

        $rateFromQuota = (int) floor((float) ($quota['MaxSendRate'] ?? 1.0) * 60);
        $settings = Setting::get();
        $override = (int) ($settings->ses_throttle_per_second ?? 0);
        if ($override > 0) {
            $rateFromQuota = min($rateFromQuota, $override * 60);
        }

        $floor = (int) (config('email_marketing.throttle_floor_per_second') ?? 0);
        if ($floor > 0) {
            $rateFromQuota = min($rateFromQuota, $floor * 60);
        }

        return max(1, $rateFromQuota);
    }
}
