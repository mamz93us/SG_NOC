<?php

namespace App\Services\EmailMarketing;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Support\Facades\Log;

/**
 * Wraps the official AWS SDK MessageValidator so the controller doesn't
 * need to know about the SDK's quirks. Returns the parsed Message on
 * success, throws on failure (which the controller turns into a 401).
 *
 * The validator checks:
 *   - SigningCertURL is from amazonaws.com
 *   - Signature matches the message payload
 *   - Message is within an acceptable time window
 */
class SnsMessageVerifier
{
    public function verify(array $payload): Message
    {
        $message = new Message($payload);
        $validator = new MessageValidator;

        // validate() throws InvalidSnsMessageException on failure
        $validator->validate($message);

        return $message;
    }

    /**
     * Confirms an SNS subscription by fetching the SubscribeURL once.
     * AWS sends the URL in the SubscriptionConfirmation message;
     * fetching it (any HTTP verb works) confirms the topic subscription.
     */
    public function confirmSubscription(Message $message): bool
    {
        $url = $message['SubscribeURL'] ?? null;
        if (! $url) {
            return false;
        }

        // Capture the SubscribeURL up front so it's recoverable from logs
        // even if the outbound fetch below fails (firewall, DNS, TLS, etc.) —
        // the operator can then paste it into the SNS console manually.
        Log::info('SNS SubscribeURL received', [
            'topic' => $message['TopicArn'] ?? null,
            'subscribe_url' => (string) $url,
        ]);

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 15]]);
            $body = @file_get_contents((string) $url, false, $ctx);
            Log::info('SNS subscription confirmation fetched', [
                'topic' => $message['TopicArn'] ?? null,
                'ok' => $body !== false,
            ]);

            return $body !== false;
        } catch (\Throwable $e) {
            Log::error('SNS subscription confirmation failed: '.$e->getMessage());

            return false;
        }
    }
}
