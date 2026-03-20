<?php

namespace App\Services;

use App\Models\AlertRule;
use App\Models\AlertState;
use Illuminate\Support\Facades\Log;

class AlertEvaluator
{
    /**
     * Evaluate all applicable alert rules for a given entity and value.
     * Called from CollectSnmpMetricsJob and PollPrinterSnmpJob.
     */
    public static function evaluate(
        string $entityType,  // 'sensor', 'printer', 'host'
        int $entityId,
        string $sensorClass, // e.g. 'toner', 'temperature'
        float $value,
        ?string $entityName = null
    ): void {
        $rules = AlertRule::where('disabled', false)
            ->where('target_type', $entityType)
            ->where(function ($q) use ($sensorClass) {
                $q->whereNull('sensor_class')
                  ->orWhere('sensor_class', $sensorClass);
            })
            ->get();

        foreach ($rules as $rule) {
            self::processRule($rule, $entityType, $entityId, $value, $entityName);
        }
    }

    protected static function processRule(
        AlertRule $rule,
        string $entityType,
        int $entityId,
        float $value,
        ?string $entityName
    ): void {
        $shouldAlert = $rule->evaluate($value);

        $state = AlertState::firstOrCreate(
            ['alert_rule_id' => $rule->id, 'entity_type' => $entityType, 'entity_id' => $entityId],
            ['state' => 'ok']
        );

        if ($shouldAlert) {
            // Hysteresis: already alerted, don't fire again unless interval passed
            if ($state->state === 'alerted' || $state->state === 'acknowledged') {
                if ($state->last_alerted_at &&
                    now()->diffInSeconds($state->last_alerted_at) < $rule->interval_seconds) {
                    return; // Too soon to re-notify
                }
            }

            // Delay check: must be in alert condition for delay_seconds before firing
            if ($state->state === 'ok') {
                if (!$state->first_triggered_at) {
                    $state->update(['first_triggered_at' => now(), 'triggered_value' => $value]);
                    return; // Start the delay clock
                }
                if (now()->diffInSeconds($state->first_triggered_at) < $rule->delay_seconds) {
                    return; // Still within delay window
                }
            }

            // Fire alert
            $state->update([
                'state'          => 'alerted',
                'triggered_value' => $value,
                'last_alerted_at' => now(),
                'recovered_at'   => null,
                'alert_count'    => $state->alert_count + 1,
            ]);

            self::sendNotification($rule, $state, $entityName, $value, false);

        } else {
            // Check hysteresis: only recover if value is 5% above threshold
            $hysteresis = abs($rule->threshold_value) * 0.05;
            $recovered = match ($rule->operator) {
                '<='    => $value > $rule->threshold_value + $hysteresis,
                '>='    => $value < $rule->threshold_value - $hysteresis,
                default => !$rule->evaluate($value),
            };

            if ($recovered && in_array($state->state, ['alerted', 'acknowledged'])) {
                $state->update([
                    'state'              => 'ok',
                    'recovered_at'       => now(),
                    'first_triggered_at' => null,
                ]);

                if ($rule->recovery_alert) {
                    self::sendNotification($rule, $state, $entityName, $value, true);
                }
            } elseif ($state->state === 'ok') {
                // Clear the delay clock if condition no longer holds
                $state->update(['first_triggered_at' => null, 'triggered_value' => null]);
            }
        }
    }

    protected static function sendNotification(
        AlertRule $rule,
        AlertState $state,
        ?string $entityName,
        float $value,
        bool $isRecovery
    ): void {
        $subject = $isRecovery
            ? "[RECOVERED] {$rule->name} — {$entityName}"
            : "[{$rule->severity}] {$rule->name} — {$entityName}";

        $message = $isRecovery
            ? "Alert recovered: {$rule->name}\nEntity: {$entityName}\nCurrent value: {$value}"
            : "Alert triggered: {$rule->name}\nEntity: {$entityName}\nTriggered value: {$value}\nCondition: value {$rule->operator} {$rule->threshold_value}";

        Log::channel('daily')->info("[AlertEvaluator] {$subject} | value={$value}");

        // Email notification
        if ($rule->notify_email) {
            $emails = collect(explode(',', $rule->notify_emails ?? ''))
                ->map(fn ($e) => trim($e))
                ->filter()
                ->toArray();

            // Add admin and super_admin emails
            $adminEmails = \App\Models\User::whereIn('role', ['admin', 'super_admin'])
                ->whereNotNull('email')
                ->pluck('email')
                ->toArray();

            $allEmails = array_unique(array_merge($emails, $adminEmails));

            foreach ($allEmails as $email) {
                try {
                    \Illuminate\Support\Facades\Mail::raw($message, function ($mail) use ($email, $subject) {
                        $mail->to($email)->subject($subject);
                    });
                } catch (\Throwable $e) {
                    Log::warning("[AlertEvaluator] Failed to send email to {$email}: " . $e->getMessage());
                }
            }
        }

        // Slack notification
        if ($rule->notify_slack && $rule->slack_webhook) {
            try {
                $color   = $isRecovery ? 'good' : ($rule->severity === 'critical' ? 'danger' : 'warning');
                $payload = json_encode([
                    'attachments' => [[
                        'color' => $color,
                        'title' => $subject,
                        'text'  => $message,
                        'ts'    => now()->timestamp,
                    ]],
                ]);
                $ch = curl_init($rule->slack_webhook);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_exec($ch);
                curl_close($ch);
            } catch (\Throwable $e) {
                Log::warning("[AlertEvaluator] Slack notification failed: " . $e->getMessage());
            }
        }
    }
}
