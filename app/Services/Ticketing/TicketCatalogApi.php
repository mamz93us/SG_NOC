<?php

namespace App\Services\Ticketing;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pulls the category / sub-category tree from the ticketing API instead of the
 * hand-maintained JSON in Admin → Settings.
 *
 *   GET {base}/getCategoriesForNOC                  → categories, each with a
 *                                                     nested subCategories array
 *   GET {base}/getSubCategoriesForNOC?categoryId=N  → the flat list for one category
 *
 * The **ForNOC** suffix matters. The plain `getCategories` /
 * `getSubCategories` are a different pair guarded by a JWT bearer token; they
 * answer an X-API-Key with 401 "No authorization token provided". Only the
 * ForNOC variants take the same X-API-Key as `addTicketingRequestForNOC`.
 *
 * `{base}` is derived from the configured submit URL by dropping its last path
 * segment — the three endpoints are siblings under `.../ticketing/api`, so one
 * setting keeps pointing at test or production without a second field.
 *
 * Each sub-category carries its own `typeId` and `priorityId`. Those are the
 * ticketing system's opinion about what that kind of request is, so the Create
 * Ticket form pre-selects them rather than making the user guess.
 *
 * The result is cached: it is looked up on every load of the form and of the
 * Settings page, and the tree changes about never. A fetch failure is not
 * fatal — the caller falls back to the catalog in Settings.
 */
class TicketCatalogApi
{
    public const CACHE_PREFIX = 'noc_ticket_catalog_api:';

    /** Siblings of the submit endpoint. The ForNOC suffix is not optional — see the class docblock. */
    public const ENDPOINT_CATEGORIES = '/getCategoriesForNOC';

    public const ENDPOINT_SUBCATEGORIES = '/getSubCategoriesForNOC';

    /** Long enough that the form never waits on the API, short enough to pick up edits the same day. */
    public const TTL_SECONDS = 21600; // 6 hours

    /**
     * The cached category tree, in the shape TicketCatalog::fromArray() eats.
     * Returns null when the API is not configured or nothing is cached.
     *
     * @return array<int, array<string,mixed>>|null
     */
    public function categories(?Setting $settings = null): ?array
    {
        $base = $this->baseUrl($settings ?? Setting::get());

        if (! $base) {
            return null;
        }

        $cached = Cache::get($this->cacheKey($base));

        return is_array($cached) ? $cached : null;
    }

    /** Cached tree, fetching it once if the cache is cold. Never throws. */
    public function categoriesOrFetch(?Setting $settings = null): ?array
    {
        $settings ??= Setting::get();
        $base = $this->baseUrl($settings);

        if (! $base) {
            return null;
        }

        $cached = Cache::get($this->cacheKey($base));
        if (is_array($cached)) {
            return $cached;
        }

        // A recent failure is remembered so every page load does not re-hit a
        // host that is timing out.
        if (Cache::has($this->cacheKey($base).':failed')) {
            return null;
        }

        try {
            return $this->refresh($settings);
        } catch (\Throwable $e) {
            // A dead lookup endpoint must not take the Create Ticket form with
            // it — the hand-maintained catalog is still there to fall back to.
            Log::warning('TicketCatalogApi: category fetch failed', ['error' => $e->getMessage()]);
            Cache::put($this->cacheKey($base).':failed', $e->getMessage(), 300);

            return null;
        }
    }

    /** The error from the last failed fetch, if it was recent. */
    public function lastError(?Setting $settings = null): ?string
    {
        $base = $this->baseUrl($settings ?? Setting::get());

        return $base ? Cache::get($this->cacheKey($base).':failed') : null;
    }

    /**
     * Fetch the tree and cache it. Throws on failure — this is the path the
     * "Refresh from API" button takes, where the admin wants to see why.
     *
     * @return array<int, array<string,mixed>>
     */
    public function refresh(?Setting $settings = null): array
    {
        $settings ??= Setting::get();
        $base = $this->baseUrl($settings);

        if (! $base) {
            throw new RuntimeException('No API endpoint URL is configured, so there is nothing to fetch from.');
        }

        $categories = $this->normalizeCategories($this->get($settings, $base.self::ENDPOINT_CATEGORIES));

        if ($categories === []) {
            throw new RuntimeException('getCategoriesForNOC returned no usable categories.');
        }

        // A payload that omits the nested list entirely means this deployment
        // only serves sub-categories through the per-category endpoint. An
        // empty array is taken at face value.
        foreach ($categories as $i => $category) {
            if ($category['subcategories'] === null) {
                $categories[$i]['subcategories'] = $this->normalizeSubcategories(
                    $this->get($settings, $base.self::ENDPOINT_SUBCATEGORIES, ['categoryId' => $category['id']]),
                    $category['id'],
                );
            }
        }

        Cache::put($this->cacheKey($base), $categories, self::TTL_SECONDS);
        Cache::put($this->cacheKey($base).':fetched_at', now()->toIso8601String(), self::TTL_SECONDS);
        Cache::forget($this->cacheKey($base).':failed');

        return $categories;
    }

