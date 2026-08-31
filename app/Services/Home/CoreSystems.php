<?php

namespace App\Services\Home;

use App\Models\Setting;

/**
 * The "Core systems" tiles on the employee home portal.
 *
 * config/home_portal.php owns the LIST — key, name, description, icon — because
 * it cannot be empty on first boot, and this page is the one every PC opens on.
 * Settings owns the ADDRESSES, so changing where a tile points is not a deploy.
 *
 * Both the portal and the settings screen read through here, so the two can
 * never disagree about which tiles exist or where they go.
 */
class CoreSystems
{
    /** Tiles whose destination is not a plain URL, so Settings offers no field. */
    private const SPECIAL = ['servicedesk'];

    /**
     * Every configured tile, with its resolved URL — including ones with no
     * URL yet, so the settings screen can offer a field for them.
     *
     * @return array<int, array{key:string, name:string, meta:?string, url:?string, special:bool}>
     */
    public function all(?Setting $settings = null): array
    {
        $overrides = ($settings ?? Setting::get())->home_portal_urls ?? [];
        $overrides = is_array($overrides) ? $overrides : [];

        $tiles = [];

        foreach (config('home_portal.core_systems', []) as $sys) {
            $key = $sys['key'] ?? null;

            if (! $key) {
                continue;
            }

            $tiles[] = [
                'key' => $key,
                'name' => $sys['name'] ?? $key,
                'meta' => $sys['meta'] ?? null,
                'url' => $this->resolveUrl($key, $sys, $overrides),
                'special' => in_array($key, self::SPECIAL, true),
            ];
        }

        return $tiles;
    }

    /**
     * Only the tiles the portal should actually render.
     *
     * A tile with no destination is a dead tile — hidden rather than shipped as
     * a link that does nothing. The service desk is the exception: it opens the
     * ticket modal, so it never has a URL.
     *
     * @return array<int, array{key:string, name:string, meta:?string, url:?string, special:bool}>
     */
    public function visible(?Setting $settings = null): array
    {
        return array_values(array_filter(
            $this->all($settings),
            fn ($tile) => $tile['key'] === 'servicedesk' || ! empty($tile['url'])
        ));
    }

    private function resolveUrl(string $key, array $sys, array $overrides): ?string
    {
        // The service desk opens a modal in-page and never navigates.
        if ($key === 'servicedesk') {
            return null;
        }

        $override = trim((string) ($overrides[$key] ?? ''));

        return $override !== '' ? $override : ($sys['url'] ?: null);
    }
}
