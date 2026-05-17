<?php

namespace App\Services\EmailMarketing;

use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailSuppression;

class SuppressionManager
{
    public function add(string $email, string $reason, ?string $source = null, ?int $userId = null, ?string $notes = null): EmailSuppression
    {
        $email = strtolower(trim($email));

        return EmailSuppression::updateOrCreate(
            ['email' => $email],
            [
                'reason' => $reason,
                'source' => $source,
                'created_by' => $userId,
                'notes' => $notes,
            ]
        );
    }

    public function remove(string $email): bool
    {
        return EmailSuppression::where('email', strtolower(trim($email)))->delete() > 0;
    }

    public function isSuppressed(string $email): bool
    {
        return EmailSuppression::isSuppressed($email);
    }

    /**
     * Given a set of email addresses, return those that are suppressed.
     * Used to filter recipient lists at dispatch time.
     */
    public function filterSuppressed(array $emails): array
    {
        $normalized = array_map(fn ($e) => strtolower(trim($e)), $emails);

        return EmailSuppression::whereIn('email', $normalized)->pluck('email')->all();
    }

    /**
     * Mark the subscriber's global status when added to suppression.
     * Hard-bounce → status='bounced'; complaint → status='complained'.
     */
    public function syncSubscriberStatus(string $email, string $reason): void
    {
        $email = strtolower(trim($email));
        $subscriber = EmailSubscriber::where('email', $email)->first();
        if (! $subscriber) {
            return;
        }

        if ($reason === 'hard_bounce' && $subscriber->status !== 'bounced') {
            $subscriber->status = 'bounced';
            $subscriber->bounced_at = $subscriber->bounced_at ?? now();
            $subscriber->save();
        } elseif ($reason === 'complaint' && $subscriber->status !== 'complained') {
            $subscriber->status = 'complained';
            $subscriber->complained_at = $subscriber->complained_at ?? now();
            $subscriber->save();
        }
    }
}
