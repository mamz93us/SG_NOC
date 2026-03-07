<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationSetting;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $service) {}

    public function index()
    {
        $notifications = $this->service->getForUser(Auth::id());
        $this->service->markAllRead(Auth::id()); // Mark all read on view
        return view('admin.notifications.index', compact('notifications'));
    }

    public function markRead(int $id)
    {
        $this->service->markRead($id, Auth::id());
        return back();
    }

    public function markAllRead()
    {
        $this->service->markAllRead(Auth::id());
        return back()->with('success', 'All notifications marked as read.');
    }

    public function unreadCount()
    {
        $latest = $this->service->getLatestUnread(Auth::id(), 5);

        return response()->json([
            'count' => $this->service->getUnreadCount(Auth::id()),
            'items' => $latest->map(fn ($n) => [
                'id'         => $n->id,
                'title'      => $n->title,
                'message'    => $n->message,
                'severity'   => $n->severity,
                'link'       => $n->link,
                'created_at' => $n->created_at->diffForHumans(),
            ])->values(),
        ]);
    }

    public function settings()
    {
        $preferences = NotificationSetting::forUser(Auth::id());
        return view('admin.notifications.settings', compact('preferences'));
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'notify_email'  => 'boolean',
            'notify_in_app' => 'boolean',
        ]);

        NotificationSetting::updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'notify_email'  => $request->boolean('notify_email'),
                'notify_in_app' => $request->boolean('notify_in_app'),
            ]
        );

        return back()->with('success', 'Notification preferences saved.');
    }
}
