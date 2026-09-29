<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The announcements archive on the home portal, the read-state endpoint the
 * slider calls as slides are seen, and the pictures Oracle's notices carry.
 */
class HomeAnnouncementController extends Controller
{
    public function index(Request $request): View
    {
        $employee = Employee::where('email', $request->user()->email)->first();

        $announcements = Announcement::liveFor($employee)->withPictures()->paginate(20);

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
     * One picture from a notice this person may see — the same audience and
     * live-window check as the pages, per request, so a guessed id or an
     * expired notice is a 404 rather than someone else's announcement.
     *
     * The URL carries the picture's hash (`?v=`), so a changed picture is a
     * new URL and a day's private caching is safe.
     */
    public function image(Request $request, Announcement $announcement, int $position): Response
    {
        $employee = Employee::where('email', $request->user()->email)->first();

        abort_unless(Announcement::liveFor($employee)->whereKey($announcement->id)->exists(), 404);

        $image = $announcement->images()->where('position', $position)->first();

        abort_unless($image !== null, 404);

        return response($image->bytes, 200, [
            'Content-Type' => $image->mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => '"'.$image->sha1.'"',
            'X-Content-Type-Options' => 'nosniff',
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