    /** When the cached tree was fetched, for the Settings page. */
    public function fetchedAt(?Setting $settings = null): ?string
    {
        $base = $this->baseUrl($settings ?? Setting::get());

        return $base ? Cache::get($this->cacheKey($base).':fetched_at') : null;
    }

    public function forget(?Setting $settings = null): void
    {
        $this->forgetForUrl(($settings ?? Setting::get())->noc_ticket_api_url);
    }

    /**
     * Drop the cache belonging to one submit URL — used when the URL is being
     * changed, where {@see forget()} would only clear the *new* one's key.
     */
    public function forgetForUrl(?string $submitUrl): void
    {
        $base = self::baseUrlFor($submitUrl);

        if ($base) {
            Cache::forget($this->cacheKey($base));
            Cache::forget($this->cacheKey($base).':failed');
            Cache::forget($this->cacheKey($base).':fetched_at');
        }
    }

    /**
     * `.../ticketing/api` from `.../ticketing/api/addTicketingRequestForNOC`.
     * The lookup endpoints are siblings of the submit endpoint.
     */
    public function baseUrl(Setting $settings): ?string
    {
        return self::baseUrlFor($settings->noc_ticket_api_url);
    }

    public static function baseUrlFor(?string $submitUrl): ?string
    {
        $url = trim((string) $submitUrl);

        if ($url === '') {
            return null;
        }

        $trimmed = rtrim($url, '/');
        $lastSlash = strrpos($trimmed, '/');

        // 8 keeps the "https://" double slash from being mistaken for the path.
        if ($lastSlash === false || $lastSlash < 8) {
            return null;
        }

        return substr($trimmed, 0, $lastSlash);
    }

    private function cacheKey(string $base): string
    {
        // Keyed on host+path so flipping between test and production never
        // serves one environment's ids under the other's.
        return self::CACHE_PREFIX.md5($base);
    }

    private function get(Setting $settings, string $url, array $query = []): array
    {
        $key = $settings->nocTicketApiKey();

        if (! $key) {
            throw new RuntimeException('No X-API-Key is configured.');
        }

        $response = Http::withHeaders([
            'X-API-Key' => $key,
            'Accept' => 'application/json',
        ])->timeout(20)->get($url, $query);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status().' from '.$url.': '.mb_substr($response->body(), 0, 300));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Expected a JSON array from '.$url.', got: '.mb_substr($response->body(), 0, 200));
        }

        // Tolerate both a bare array and one wrapped in {"data": [...]}.
        if (isset($body['data']) && is_array($body['data'])) {
            $body = $body['data'];
        }

        return $body;
    }

    /**
     * API shape → catalog shape. `subcategories` is deliberately left null when
     * the payload had no nested key at all, so refresh() knows to go and ask.
     */
    private function normalizeCategories(array $raw): array
    {
        $out = [];

        foreach ($raw as $cat) {
            if (! is_array($cat) || ! isset($cat['categoryId'])) {
                continue;
            }

            $id = (int) $cat['categoryId'];

            $out[] = [
                'id' => $id,
                'name' => (string) ($cat['categoryName'] ?? $id),
                'name_ar' => isset($cat['categoryNameAr']) ? (string) $cat['categoryNameAr'] : null,
                'department_id' => isset($cat['departmentId']) ? (int) $cat['departmentId'] : null,
                'subcategories' => array_key_exists('subCategories', $cat) && is_array($cat['subCategories'])
                    ? $this->normalizeSubcategories($cat['subCategories'], $id)
                    : null,
            ];
        }

        return $out;
    }

    private function normalizeSubcategories(array $raw, int $categoryId): array
    {
        $out = [];

        foreach ($raw as $sub) {
            if (! is_array($sub) || ! isset($sub['subCategoryId'])) {
                continue;
            }

            // A payload that carries its own categoryId is trusted over the
            // category it arrived under.
            if (isset($sub['categoryId']) && (int) $sub['categoryId'] !== $categoryId) {
                continue;
            }

            $out[] = [
                'id' => (int) $sub['subCategoryId'],
                'name' => (string) ($sub['subCategoryName'] ?? $sub['subCategoryId']),
                'name_ar' => isset($sub['subCategoryNameAr']) ? (string) $sub['subCategoryNameAr'] : null,
                'type_id' => isset($sub['typeId']) ? (int) $sub['typeId'] : null,
                'priority_id' => isset($sub['priorityId']) ? (int) $sub['priorityId'] : null,
            ];
        }

        return $out;
    }
}
