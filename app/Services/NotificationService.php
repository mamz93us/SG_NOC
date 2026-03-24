<?php

namespace App\Services;

use App\Jobs\SendNotificationEmailJob;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationService
{
    /**
     * Create an in-app notification and optionally dispatch an email for a single user.
     *
     * @param  bool  $skipRules  Pass true for broadcast calls (notifyAdmins / notifyRole)
     *                           to prevent notification rules from re-sending to the same
     *                           audience and producing duplicate emails.
     */
    public function notify(
        int     $userId,
        string  $type,
        string  $title,
        string  $message,
        ?string $link = null,
        string  $severity = 'info',
        bool    $skipRules = false
    ): Notification {
        $notification = Notification::create([
            'user_id'    => $userId,
            'type'       => $type,
            'severity'   => $severity,
            'title'      => $title,
            'message'    => $message,
            'link'       => $link,
            'is_read'    => false,
            'created_at' => now(),
        ]);

        // 1. Per-user email preference
        $settings = NotificationSetting::forUser($userId);
        if ($settings->notify_email) {
            SendNotificationEmailJob::dispatch($notification)->afterCommit();
        }

        // 2. Notification routing rules — skipped for broadcast calls to avoid
        //    each admin's notification re-firing rules that target the admin role,
        //    which would result in N×(N-1) duplicate emails for N admins.
        if (! $skipRules) {
            $this->applyNotificationRules($notification);
        }

        return $notification;
    }

    /**
     * Broadcast to all users with a given role.
     * Rules are skipped — the broadcast already covers the target audience.
     */
    public function notifyRole(
        string  $role,
        string  $type,
        string  $title,
        string  $message,
        ?string $link = null,
        string  $severity = 'info'
    ): void {
        $users = User::where('role', $role)->get();
        foreach ($users as $user) {
            $this->notify($user->id, $type, $title, $message, $link, $severity, skipRules: true);
        }
    }

    /**
     * Broadcast to all super_admin and admin users.
     * Rules are skipped — the broadcast already covers the target audience.
     */
    public function notifyAdmins(
        string  $type,
        string  $title,
        string  $message,
        ?string $link = null,
        string  $severity = 'info'
    ): void {
        $users = User::whereIn('role', ['super_admin', 'admin'])->get();
        foreach ($users as $user) {
            $this->notify($user->id, $type, $title, $message, $link, $severity, skipRules: true);
        }
    }

    /**
     * Send a notification only to recipients defined in active rules for the given
     * event type, honouring per-rule send_in_app / send_email flags.
     *
     * Falls back to notifyAdmins() when no rules are configured for the event type,
     * so alerts are never silently dropped.
     */
    public function notifyViaRules(
        string  $type,
        string  $title,
        string  $message,
        ?string $link = null,
        string  $severity = 'info'
    ): void {
        $rules = NotificationRule::active()->forEvent($type)->get();

        if ($rules->isEmpty()) {
            // No rules configured for this event — fall back to all admins
            $this->notifyAdmins($type, $title, $message, $link, $severity);
            return;
        }

        $notifiedIds = collect(); // deduplicate: first matching rule wins per user

        foreach ($rules as $rule) {
            if ($rule->recipient_type === 'role') {
                $recipients = User::where('role', $rule->recipient_role)->get();
            } else {
                $user = $rule->recipientUser;
                $recipients = $user ? collect([$user]) : collect();
            }

            foreach ($recipients as $recipient) {
                if ($notifiedIds->contains($recipient->id)) {
                    continue; // already handled by an earlier rule
                }
                $notifiedIds->push($recipient->id);

                // Create the notification record.
                // is_read=true when send_in_app is off so it doesn't clutter the bell.
                $notification = Notification::create([
                    'user_id'    => $recipient->id,
                    'type'       => $type,
                    'severity'   => $severity,
                    'title'      => $title,
                    'message'    => $message,
                    'link'       => $link,
                    'is_read'    => ! $rule->send_in_app,
                    'created_at' => now(),
                ]);

                // Email — rule flag OR the user's personal notify_email preference
                $settings     = NotificationSetting::forUser($recipient->id);
                $shouldEmail  = $rule->send_email || $settings->notify_email;

                if ($shouldEmail) {
                    SendNotificationEmailJob::dispatch($notification, $recipient)->afterCommit();
                }
            }
        }
    }

    public function markRead(int $notificationId, int $userId): void
    {
        Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->update(['is_read' => true]);
    }

    public function markAllRead(int $userId): void
    {
        Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    public function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();
    }

    public function getForUser(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return Notification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function getLatestUnread(int $userId, int $limit = 5): \Illuminate\Database\Eloquent\Collection
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    // ─────────────────────────────────────────────────────────────
    // Notification Routing Rules
    // ─────────────────────────────────────────────────────────────

    private function applyNotificationRules(Notification $notification): void
    {
        try {
            $rules = NotificationRule::active()->forEvent($notification->type)->get();

            foreach ($rules as $rule) {
                if ($rule->recipient_type === 'role') {
                    $recipients = User::where('role', $rule->recipient_role)->get();
                } else {
                    $user = $rule->recipientUser;
                    $recipients = $user ? collect([$user]) : collect();
                }

                foreach ($recipients as $recipient) {
                    // Skip if this user is already the notification owner
                    // (they already received the standard email above)
                    if ($recipient->id === $notification->user_id) {
                        continue;
                    }

                    // Skip email if recipient already gets emails via their own
                    // notify_email preference — they will receive their own direct
                    // email when notify() is called for them (e.g. via notifyAdmins).
                    $recipientPref = NotificationSetting::forUser($recipient->id);

                    // In-app notification (always send regardless of email preference)
                    if ($rule->send_in_app) {
                        Notification::create([
                            'user_id'    => $recipient->id,
                            'type'       => $notification->type,
                            'severity'   => $notification->severity,
                            'title'      => $notification->title,
                            'message'    => $notification->message,
                            'link'       => $notification->link,
                            'is_read'    => false,
                            'created_at' => now(),
                        ]);
                    }

                    // Email notification — only if recipient does NOT already
                    // receive email via their personal notify_email setting
                    if ($rule->send_email && ! $recipientPref->notify_email) {
                        SendNotificationEmailJob::dispatch($notification, $recipient)->afterCommit();
                    }
                }
            }
        } catch (\Throwable) {
            // Don't fail the main notification if rule processing errors
        }
    }
}
