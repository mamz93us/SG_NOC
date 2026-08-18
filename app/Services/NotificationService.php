<?php

namespace App\Services;

use App\Jobs\SendNotificationEmailJob;
use App\Jobs\SendNotificationWhatsAppJob;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Services\Notifications\WhatsAppService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class NotificationService
{
    /**
     * Floor on the unconfigured broadcast path: how long the same event type
     * must stay quiet for a given user before it is broadcast to them again.
     *
     * Rules have their own per-rule `cooldown_minutes`. This is the backstop for
     * when there are NO rules — previously that path had no rate limiting at
     * all, so a flapping monitor could put 60+ identical emails an hour into
     * every admin's inbox.
     */
    private const BROADCAST_COOLDOWN_MINUTES = 60;

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
     * Notify the audience configured for this event type, falling back to every
     * admin when no rule covers it.
     *
     * This used to broadcast to all admins unconditionally, which meant the
     * callers that use it — NocAlertEngine, SlaMonitorService,
     * LicenseMonitorService, WorkflowEngine — bypassed the Notification Rules
     * page entirely: configuring a rule there had no effect on them. It now
     * delegates to notifyViaRules() so one page governs every alert, and the
     * broadcast is what happens only when nothing is configured.
     */
    public function notifyAdmins(
        string  $type,
        string  $title,
        string  $message,
        ?string $link = null,
        string  $severity = 'info'
    ): void {
        $this->notifyViaRules($type, $title, $message, $link, $severity);
    }

    /**
     * The unconfigured fallback: every super_admin / admin, rate-limited per
     * user per event type so a flapping source cannot storm them.
     */
    private function broadcastToAdmins(
        string  $type,
        string  $title,
        string  $message,
        ?string $link = null,
        string  $severity = 'info'
    ): void {
        $users = User::whereIn('role', ['super_admin', 'admin'])->get();

        foreach ($users as $user) {
            $recentExists = Notification::where('user_id', $user->id)
                ->where('type', $type)
                ->where('created_at', '>=', now()->subMinutes(self::BROADCAST_COOLDOWN_MINUTES))
                ->exists();

            if ($recentExists) {
                continue;
            }

            $this->notify($user->id, $type, $title, $message, $link, $severity, skipRules: true);
        }
    }

    /**
     * Send a notification only to recipients defined in active rules for the given
     * event type, honouring per-rule send_in_app / send_email flags.
     *
     * Falls back to notifyAdmins() when no rules are configured for the event type,
     * so alerts are never silently dropped.
     *
     * @param  bool  $skipCooldown  Bypass per-rule cooldown_minutes; set true for
     *                              manual Resend actions where the admin explicitly
     *                              wants the alert delivered again.
     */
    public function notifyViaRules(
        string  $type,
        string  $title,
        string  $message,
        ?string $link = null,
        string  $severity = 'info',
        bool    $skipCooldown = false
    ): void {
        $rules = NotificationRule::active()->forEvent($type)->with('recipients')->get();

        if ($rules->isEmpty()) {
            // No rules configured for this event — fall back to all admins
            $this->broadcastToAdmins($type, $title, $message, $link, $severity);
            return;
        }

        $notifiedIds = collect(); // deduplicate: first matching rule wins per user
        $whatsappSent = collect(); // deduplicate by number, across all rules

        foreach ($rules as $rule) {
            $recipients = $rule->resolveRecipients();

            foreach ($recipients as $recipient) {
                if ($notifiedIds->contains($recipient->id)) {
                    continue; // already handled by an earlier rule
                }

                // Per-rule cooldown — skip if this user already got the same
                // event type within the configured window. cooldown_minutes=0
                // means "no auto-repeat", which combined with upstream idempotency
                // (NocAlertEngine, PrinterSupplyMonitorService) gives one-shot
                // delivery by default. Manual Resend passes $skipCooldown=true.
                if (! $skipCooldown && $rule->cooldown_minutes > 0) {
                    $recentExists = Notification::where('user_id', $recipient->id)
                        ->where('type', $type)
                        ->where('created_at', '>=', now()->subMinutes($rule->cooldown_minutes))
                        ->exists();
                    if ($recentExists) {
                        continue;
                    }
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

                // Email — the rule decides WHO gets mailed; the user's personal
                // notify_email preference can only veto it.
                //
                // This was `||`, which made the rule's "send email" checkbox
                // inert: notify_email defaults to true for every user, so a rule
                // with email switched off still emailed everyone it matched.
                $settings     = NotificationSetting::forUser($recipient->id);
                $shouldEmail  = $rule->send_email && $settings->notify_email;

                if ($shouldEmail) {
                    SendNotificationEmailJob::dispatch($notification, $recipient)->afterCommit();
                }

                // WhatsApp — same semantics as email: the rule decides who is
                // paged, the recipient's own preference can veto.
                if ($rule->send_whatsapp && $settings->notify_whatsapp) {
                    $this->queueWhatsApp(
                        $recipient->whatsapp_number,
                        $recipient->name,
                        $type, $title, $message, $link, $severity,
                        $notification->id,
                        $whatsappSent
                    );
                }
            }

            // Numbers typed onto the rule itself: on-call phones and people
            // with no NOC login. They are a separate audience, not a duplicate
            // of the users above, so they send whether or not any user matched.
            if ($rule->send_whatsapp) {
                foreach ($rule->whatsappNumberList() as $number) {
                    $this->queueWhatsApp(
                        $number, null,
                        $type, $title, $message, $link, $severity,
                        null,
                        $whatsappSent
                    );
                }
            }
        }
    }

    /**
     * Queue one WhatsApp send, skipping numbers already messaged for this
     * event and anything that does not normalise to a dialable number.
     *
     * The alert text is passed as scalars rather than a Notification model:
     * the rule's extra numbers belong to no user, so there is no notification
     * row to hang them off, and `notifications.user_id` is not nullable.
     */
    private function queueWhatsApp(
        ?string    $rawNumber,
        ?string    $name,
        string     $type,
        string     $title,
        string     $message,
        ?string    $link,
        string     $severity,
        ?int       $notificationId,
        Collection $alreadySent
    ): void {
        $whatsapp = app(WhatsAppService::class);

        if (! $whatsapp->isConfigured()) {
            return;
        }

        $number = $whatsapp->withCountryCode($rawNumber);

        if (! $number || $alreadySent->contains($number)) {
            return;
        }

        $alreadySent->push($number);

        SendNotificationWhatsAppJob::dispatch(
            $number,
            [
                'type'     => $type,
                'title'    => $title,
                'message'  => $message,
                'link'     => $link,
                'severity' => $severity,
            ],
            $notificationId,
            $name
        )->afterCommit();
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
            $rules = NotificationRule::active()->forEvent($notification->type)->with('recipients')->get();

            $whatsappSent = collect(); // deduplicate by number, across all rules

            foreach ($rules as $rule) {
                $recipients = $rule->resolveRecipients();

                foreach ($recipients as $recipient) {
                    // Skip if this user is already the notification owner
                    // (they already received the standard email above)
                    if ($recipient->id === $notification->user_id) {
                        continue;
                    }

                    // Per-rule cooldown — same logic as notifyViaRules().
                    if ($rule->cooldown_minutes > 0) {
                        $recentExists = Notification::where('user_id', $recipient->id)
                            ->where('type', $notification->type)
                            ->where('id', '!=', $notification->id)
                            ->where('created_at', '>=', now()->subMinutes($rule->cooldown_minutes))
                            ->exists();
                        if ($recentExists) {
                            continue;
                        }
                    }

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

                    // Email notification. Same semantics as notifyViaRules(): the
                    // rule decides, the recipient's preference can veto.
                    //
                    // This was `&& ! $recipientPref->notify_email`, on the theory
                    // that the recipient would already be getting their own copy.
                    // They would not — the notification's owner is skipped above,
                    // so this recipient is always someone else, and their own
                    // preference never produced a mail for this notification.
                    // With notify_email defaulting to true, the condition was
                    // false for everyone and rule-routed email on this path never
                    // sent at all.
                    if ($rule->send_email && $recipientPref->notify_email) {
                        SendNotificationEmailJob::dispatch($notification, $recipient)->afterCommit();
                    }

                    // WhatsApp — rule decides, recipient preference vetoes.
                    if ($rule->send_whatsapp && $recipientPref->notify_whatsapp) {
                        $this->queueWhatsApp(
                            $recipient->whatsapp_number,
                            $recipient->name,
                            $notification->type,
                            $notification->title,
                            $notification->message,
                            $notification->link,
                            $notification->severity,
                            $notification->id,
                            $whatsappSent
                        );
                    }
                }

                // The rule's own numbers — see notifyViaRules().
                if ($rule->send_whatsapp) {
                    foreach ($rule->whatsappNumberList() as $number) {
                        $this->queueWhatsApp(
                            $number, null,
                            $notification->type,
                            $notification->title,
                            $notification->message,
                            $notification->link,
                            $notification->severity,
                            $notification->id,
                            $whatsappSent
                        );
                    }
                }
            }
        } catch (\Throwable) {
            // Don't fail the main notification if rule processing errors
        }
    }
}
