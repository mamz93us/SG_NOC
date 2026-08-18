<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\Notifications\WhatsAppService;
use Illuminate\Http\Request;

class NotificationRuleController extends Controller
{
    public function index()
    {
        $this->authorize('manage-notification-rules');

        $rules = NotificationRule::with(['recipientUser', 'recipients'])->orderBy('event_type')->get();
        $users = User::orderBy('name')->get(['id', 'name', 'email', 'whatsapp_number']);
        $eventTypes = NotificationRule::eventTypeLabels();
        $eventGroups = NotificationRule::eventTypeGroups();
        $whatsappReady = app(WhatsAppService::class)->isConfigured();

        return view('admin.notifications.rules', compact(
            'rules', 'users', 'eventTypes', 'eventGroups', 'whatsappReady'
        ));
    }

    public function store(Request $request)
    {
        $this->authorize('manage-notification-rules');

        $validated = $this->validated($request);

        $rule = NotificationRule::create($this->attributes($request, $validated));

        $this->syncRecipients($rule, $request, $validated);

        return back()->with('success', 'Notification rule created.');
    }

    public function update(Request $request, NotificationRule $notificationRule)
    {
        $this->authorize('manage-notification-rules');

        $validated = $this->validated($request);

        $notificationRule->update($this->attributes($request, $validated));

        $this->syncRecipients($notificationRule, $request, $validated);

        return back()->with('success', 'Notification rule updated.');
    }

    public function destroy(NotificationRule $notificationRule)
    {
        $this->authorize('manage-notification-rules');
        $notificationRule->delete();

        return back()->with('success', 'Notification rule deleted.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        $rules = [
            'event_type' => 'required|string|max:50',
            'recipient_type' => 'required|in:role,user',
            'recipient_role' => 'nullable|required_if:recipient_type,role|in:super_admin,admin,hr,viewer',
            // A rule can now name several people. `recipient_user_ids` is the
            // current shape; `recipient_user_id` is still accepted so an older
            // form post (or a bookmarked request) keeps working.
            'recipient_user_ids' => 'nullable|array',
            'recipient_user_ids.*' => 'integer|exists:users,id',
            'recipient_user_id' => 'nullable|exists:users,id',
            'send_email' => 'boolean',
            'send_in_app' => 'boolean',
            'send_whatsapp' => 'boolean',
            'whatsapp_numbers' => 'nullable|string|max:1000',
            'cooldown_minutes' => 'nullable|integer|min:0|max:43200',
            'is_active' => 'boolean',
        ];

        // Merged after, not before: the presence check replaces the plain
        // recipient_type rule, and `+` would have kept the plain one.
        return $request->validate(array_merge($rules, $this->recipientPresenceRule($request)));
    }

    /**
     * Require at least one user when the rule targets users.
     *
     * Expressed as a closure rather than `required_if` because the recipient
     * may arrive under either key, and a rule that targets nobody would sit on
     * the page looking configured while paging no one.
     */
    private function recipientPresenceRule(Request $request): array
    {
        if ($request->input('recipient_type') !== 'user') {
            return [];
        }

        return [
            'recipient_type' => [
                'required', 'in:role,user',
                function ($attribute, $value, $fail) use ($request) {
                    $ids = array_filter((array) $request->input('recipient_user_ids', []));
                    if ($ids === [] && blank($request->input('recipient_user_id'))) {
                        $fail('Select at least one recipient user.');
                    }
                },
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    private function attributes(Request $request, array $validated): array
    {
        $isRole = $validated['recipient_type'] === 'role';

        return [
            'event_type' => $validated['event_type'],
            'recipient_type' => $validated['recipient_type'],
            'recipient_role' => $isRole ? $validated['recipient_role'] : null,
            // Kept in step with the pivot so anything still reading the column
            // resolves to a real person rather than a stale one.
            'recipient_user_id' => $isRole ? null : $this->userIds($request)[0] ?? null,
            'send_email' => $request->boolean('send_email', true),
            'send_in_app' => $request->boolean('send_in_app', true),
            'send_whatsapp' => $request->boolean('send_whatsapp'),
            'whatsapp_numbers' => $request->input('whatsapp_numbers') ?: null,
            'cooldown_minutes' => (int) ($validated['cooldown_minutes'] ?? 0),
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function syncRecipients(NotificationRule $rule, Request $request, array $validated): void
    {
        if ($validated['recipient_type'] === 'role') {
            $rule->recipients()->sync([]);

            return;
        }

        $rule->recipients()->sync($this->userIds($request));
    }

    /**
     * Selected user ids, from either form shape, de-duplicated and in order.
     *
     * @return list<int>
     */
    private function userIds(Request $request): array
    {
        $ids = array_map('intval', array_filter((array) $request->input('recipient_user_ids', [])));

        if ($ids === [] && $request->filled('recipient_user_id')) {
            $ids = [(int) $request->input('recipient_user_id')];
        }

        return array_values(array_unique($ids));
    }
}
