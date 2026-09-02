<?php

namespace App\Services\Ticketing;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The bits every call to the Samir ticketing API needs: where it lives, the
 * key, and turning a response into an array or an exception.
 *
 * There is one URL in Settings — the **submit** endpoint
 * (`addTicketingRequestForNOC`) — and every other endpoint is its sibling under
 * `.../ticketing/api`, so the base is derived by dropping the last path
 * segment. That keeps a test↔production switch to a single field.
 *
 * Auth is `X-API-Key`, the same key the submit uses. Note that only the
 * **ForNOC** endpoint variants accept it; the plain `getCategories` /
 * `getSubCategories` are a separate JWT-guarded pair that answers the key with
 * `401 No authorization token provided`.
 */
class TicketingApiClient
{
    /**
     * `.../ticketing/api` from `.../ticketing/api/addTicketingRequestForNOC`.
     * Null when nothing is configured.
     */
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

    public function baseUrl(?Setting $settings = null): ?string
    {
        return self::baseUrlFor(($settings ?? Setting::get())->noc_ticket_api_url);
    }

    /**
     * POST a multipart form to one endpoint, relative to the base.
     *
     * The write endpoints on this API are all multipart with a JSON `data`
     * part rather than a JSON body — see `addTicketingRequestForNOC` and
     * `addTicketingRequestDetailMobileForNOC`.
     *
     * @param  array<string,scalar|null>  $fields  the non-file parts
     *
     * @throws RuntimeException on any non-2xx or a body that is not JSON
     */
    public function postMultipart(
        string $endpoint,
        array $fields,
        ?UploadedFile $file = null,
        ?Setting $settings = null,
    ): array {
        $settings ??= Setting::get();
        $base = $this->baseUrl($settings);

        if (! $base) {
            throw new RuntimeException('No API endpoint URL is configured in Admin → Settings.');
        }

        $key = $settings->nocTicketApiKey();

        if (! $key) {
            throw new RuntimeException('No X-API-Key is configured.');
        }

        $url = $base.'/'.ltrim($endpoint, '/');

        // No explicit Content-Type: Guzzle has to set the multipart boundary.
        $request = Http::withHeaders([
            'X-API-Key' => $key,
            'Accept' => 'application/json',
        ])->timeout(60)->asMultipart();

        if ($file) {
            $request = $request->attach(
                'file',
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName(),
                ['Content-Type' => $file->getMimeType() ?: 'application/octet-stream'],
            );
        }

        $response = $request->post($url, $fields);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status().' from '.$url.': '
                .mb_substr($response->body(), 0, 300));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Expected JSON from '.$url.', got: '.mb_substr($response->body(), 0, 200));
        }

        return $body;
    }

    /**
     * GET one endpoint, relative to the base (e.g. `/getCategoriesForNOC`).
     *
     * @return array<mixed> the decoded body, unwrapped from {"data": …} if present
     *
     * @throws RuntimeException on any non-2xx, unconfigured endpoint, or a body
     *                          that is not JSON
     */
    public function get(string $endpoint, array $query = [], ?Setting $settings = null): array
    {
        $settings ??= Setting::get();
        $base = $this->baseUrl($settings);

        if (! $base) {
            throw new RuntimeException('No API endpoint URL is configured in Admin → Settings.');
        }

        $key = $settings->nocTicketApiKey();

        if (! $key) {
            throw new RuntimeException('No X-API-Key is configured.');
        }

        $url = $base.'/'.ltrim($endpoint, '/');

        $response = Http::withHeaders([
            'X-API-Key' => $key,
            'Accept' => 'application/json',
        ])->timeout(30)->get($url, $query);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status().' from '.$url.': '.mb_substr($response->body(), 0, 300));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Expected JSON from '.$url.', got: '.mb_substr($response->body(), 0, 200));
        }

        // Tolerate both a bare array and one wrapped in {"data": [...]}.
        if (isset($body['data']) && is_array($body['data'])) {
            $body = $body['data'];
        }

        return $body;
    }
}
