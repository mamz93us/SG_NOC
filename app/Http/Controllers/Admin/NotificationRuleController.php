<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationRule;
use App\Models\User;
use Illuminate\Http\Request;

class NotificationRuleController extends Controller
{
    public function index()
    {
        $this->authorize('manage-notification-rules');

        $rules       = NotificationRule::with('recipientUser')->orderBy('event_type')->get();
        $users       = User::orderBy('name')->get(['id', 'name', 'email']);
        $eventTypes  = NotificationRule::eventTypeLabels();
        $eventGroups = NotificationRule::eventTypeGroups();

        return view('admin.notifications.rules', compact('rules', 'users', 'eventTypes', 'eventGroups'));
    }

    public function store(Request $request)
    {
        $this->authorize('manage-notification-rules');

        $validated = $request->validate([
            'event_type'        => 'required|string|max:50',
            'recipient_type'    => 'required|in:role,user',
            'recipient_role'    => 'nullable|required_if:recipient_type,role|in:super_admin,admin,viewer',
            'recipient_user_id' => 'nullable|required_if:recipient_type,user|exists:users,id',
            'send_email'        => 'boolean',
            'send_in_app'       => 'boolean',
            'is_active'         => 'boolean',
        ]);

        NotificationRule::create([
            'event_type'        => $validated['event_type'],
            'recipient_type'    => $validated['recipient_type'],
            'recipient_role'    => $validated['recipient_type'] === 'role' ? $validated['recipient_role'] : null,
            'recipient_user_id' => $validated['recipient_type'] === 'user' ? $validated['recipient_user_id'] : null,
            'send_email'        => $request->boolean('send_email', true),
            'send_in_app'       => $request->boolean('send_in_app', true),
            'is_active'         => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Notification rule created.');
    }

    public function update(Request $request, NotificationRule $notificationRule)
    {
        $this->authorize('manage-notification-rules');

        $validated = $request->validate([
            'event_type'        => 'required|string|max:50',
            'recipient_type'    => 'required|in:role,user',
            'recipient_role'    => 'nullable|required_if:recipient_type,role|in:super_admin,admin,viewer',
            'recipient_user_id' => 'nullable|required_if:recipient_type,user|exists:users,id',
            'send_email'        => 'boolean',
            'send_in_app'       => 'boolean',
            'is_active'         => 'boolean',
        ]);

        $notificationRule->update([
            'event_type'        => $validated['event_type'],
            'recipient_type'    => $validated['recipient_type'],
            'recipient_role'    => $validated['recipient_type'] === 'role' ? $validated['recipient_role'] : null,
            'recipient_user_id' => $validated['recipient_type'] === 'user' ? $validated['recipient_user_id'] : null,
            'send_email'        => $request->boolean('send_email', true),
            'send_in_app'       => $request->boolean('send_in_app', true),
            'is_active'         => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Notification rule updated.');
    }

    public function destroy(NotificationRule $notificationRule)
    {
        $this->authorize('manage-notification-rules');
        $notificationRule->delete();
        return back()->with('success', 'Notification rule deleted.');
    }
}
