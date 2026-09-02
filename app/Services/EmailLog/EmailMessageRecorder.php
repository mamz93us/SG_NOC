<?php

namespace App\Services\EmailLog;

use App\Models\EmailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Folds a single SES notification into the one-row-per-message log.
 *
 * Called from the SNS ingest for live events and from email-log:backfill for
 * history, so both paths produce identical rows. Every write is idempotent:
 * SNS retries, and the backfill replays the same events, so applying the same
 * notification twice must not double-count an open or move a bounce back to
 * delivered.
 */
class EmailMessageRecorder
{
    /**
     * @param  array  $payload  the decoded SES notification (the SNS "Message" body)
     * @param  int|null  $campaignSendId  set when the marketing pipeline matched a send
     */
    public function record(array $payload, ?int $campaignSendId = null): ?EmailMessage
    {
        $mail = $payload['mail'] ?? [];
        $messageId = trim((string) ($mail['messageId'] ?? ''));

        // Without a message id there is nothing to group on. SES always sends
        // one for real mail; this only trips on malformed test payloads.
        if ($messageId === '') {
            return null;
        }

        $eventType = $this->normalizeEventType(
            (string) ($payload['eventType'] ?? $payload['notificationType'] ?? '')
        );

        return DB::transaction(function () use ($messageId, $mail, $payload, $eventType, $campaignSendId) {
            // Lock the row so two events for the same message arriving together
            // can't both read a stale open_count and write the same value.
            $message = EmailMessage::where('ses_message_id', $messageId)->lockForUpdate()->first();

            $identity = $this->extractIdentity($mail);

            if (! $message) {
                $message = new EmailMessage([
                    'ses_message_id' => $messageId,
                    'status' => 'sent',
                    'source' => $campaignSendId ? 'marketing' : 'transactional',
                ] + $identity);
            } else {
                // Later events carry the same headers; fill anything still blank
                // (a Send may arrive after a Delivery, or vice versa).
                foreach ($identity as $key => $value) {
                    if (blank($message->{$key}) && filled($value)) {
                        $message->{$key} = $value;
                    }
                }
            }

            if ($campaignSendId && ! $message->email_campaign_send_id) {
                $message->email_campaign_send_id = $campaignSendId;
                $message->source = 'marketing';
            }

            $at = $this->eventTimestamp($payload, $eventType) ?? CarbonImmutable::now();
            $message->last_event_at = $at;

            if (! $message->sent_at) {
                $message->sent_at = $this->parseTime($mail['timestamp'] ?? null) ?? $at;
            }

            $this->applyEvent($message, $payload, $eventType, $at);

            $message->save();

            return $message;
        });
    }

    // ─── Event application ────────────────────────────────────────

    private function applyEvent(EmailMessage $message, array $payload, string $eventType, CarbonImmutable $at): void
    {
        switch ($eventType) {
            case 'Delivery':
                $message->delivered_at ??= $at;
                $this->promote($message, 'delivered');
                break;

            case 'Open':
                // Idempotency: replaying the backfill must not inflate this, so
                // the count is recomputed from the events table rather than
                // incremented blindly.
                $message->opened_at ??= $at;
                $message->open_count = $this->countEvents($message, 'Open');
                break;

            case 'Click':
                $message->clicked_at ??= $at;
                $message->click_count = $this->countEvents($message, 'Click');
                break;

            case 'Bounce':
                $message->bounce_type = (string) ($payload['bounce']['bounceType'] ?? '') ?: null;
                $message->bounce_subtype = (string) ($payload['bounce']['bounceSubType'] ?? '') ?: null;
                $message->failure_reason = $this->firstBounceDiagnostic($payload);
                $this->promote($message, 'bounced');
                break;

            case 'Complaint':
                $message->complaint_type = (string) ($payload['complaint']['complaintFeedbackType'] ?? '') ?: null;
                $this->promote($message, 'complained');
                break;

            case 'Reject':
                $message->failure_reason = (string) ($payload['reject']['reason'] ?? '') ?: null;
                $this->promote($message, 'rejected');
                break;

            case 'RenderingFailure':
                $message->failure_reason = (string) ($payload['failure']['errorMessage'] ?? '') ?: null;
                $this->promote($message, 'failed');
                break;
        }
    }

