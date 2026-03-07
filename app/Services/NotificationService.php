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
    public function notify(
        int     $userId,
        string  $type,
        string  $title,
        string  $message,
        ?string $link = null,
        string  $severity = 'info'
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

        // 1. Per-user email preference (existing behaviour)
        $settings = NotificationSetting::forUser($userId);
        if ($settings->notify_email) {
            SendNotificationEmailJob::dispatch($notification)->afterCommit();
        }

        // 2. Notification routing rules
        $this->applyNotificationRules($notification);

        return $notification;
    }

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
            $this->notify($user->id, $type, $title, $message, $link, $severity);
        }
    }

    public function notifyAdmins(
        string  $type,
        string  $title,
        string  $message,
        ?string $link = null,
        string  $severity = 'info'
    ): void {
        $users = User::whereIn('role', ['super_admin', 'admin'])->get();
        foreach ($users as $user) {
            $this->notify($user->id, $type, $title, $message, $link, $severity);
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
                    // (they already got the standard email above)
                    if ($recipient->id === $notification->user_id) {
                        continue;
                    }

                    // In-app notification
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

                    // Email notification
                    if ($rule->send_email) {
                        SendNotificationEmailJob::dispatch($notification, $recipient)->afterCommit();
                    }
                }
            }
        } catch (\Throwable) {
            // Don't fail the main notification if rule processing errors
        }
    }
}
