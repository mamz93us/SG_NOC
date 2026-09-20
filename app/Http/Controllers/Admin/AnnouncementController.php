<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Department;
use App\Models\OraclePortal\PortalSetting;
use App\Services\OraclePortal\AnnouncementSync;
use App\Support\AnnouncementCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            'portalReady' => $this->portalReady(),
        ]);
    }

    /** Show the Pull button only when Oracle is actually reachable and switched on. */
    private function portalReady(): bool
    {
        $portal = PortalSetting::get();

        return $portal->isConfigured() && $portal->sync_announcements;
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
        $announcement->update($this->validated($request, $announcement));

        $this->audit('announcement_updated', $announcement);
        $this->flushCache();

        return redirect()
            ->route('admin.announcements.index')
            ->with('success', 'Announcement updated.');
    }

    /**
     * Pull Oracle's announcements now, rather than waiting for the hourly run.
     *
     * 13 KB and well under a second, so it runs in the request. Edits made
     * here are kept — the sync only refreshes a field it still owns.
     */
    public function pull(AnnouncementSync $sync): RedirectResponse
    {
        try {
            $counts = $sync->sync(dryRun: false, userId: Auth::id());
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.announcements.index')
                ->with('error', 'Could not pull from Oracle: '.$e->getMessage());
        }

        $message = sprintf(
            'Pulled %d announcements from Oracle: %d new, %d changed, %d unchanged, %d no longer listed.',
            $counts['rows'], $counts['created'], $counts['updated'], $counts['unchanged'], $counts['removed'],
        );

        if ($counts['kept'] > 0) {
            $message .= " {$counts['kept']} with edits made here were left alone.";
        }

        return redirect()->route('admin.announcements.index')->with('success', $message);
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

    /**
     * @param  Announcement|null  $announcement  the row being edited, when there is one
     */
    private function validated(Request $request, ?Announcement $announcement = null): array
    {
        // Oracle sends announcements with no text at all — its DESCRIPTION
        // column is empty for every one of them, and the picture that carries
        // the message cannot be fetched. Requiring a body on those would stop
        // an admin fixing a title on the very rows most likely to need it.
        // A notice somebody writes here still needs one.
        $bodyRule = $announcement?->isFromOracle()
            ? 'nullable|string|max:20000'
            : 'required|string|max:20000';

        $data = $request->validate([
            'title' => 'required|string|max:200',
            'title_ar' => 'nullable|string|max:200',
            'body' => $bodyRule,
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

        // The column is NOT NULL, so a synced row saved with no body stores an
        // empty string — which is also exactly what the sync writes, so the
        // body goes back to being Oracle's the day Oracle has one.
        $data['body'] = (string) ($data['body'] ?? '');

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
        // Shared with the Oracle sync, which writes the same rows from the
        // scheduler. Note the enumeration also covers department-only keys
        // when there are no branches, which the inline version here missed.
        AnnouncementCache::flush();
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
