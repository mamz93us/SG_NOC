<?php

use App\Models\NotificationRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed one routing rule per event type, all pointing at a single recipient.
 *
 * Until now `notification_rules` was empty, so every alert took the
 * "no rules configured" fallback in NotificationService and broadcast to all
 * seven super_admins — including people who have no business being paged about
 * a VPN traffic selector. This gives every event type a row on
 * /admin/notifications/rules that can be re-pointed from the UI.
 *
 * Deliberately conservative:
 *  - skips entirely if any rule already exists, so it can never overwrite
 *    routing that has been configured by hand;
 *  - skips if the default recipient is not found, rather than seeding rules
 *    that point at nobody;
 *  - a 60-minute cooldown per rule, matching the broadcast floor, so a flapping
 *    source cannot storm the inbox even if the upstream damper is missed.
 */
return new class extends Migration
{
    private const DEFAULT_RECIPIENT_EMAIL = 'mohamed.zahran@sssegypt.com';

    private const COOLDOWN_MINUTES = 60;

    public function up(): void
    {
        if (DB::table('notification_rules')->exists()) {
            return;
        }

        $userId = DB::table('users')
            ->where('email', self::DEFAULT_RECIPIENT_EMAIL)
            ->value('id');

        if (! $userId) {
            return;
        }

        $now = now();

        $rows = [];
        foreach (NotificationRule::eventTypeLabels() as $eventType => $label) {
            if ($eventType === '*') {
                continue; // the per-type rows already cover everything
            }

            $rows[] = [
                'event_type' => $eventType,
                'recipient_type' => 'user',
                'recipient_role' => null,
                'recipient_user_id' => $userId,
                'send_email' => true,
                'send_in_app' => true,
                'notify_telegram' => false,
                'notify_sms' => false,
                'notify_dashboard' => true,
                'cooldown_minutes' => self::COOLDOWN_MINUTES,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notification_rules')->insert($rows);
    }

    public function down(): void
    {
        $userId = DB::table('users')
            ->where('email', self::DEFAULT_RECIPIENT_EMAIL)
            ->value('id');

        if (! $userId) {
            return;
        }

        DB::table('notification_rules')
            ->where('recipient_type', 'user')
            ->where('recipient_user_id', $userId)
            ->delete();
    }
};
