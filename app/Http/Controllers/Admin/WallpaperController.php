<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WallpaperCheckin;
use App\Models\WallpaperSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Wallpaper manager. Each row is a per-domain set (desktop + lock screen). Images
 * are stored on the `public` disk so they have a stable, unauthenticated URL; the
 * per-device PowerShell script reads the public manifest, compares sha256 hashes
 * and re-applies only what changed. So replacing an image here auto-propagates to
 * every device on its next daily run.
 *
 * @see \App\Http\Controllers\Public\WallpaperDeploymentController for the public
 *      manifest + script endpoints.
 */
class WallpaperController extends Controller
{
    /** Max wallpaper upload size in KB (20 MB — plenty for a 4K JPEG/PNG). */
    private const MAX_IMAGE_KB = 20_480;

    public function index()
    {
        $sets = WallpaperSet::with('updater')->orderByDesc('is_default')->orderBy('label')->get();

        $checkins = WallpaperCheckin::orderByDesc('last_applied_at')->limit(500)->get();

        return view('admin.wallpapers.index', [
            'sets' => $sets,
            'checkins' => $checkins,
            'manifestUrl' => route('wallpapers.manifest'),
            'scriptUrl' => route('wallpapers.script'),
        ]);
    }

    /** Add a new domain set (images optional — can be uploaded afterwards). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:150',
            'domain_match' => 'required|string|max:191|unique:wallpaper_sets,domain_match',
            'is_default' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
        ]);

        $set = WallpaperSet::create([
            'label' => $data['label'],
            'domain_match' => strtolower(trim($data['domain_match'])),
            'is_default' => $request->boolean('is_default'),
            'enabled' => $request->boolean('enabled', true),
            'updated_by' => Auth::id(),
        ]);

        $this->enforceSingleDefault($set);

        return redirect()->route('admin.wallpapers.index')
            ->with('success', "Added domain “{$set->label}” ({$set->domain_match}). Now upload its wallpapers.");
    }

    /** Edit the domain string / label / flags of an existing set. */
    public function update(Request $request, WallpaperSet $wallpaper): RedirectResponse
    {
        $data = $request->validate([
            'label' => 'required|string|max:150',
            'domain_match' => 'required|string|max:191|unique:wallpaper_sets,domain_match,'.$wallpaper->id,
            'is_default' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
        ]);

        $wallpaper->update([
            'label' => $data['label'],
            'domain_match' => strtolower(trim($data['domain_match'])),
            'is_default' => $request->boolean('is_default'),
            'enabled' => $request->boolean('enabled'),
            'updated_by' => Auth::id(),
        ]);

        $this->enforceSingleDefault($wallpaper);

        return back()->with('success', "Updated “{$wallpaper->label}”.");
    }

    /** Upload (or replace) the desktop or lock-screen image for a set. */
    public function uploadImage(Request $request, WallpaperSet $wallpaper): RedirectResponse
    {
        $data = $request->validate([
            'kind' => 'required|in:desktop,lockscreen',
            'image' => 'required|image|mimes:jpeg,jpg,png,bmp|max:'.self::MAX_IMAGE_KB,
        ]);

        $kind = $data['kind'];
        $file = $request->file('image');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        // Remove the previous image (any extension) so we never leave a stale file behind.
        $oldPath = $wallpaper->{$kind.'_path'};
        if ($oldPath) {
            Storage::disk(WallpaperSet::DISK)->delete($oldPath);
        }

        $path = "wallpapers/{$wallpaper->id}/{$kind}.{$ext}";
        Storage::disk(WallpaperSet::DISK)->putFileAs(
            "wallpapers/{$wallpaper->id}",
            $file,
            "{$kind}.{$ext}"
        );

        $wallpaper->update([
            "{$kind}_path" => $path,
            "{$kind}_hash" => hash_file('sha256', $file->getRealPath()),
            'updated_by' => Auth::id(),
        ]);

        $label = $kind === 'desktop' ? 'Desktop' : 'Lock-screen';

        return back()->with('success', "{$label} wallpaper updated for “{$wallpaper->label}”. Devices will pick it up on their next daily run.");
    }

    /** Remove one image without deleting the whole domain set. */
    public function deleteImage(Request $request, WallpaperSet $wallpaper): RedirectResponse
    {
        $kind = $request->validate(['kind' => 'required|in:desktop,lockscreen'])['kind'];

        if ($path = $wallpaper->{$kind.'_path'}) {
            Storage::disk(WallpaperSet::DISK)->delete($path);
        }

        $wallpaper->update([
            "{$kind}_path" => null,
            "{$kind}_hash" => null,
            'updated_by' => Auth::id(),
        ]);

        return back()->with('success', 'Image removed.');
    }

    public function destroy(WallpaperSet $wallpaper): RedirectResponse
    {
        Storage::disk(WallpaperSet::DISK)->deleteDirectory("wallpapers/{$wallpaper->id}");
        $label = $wallpaper->label;
        $wallpaper->delete();

        return redirect()->route('admin.wallpapers.index')->with('success', "Deleted “{$label}”.");
    }

    /** Only one set may be the default fallback. */
    private function enforceSingleDefault(WallpaperSet $set): void
    {
        if ($set->is_default) {
            WallpaperSet::where('id', '!=', $set->id)->where('is_default', true)->update(['is_default' => false]);
        }
    }
}
