<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NocEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Receives alert notifications fired from Graylog Event Definitions.
 *
 * Graylog's "HTTP Notification" sends a JSON body shaped like:
 *
 *   {
 *     "event_definition_id":    "65f4a1...",
 *     "event_definition_type":  "aggregation-v1",
 *     "event_definition_title": "Sophos: 5+ denies in 5min",
 *     "event_definition_description": "...",
 *     "job_definition_id": "...",
 *     "job_trigger_id":   "...",
 *     "event": {
 *       "id":          "01HK...",
 *       "event_definition_id": "...",
 *       "alert":       true,
 *       "priority":    2,
 *       "message":     "Sophos: 5+ denies in 5min",
 *       "timestamp":   "2026-04-29T14:52:00.000Z",
 *       "fields": { ... },
 *       "source":      "graylog-server",
 *       "key":         "JED-FW-01",
 *       "key_tuple":   ["JED-FW-01"],
 *       "group_by_fields": { "host": "JED-FW-01" }
 *     },
 *     "backlog": [  // Optional, the messages that triggered the alert.
 *       { "id":"...", "message":"...", "timestamp":"...", ... }
 *     ]
 *   }
 *
 * We map each alert to a NocEvent so existing notification routing
 * (email, Slack, in-app) fires the same way it does for Laravel-side
 * alerts. Idempotency: identical (event_definition_id, key) pairs
 * within the rule's cooldown collapse into one open event.
 */
class GraylogWebhookController extends Controller
{
    /**
     * POST /api/graylog/webhook
     *
     * Auth is via shared-secret header so we don't have to expose the
     * endpoint to user sessions. The secret lives in .env and Graylog
     * is configured to send it via "Custom HTTP headers".
     */
    public function __invoke(Request $request): JsonResponse
    {
        $expected = (string) config('services.graylog.webhook_secret', '');
        if ($expected === '' || !hash_equals($expected, (string) $request->header('X-Graylog-Secret', ''))) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $payload = $request->all();
        $event   = $payload['event'] ?? null;

        if (!is_array($event)) {
            return response()->json(['ok' => false, 'error' => 'missing event'], 422);
        }

        // Map Graylog priority (1=low … 4=critical) → NocEvent severity.
        $severity = match ((int) ($event['priority'] ?? 2)) {
            4       => 'critical',
            3       => 'warning',
            2       => 'warning',
            default => 'info',
        };

        $title    = (string) ($payload['event_definition_title'] ?? $event['message'] ?? 'Graylog alert');
        $key      = (string) ($event['key'] ?? '');
        $defId    = (string) ($payload['event_definition_id'] ?? $event['event_definition_id'] ?? '');
        $message  = $this->buildMessage($event, $payload['backlog'] ?? []);
        $now      = Carbon::now();
        $firstSeen = $this->parseTimestamp($event['timestamp'] ?? null) ?? $now;

        // Reuse an open event for the same (rule, key) pair so a flood
        // of repeated firings collapses into one row.
        $existing = NocEvent::where('module', 'syslog')
            ->where('source_type', 'graylog_alert')
            ->where('source_id', null)             // Graylog ids are GUIDs, kept in entity_id below
            ->where('entity_type', 'graylog_event_definition')
            ->where('entity_id', $defId . ':' . $key)
            ->whereIn('status', ['open', 'acknowledged'])
            ->first();

        if ($existing) {
            $existing->update([
                'last_seen' => $now,
                'message'   => $this->truncate($message, 1000),
                'severity'  => $severity,
            ]);
            return response()->json(['ok' => true, 'noc_event_id' => $existing->id, 'mode' => 'updated']);
        }

        $nocEvent = NocEvent::create([
            'module'      => 'syslog',
            'entity_type' => 'graylog_event_definition',
            'entity_id'   => $defId . ':' . $key,
            'source_type' => 'graylog_alert',
            'severity'    => $severity,
            'title'       => $this->truncate($title, 200),
            'message'     => $this->truncate($message, 1000),
            'first_seen'  => $firstSeen,
            'last_seen'   => $now,
            'status'      => 'open',
        ]);

        Log::info('Graylog alert ingested', [
            'noc_event_id' => $nocEvent->id,
            'definition'   => $defId,
            'key'          => $key,
            'severity'     => $severity,
        ]);

        return response()->json(['ok' => true, 'noc_event_id' => $nocEvent->id, 'mode' => 'created']);
    }

    /**
     * Compose a human-readable message from the event + a sample of
     * backlog messages, capped at 1000 chars by the caller.
     */
    private function buildMessage(array $event, array $backlog): string
    {
        $parts = [];

        if (!empty($event['message'])) {
            $parts[] = (string) $event['message'];
        }

        $groupBy = $event['group_by_fields'] ?? [];
        if (!empty($groupBy)) {
            $parts[] = 'matched: ' . collect($groupBy)
                ->map(fn ($v, $k) => "{$k}={$v}")->join(', ');
        }

        if (!empty($backlog) && is_array($backlog)) {
            // Show up to 3 example messages so the on-call has context.
            $samples = array_slice($backlog, 0, 3);
            foreach ($samples as $b) {
                $msg = $b['message'] ?? ($b['fields']['full_message'] ?? '');
                if ($msg !== '') {
                    $parts[] = '· ' . $this->truncate(trim((string) $msg), 200);
                }
            }
        }

        return implode("\n", $parts) ?: 'Graylog alert (no message body)';
    }

    private function parseTimestamp(?string $ts): ?Carbon
    {
        if (!$ts) return null;
        try {
            return Carbon::parse($ts);
        } catch (\Throwable) {
            return null;
        }
    }

    private function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
