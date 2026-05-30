<?php

namespace App\Services\Teamtailor;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Teamtailor public REST API (JSON:API).
 *
 * Auth is a static API token sent as `Authorization: Token token=<KEY>` (NOT a
 * Bearer token). The `X-Api-Version` header is mandatory. Mirrors the HTTP
 * conventions used by App\Services\Identity\GraphService.
 */
class TeamtailorApiService
{
    private string $baseUrl;

    private string $apiKey;

    private string $apiVersion;

    private int $timeout;

    /** Teamtailor hard-caps page[size] at 30. */
    private const MAX_PAGE_SIZE = 30;

    /**
     * Credentials are resolved DB-first (admin Settings UI), then fall back to
     * the env-driven config. Constructor params override both, mirroring
     * App\Services\Identity\GraphService.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $apiVersion = null
    ) {
        $settings = $this->settings();

        $this->apiKey = (string) ($apiKey
            ?: $settings?->teamtailor_api_key
            ?: config('teamtailor.api_key'));

        $this->baseUrl = rtrim((string) ($baseUrl
            ?: $settings?->teamtailor_base_url
            ?: config('teamtailor.base_url')), '/');

        $this->apiVersion = (string) ($apiVersion
            ?: $settings?->teamtailor_api_version
            ?: config('teamtailor.api_version'));

        $this->timeout = (int) config('teamtailor.timeout', 30);
    }

    /**
     * The settings singleton, or null if the table is unavailable (e.g. during
     * a fresh install before migrations run, or in unit tests that skip them).
     */
    private function settings(): ?Setting
    {
        try {
            return Setting::get();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether an API key has been configured. Callers should branch on this so
     * the UI can show a "not configured" notice instead of throwing.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * GET /v1/candidates with JSON:API filters, sort and pagination.
     *
     * @param  array<string,string|int>  $filters  e.g. ['filter[email]' => 'a@b.com']
     * @param  array<int,string>  $include  relationships to side-load
     * @return array decoded JSON:API body: data[], included[], links{}, meta{}
     */
    public function listCandidates(
        array $filters = [],
        int $page = 1,
        ?int $size = null,
        ?string $sort = null,
        array $include = []
    ): array {
        $size = max(1, min(
            $size ?? (int) config('teamtailor.page_size', 25),
            self::MAX_PAGE_SIZE
        ));

        $query = array_merge($filters, [
            'page[size]' => $size,
            'page[number]' => max(1, $page),
        ]);

        if ($sort) {
            $query['sort'] = $sort;
        }
        if (! empty($include)) {
            $query['include'] = implode(',', $include);
        }

        return $this->get('/v1/candidates', $query);
    }

    /**
     * Fetch a single candidate by id, optionally side-loading relationships.
     *
     * @param  array<int,string>  $include
     */
    public function getCandidate(string $id, array $include = []): array
    {
        $query = [];
        if (! empty($include)) {
            $query['include'] = implode(',', $include);
        }

        return $this->get("/v1/candidates/{$id}", $query);
    }

    private function headers(): array
    {
        return [
            'Authorization' => "Token token={$this->apiKey}",
            'X-Api-Version' => $this->apiVersion,
            'Accept' => 'application/vnd.api+json',
        ];
    }

    /**
     * @param  array<string,string|int>  $query
     * @return array<string,mixed>
     */
    private function get(string $endpoint, array $query = []): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Teamtailor API key is not configured. Set TEAMTAILOR_API_KEY in your .env.');
        }

        $url = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl.$endpoint;

        try {
            $response = Http::timeout($this->timeout)->withHeaders($this->headers())->get($url, $query);

            // Rate limit is 50 req / 10s → HTTP 429. Back off and retry a few times.
            $attempts = 0;
            while ($response->status() === 429 && $attempts < 3) {
                $wait = (int) ($response->header('Retry-After')
                    ?: $response->header('X-Rate-Limit-Reset')
                    ?: 2);
                $wait = max(1, min($wait, 10));
                Log::warning("Teamtailor 429 on {$url} — waiting {$wait}s (attempt ".($attempts + 1).'/3)');
                sleep($wait);
                $response = Http::timeout($this->timeout)->withHeaders($this->headers())->get($url, $query);
                $attempts++;
            }
        } catch (ConnectionException $e) {
            Log::error("Teamtailor GET {$endpoint} connection error: ".$e->getMessage());
            throw new \RuntimeException('Could not reach Teamtailor: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $body = $response->body();
            Log::error("Teamtailor GET {$endpoint} failed ({$response->status()}): {$body}");
            throw new \RuntimeException(
                "Teamtailor API error ({$response->status()}): ".$this->extractError($body, $response->status())
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Pull a human-readable message out of a JSON:API error body.
     */
    private function extractError(string $body, int $status): string
    {
        $json = json_decode($body, true);
        if (is_array($json) && isset($json['errors'][0])) {
            $err = $json['errors'][0];
            $msg = trim(($err['title'] ?? '').' '.($err['detail'] ?? ''));

            return $msg !== '' ? $msg : "HTTP {$status}";
        }

        return "HTTP {$status}";
    }
}
