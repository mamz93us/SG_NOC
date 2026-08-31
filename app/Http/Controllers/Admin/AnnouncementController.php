<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Announcements.
 *
 * Authoring for what the whole company reads on the home portal every morning,
 * so every write is audited and publishing is an explicit act rather than a
 * side effect of saving.
 */
class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::with(['branch', 'department'])
                ->orderByDesc('pinned')
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->paginate(25),
        ]);
    }

    public function create(): View
    {
        return view('admin.announcements.form', [
            'announcement' => new Announcement(['severity' => 'info', 'audience' => 'all']),
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.announcements.form', [
            'announcement' => $announcement,
            'branches' => Branch::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $data['created_by'] = Auth::id();
        $data['created_by_name'] = Auth::user()?->name;

        $announcement = Announcement::create($data);

        $this->audit('announcement_created', $announcement);
        $this->flushCache();

        return redirect()
            ->route('admin.announcements.index')
            ->with('success', 'Announcement created.');
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($this->validated($request));

        $this->audit('announcement_updated', $announcement);
        $this->flushCache();

        return redirect()
            ->route('admin.announcements.index')
            ->with('success', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $this->audit('announcement_deleted', $announcement);

        $announcement->delete();
        $this->flushCache();

        return redirect()
            ->route('admin.announcements.index')
            ->with('success', 'Announcement deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'title_ar' => 'nullable|string|max:200',
            'body' => 'required|string|max:20000',
            'body_ar' => 'nullable|string|max:20000',
            'link_url' => 'nullable|url|max:500',
            'link_label' => 'nullable|string|max:80',
            'severity' => ['required', Rule::in(Announcement::SEVERITIES)],
            'audience' => ['required', Rule::in(Announcement::AUDIENCES)],
            'audience_branch_id' => 'nullable|integer|exists:branches,id',
            'audience_department_id' => 'nullable|integer|exists:departments,id',
            'published_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:published_at',
        ], [
            'expires_at.after' => 'The expiry must be after the publish date.',
        ]);

        $data['pinned'] = $request->boolean('pinned');
        $data['is_published'] = $request->boolean('is_published');

        // Publishing with no date means "now" — otherwise the `live` scope,
        // which treats a null published_at as always-live, would show it but the
        // slider would have nothing to date it with.
        if ($data['is_published'] && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        // Clear the audience id that does not apply, so switching from "branch"
        // to "all" cannot leave a stale filter behind.
        if ($data['audience'] !== 'branch') {
            $data['audience_branch_id'] = null;
        }
        if ($data['audience'] !== 'department') {
            $data['audience_department_id'] = null;
        }

        return $data;
    }

    /**
     * The home portal caches announcements per audience for 5 minutes. Without
     * this, a correction to an urgent notice would keep showing the old text —
     * which is exactly when people are least willing to wait.
     */
    private function flushCache(): void
    {
        try {
            Cache::forget('home.announcements.nb.nd');

            foreach (Branch::pluck('id') as $branchId) {
                Cache::forget("home.announcements.{$branchId}.nd");

                foreach (Department::pluck('id') as $deptId) {
                    Cache::forget("home.announcements.{$branchId}.{$deptId}");
                    Cache::forget("home.announcements.nb.{$deptId}");
                }
            }
        } catch (\Throwable) {
            // A cache store hiccup must not fail the save; entries expire in
            // five minutes regardless.
        }
    }

    private function audit(string $action, Announcement $announcement): void
    {
        try {
            ActivityLog::create([
                'model_type' => 'Announcement',
                'model_id' => $announcement->id,
                'action' => $action,
                'changes' => [
                    'title' => $announcement->title,
                    'severity' => $announcement->severity,
                    'audience' => $announcement->audience,
                    'is_published' => (bool) $announcement->is_published,
                ],
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable) {
            // Never let audit logging block publishing.
        }
    }
}
