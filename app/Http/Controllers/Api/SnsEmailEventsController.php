<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\EmailMarketing\ProcessSnsEventJob;
use App\Services\EmailMarketing\SnsMessageVerifier;
use App\Services\EmailMarketing\SuppressionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives SES → SNS notifications and writes them to email_events,
 * updating per-campaign counters and global suppressions as a side
 * effect. AWS signs every SNS message; we verify with the canonical
 * Aws\Sns\MessageValidator. No shared-secret header.
 */
class SnsEmailEventsController extends Controller
{
    public function handle(Request $request, SnsMessageVerifier $verifier, SuppressionManager $suppressions): JsonResponse
    {
        $raw = $request->getContent();
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            Log::warning('SNS webhook: invalid JSON');

            return response()->json(['ok' => false], 400);
        }

        try {
            $message = $verifier->verify($payload);
        } catch (\Throwable $e) {
            Log::warning('SNS signature verification failed: '.$e->getMessage());

            return response()->json(['ok' => false], 401);
        }

        $type = (string) ($message['Type'] ?? '');

        // Handle subscription confirmation once during topic bootstrap
        if ($type === 'SubscriptionConfirmation') {
            $ok = $verifier->confirmSubscription($message);

            return response()->json(['ok' => $ok, 'mode' => 'subscription-confirmation']);
        }

        if ($type === 'UnsubscribeConfirmation') {
            Log::info('SNS topic unsubscribed', ['topic' => $message['TopicArn'] ?? null]);

            return response()->json(['ok' => true, 'mode' => 'unsubscribe-confirmation']);
        }

        if ($type !== 'Notification') {
            Log::info('SNS unknown message type', ['type' => $type]);

            return response()->json(['ok' => true, 'mode' => 'ignored']);
        }

        $msgJson = (string) ($message['Message'] ?? '');
        $sesMessage = json_decode($msgJson, true);
        if (! is_array($sesMessage)) {
            Log::warning('SNS notification body was not JSON');

            return response()->json(['ok' => true, 'mode' => 'ignored']);
        }

        try {
            (new ProcessSnsEventJob($sesMessage))->handle($suppressions);
        } catch (\Throwable $e) {
            Log::error('ProcessSnsEventJob failed: '.$e->getMessage());

            // 500 → SNS retries, which is what we want.
            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }
}