    /**
     * Move the status forward only. SES does not guarantee event order, and a
     * Delivery notification arriving after a Bounce must not repaint the row as
     * delivered — the bounce is the outcome that matters.
     */
    private function promote(EmailMessage $message, string $status): void
    {
        $current = EmailMessage::STATUS_RANK[$message->status] ?? 0;
        $candidate = EmailMessage::STATUS_RANK[$status] ?? 0;

        if ($candidate >= $current) {
            $message->status = $status;
        }
    }

    /** Recount from the events table so replays stay idempotent. */
    private function countEvents(EmailMessage $message, string $type): int
    {
        if (! $message->exists && ! $message->ses_message_id) {
            return 1;
        }

        $counted = DB::table('email_events')
            ->where('ses_message_id', $message->ses_message_id)
            ->where('event_type', $type)
            ->count();

        // The caller writes the email_events row before us, so the current event
        // is already counted. If it isn't (backfill ordering), never report 0.
        return max($counted, 1);
    }

    // ─── Extraction ───────────────────────────────────────────────

    /**
     * Sender, recipients and subject.
     *
     * SES exposes these two ways: `commonHeaders` (present when the
     * configuration set includes original headers) and the flat `headers`
     * array. Prefer commonHeaders, fall back to scanning headers, and fall
     * back again to the envelope fields, which are always present.
     */
    private function extractIdentity(array $mail): array
    {
        $common = $mail['commonHeaders'] ?? [];

        $fromRaw = $common['from'][0]
            ?? $this->header($mail, 'From')
            ?? (string) ($mail['source'] ?? '');

        [$fromName, $fromEmail] = $this->splitAddress((string) $fromRaw);

        // Envelope destination is authoritative for who actually received it.
        $destinations = $mail['destination'] ?? $common['to'] ?? [];
        $destinations = array_values(array_filter(array_map(
            fn ($d) => $this->splitAddress((string) $d)[1],
            is_array($destinations) ? $destinations : []
        )));

        $subject = $common['subject'] ?? $this->header($mail, 'Subject');

        return [
            'from_email' => $fromEmail ?: null,
            'from_name' => $fromName ?: null,
            'to_email' => $destinations[0] ?? null,
            'recipients' => $destinations ? implode(', ', $destinations) : null,
            'recipient_count' => max(count($destinations), 1),
            'subject' => $subject !== null ? mb_substr((string) $subject, 0, 2000) : null,
        ];
    }

    private function header(array $mail, string $name): ?string
    {
        foreach ($mail['headers'] ?? [] as $header) {
            if (strcasecmp((string) ($header['name'] ?? ''), $name) === 0) {
                return (string) ($header['value'] ?? '');
            }
        }

        return null;
    }

    /**
     * `"Samir Group IT" <it@samirgroup.com>` → ['Samir Group IT', 'it@samirgroup.com'].
     * A bare address returns a null name.
     *
     * @return array{0: ?string, 1: string}
     */
    private function splitAddress(string $raw): array
    {
        $raw = trim($raw);

        if (preg_match('/^(.*?)\s*<([^>]+)>\s*$/', $raw, $m)) {
            $name = trim($m[1], " \t\"'");

            return [$name !== '' ? $name : null, strtolower(trim($m[2]))];
        }

        return [null, strtolower($raw)];
    }

    private function firstBounceDiagnostic(array $payload): ?string
    {
        foreach ($payload['bounce']['bouncedRecipients'] ?? [] as $recipient) {
            if (! empty($recipient['diagnosticCode'])) {
                return mb_substr((string) $recipient['diagnosticCode'], 0, 1000);
            }
        }

        return null;
    }

    private function eventTimestamp(array $payload, string $eventType): ?CarbonImmutable
    {
        $key = match ($eventType) {
            'Delivery' => 'delivery',
            'Open' => 'open',
            'Click' => 'click',
            'Bounce' => 'bounce',
            'Complaint' => 'complaint',
            'Reject' => 'reject',
            'RenderingFailure' => 'failure',
            default => null,
        };

        return $this->parseTime($key ? ($payload[$key]['timestamp'] ?? null) : null)
            ?? $this->parseTime($payload['mail']['timestamp'] ?? null);
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeEventType(string $raw): string
    {
        return match (strtolower($raw)) {
            'send' => 'Send',
            'delivery' => 'Delivery',
            'open' => 'Open',
            'click' => 'Click',
            'bounce' => 'Bounce',
            'complaint' => 'Complaint',
            'reject' => 'Reject',
            'renderingfailure' => 'RenderingFailure',
            default => $raw,
        };
    }
}
