<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLink;
use App\Models\UserQuickLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

class UserQuickLinkController extends Controller
{
    /**
     * Pin a quick link from one of two system sources:
     *   - admin_link: an entry in the admin_links table
     *   - tool:       a key from config/admin_tools.php
     *
     * Free-text URLs are intentionally NOT supported — quick links must come
     * from a curated source so labels/icons stay consistent and routes are
     * permission-checked.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source'    => 'required|in:admin_link,tool',
            'source_id' => 'required|string|max:64',
        ]);

        $userId = Auth::id();

        if ($data['source'] === 'admin_link') {
            $link = AdminLink::active()->find($data['source_id']);
            if (! $link) {
                return back()->with('error', 'Selected admin link not found.');
            }
            $label = $link->name;
            $url   = $link->url;
            $icon  = $this->normalizeIcon($link->icon, 'bi-link-45deg');
        } else {
            $tool = collect(config('admin_tools', []))->firstWhere('key', $data['source_id']);
            if (! $tool || ! Route::has($tool['route'])) {
                return back()->with('error', 'Selected admin tool is not available.');
            }
            $perm = $tool['permission'] ?? null;
            if ($perm && ! Auth::user()->can($perm)) {
                abort(403);
            }
            $label = $tool['label'];
            $url   = route($tool['route']);
            $icon  = $this->normalizeIcon($tool['icon'] ?? null, 'bi-tools');
        }

        // Prevent duplicates — same user pinning the same URL again
        if (UserQuickLink::where('user_id', $userId)->where('url', $url)->exists()) {
            return back()->with('info', '“' . $label . '” is already pinned.');
        }

        $next = (int) (UserQuickLink::where('user_id', $userId)->max('sort_order') ?? 0) + 1;

        UserQuickLink::create([
            'user_id'    => $userId,
            'label'      => $label,
            'url'        => $url,
            'icon'       => $icon,
            'sort_order' => $next,
        ]);

        return back()->with('success', '“' . $label . '” pinned to your quick links.');
    }

    public function destroy(UserQuickLink $quickLink): RedirectResponse
    {
        abort_unless($quickLink->user_id === Auth::id(), 403);
        $quickLink->delete();

        return back()->with('success', 'Quick link removed.');
    }

    /** Bootstrap-Icons names sometimes lack the "bi-" prefix in the DB. */
    private function normalizeIcon(?string $icon, string $fallback): string
    {
        $icon = trim((string) $icon);
        if ($icon === '') return $fallback;
        return str_starts_with($icon, 'bi-') ? $icon : 'bi-' . $icon;
    }
}
