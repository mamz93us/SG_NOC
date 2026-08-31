<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The announcements archive on the home portal, and the read-state endpoint the
 * slider calls as slides are seen.
 */
class HomeAnnouncementController extends Controller
{
    public function index(Request $request): View
    {
        $employee = Employee::where('email', $request->user()->email)->first();

        $announcements = Announcement::liveFor($employee)->paginate(20);

        $readIds = AnnouncementRead::where('user_id', $request->user()->id)
            ->whereIn('announcement_id', $announcements->pluck('id'))
            ->pluck('announcement_id')
            ->all();

        return view('home.announcements', [
            'announcements' => $announcements,
            'readIds' => array_flip($readIds),
            'employee' => $employee,
        ]);
    }

    /**
     * Mark announcements as read.
     *
     * The ids are validated against what this person is actually allowed to
     * see, so a hand-crafted request cannot create read rows for another
     * audience's notices — the unread badge is derived from these rows, and a
     * poisoned count would quietly hide real announcements.
     */
    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|max:50',
            'ids.*' => 'integer',
        ]);

        $employee = Employee::where('email', $request->user()->email)->first();

        $visibleIds = Announcement::liveFor($employee)
            ->whereIn('id', $validated['ids'])
            ->pluck('id');

        $now = now();
        $userId = $request->user()->id;

        foreach ($visibleIds as $id) {
            // The unique index on (announcement_id, user_id) makes this
            // idempotent — the slider re-sends ids as it loops.
            AnnouncementRead::firstOrCreate(
                ['announcement_id' => $id, 'user_id' => $userId],
                ['read_at' => $now],
            );
        }

        return response()->json(['marked' => $visibleIds->count()]);
    }
}
