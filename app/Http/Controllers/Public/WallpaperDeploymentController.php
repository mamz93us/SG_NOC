<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\WallpaperSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Public, unauthenticated endpoints consumed by Intune-managed devices.
 *
 *  - GET /api/wallpapers/manifest   → JSON: per-domain image URLs + sha256 hashes
 *  - GET /api/wallpapers/script.ps1 → the PowerShell agent with the manifest URL baked in
 *
 * No auth/CSRF on purpose: devices fetch these as anonymous HTTP clients. Nothing
 * sensitive is exposed — only public wallpaper image URLs that are already served
 * by the web server at /storage/…. Writes still require manage-wallpapers (admin).
 */
class WallpaperDeploymentController extends Controller
{
    public function manifest(): JsonResponse
    {
        $sets = WallpaperSet::where('enabled', true)
            ->where(fn ($q) => $q->whereNotNull('desktop_path')->orWhereNotNull('lockscreen_path'))
            ->get()
            ->map(fn (WallpaperSet $s) => [
                'label' => $s->label,
                'domain_match' => $s->domain_match,
                'is_default' => $s->is_default,
                'desktop_url' => $s->desktopUrl(),
                'desktop_hash' => $s->desktop_hash,
                'lockscreen_url' => $s->lockscreenUrl(),
                'lockscreen_hash' => $s->lockscreen_hash,
            ])
            ->values();

        return response()->json([
            'schema' => 1,
            'generated_at' => now()->toIso8601String(),
            'sets' => $sets,
        ]);
    }

    public function script(): Response
    {
        $template = file_get_contents(resource_path('scripts/Apply-SamirWallpaper.ps1'));
        $ps1 = strtr($template, [
            '{{MANIFEST_URL}}' => route('wallpapers.manifest'),
            '{{SELF_URL}}' => route('wallpapers.script'),
        ]);

        return response($ps1, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="Apply-SamirWallpaper.ps1"',
        ]);
    }
}
