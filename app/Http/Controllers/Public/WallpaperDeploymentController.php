<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\WallpaperCheckin;
use App\Models\WallpaperSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * Device check-in — the PowerShell agent POSTs this after it applies, so the
     * NOC can show which wallpaper set each machine matched + when it last ran.
     * Anonymous + CSRF-exempt (devices have no token); upserted by hostname.
     */
    public function checkin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hostname' => 'required|string|max:191',
            'domain_detected' => 'nullable|string|max:191',
            'set_label' => 'nullable|string|max:150',
            'domain_match' => 'nullable|string|max:191',
            'desktop_hash' => 'nullable|string|max:64',
            'lockscreen_hash' => 'nullable|string|max:64',
            'os_version' => 'nullable|string|max:191',
        ]);

        // Resolve the matched set (by domain_match the agent reported) for the FK.
        $set = ! empty($data['domain_match'])
            ? WallpaperSet::where('domain_match', strtolower($data['domain_match']))->first()
            : null;

        $existing = WallpaperCheckin::where('hostname', $data['hostname'])->first();

        WallpaperCheckin::updateOrCreate(
            ['hostname' => $data['hostname']],
            [
                'domain_detected' => $data['domain_detected'] ?? null,
                'wallpaper_set_id' => $set?->id,
                'set_label' => $data['set_label'] ?? $set?->label,
                'desktop_hash' => $data['desktop_hash'] ?? null,
                'lockscreen_hash' => $data['lockscreen_hash'] ?? null,
                'os_version' => $data['os_version'] ?? null,
                'ip_address' => $request->ip(),
                'checkin_count' => ($existing->checkin_count ?? 0) + 1,
                'last_applied_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function script(): Response
    {
        $template = file_get_contents(resource_path('scripts/Apply-SamirWallpaper.ps1'));
        $ps1 = strtr($template, [
            '{{MANIFEST_URL}}' => route('wallpapers.manifest'),
            '{{SELF_URL}}' => route('wallpapers.script'),
            '{{CHECKIN_URL}}' => route('wallpapers.checkin'),
        ]);

        return response($ps1, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="Apply-SamirWallpaper.ps1"',
        ]);
    }
}
