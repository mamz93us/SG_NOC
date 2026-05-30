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

        $this->baseUrl = static::normalizeBaseUrl($baseUrl
            ?: $settings?->teamtailor_base_url
            ?: config('teamtailor.base_url'));

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
     * Reduce a configured base URL to scheme://host[:port], discarding any path.
     *
     * The endpoint strings already carry the `/v1` prefix, so a base URL pasted
     * from the docs (e.g. `https://api.teamtailor.com/v1`) would otherwise be
     * doubled into `/v1/v1/candidates` and return a bare HTTP 404. Collapsing to
     * the host makes every paste variant resolve correctly.
     */
    public static function normalizeBaseUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return 'https://api.teamtailor.com';
        }

        $parts = parse_url($url);
        if (! isset($parts['host'])) {
            return rtrim($url, '/');
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$parts['host']}{$port}";
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

    /**
     * GET /v1/jobs — the recruiting positions candidates apply to.
     *
     * @param  array<string,string|int>  $filters
     * @return array decoded JSON:API body: data[], links{}, meta{}
     */
    public function listJobs(
        array $filters = [],
        int $page = 1,
        ?int $size = null,
        ?string $sort = null
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

        return $this->get('/v1/jobs', $query);
    }

    /**
     * GET /v1/job-applications for one job. Each row links a candidate to the
     * job and carries the pipeline stage (and, once rejected, a rejected-at
     * stamp). The candidate is side-loaded via `include` so the applicants
     * table renders names without an N+1 of per-candidate calls.
     *
     * @return array decoded JSON:API body: data[], included[] (candidates), meta{}
     */
    public function listJobApplications(
        string $jobId,
        int $page = 1,
        ?int $size = null,
        ?string $sort = null
    ): array {
        $size = max(1, min(
            $size ?? (int) config('teamtailor.page_size', 25),
            self::MAX_PAGE_SIZE
        ));

        $query = [
            'filter[job-id]' => $jobId,
            'include' => 'candidate',
            'page[size]' => $size,
            'page[number]' => max(1, $page),
        ];

        if ($sort) {
            $query['sort'] = $sort;
        }

        return $this->get('/v1/job-applications', $query);
    }

    /**
     * Reject a single job application by stamping `rejected-at`, moving it out
     * of the active pipeline into Teamtailor's "Rejected" section.
     *
     * Deliberately SILENT: a bare PATCH does not email the candidate — the
     * Teamtailor rejection email is a separate, opt-in action. It is also
     * reversible: clearing rejected-at in Teamtailor restores the application.
     *
     * @return array<string,mixed> the updated job-application resource
     */
    public function rejectJobApplication(string $applicationId): array
    {
        return $this->patch("/v1/job-applications/{$applicationId}", [
            'data' => [
                'type' => 'job-applications',
                'id' => $applicationId,
                'attributes' => ['rejected-at' => now()->toIso8601String()],
            ],
        ]);
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
     * PATCH a JSON:API document. Writes must carry the vnd.api+json content
     * type; auth/version/accept come from headers(). Shares the same 429
     * back-off and error surfacing as get().
     *
     * @param  array<string,mixed>  $payload  JSON:API document
     * @return array<string,mixed>
     */
    private function patch(string $endpoint, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Teamtailor API key is not configured. Set TEAMTAILOR_API_KEY in your .env.');
        }

        $url = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl.$endpoint;
        $body = json_encode($payload);

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders($this->headers())
                ->withBody($body, 'application/vnd.api+json')
                ->patch($url);

            // Same 50 req / 10s rate limit applies to writes.
            $attempts = 0;
            while ($response->status() === 429 && $attempts < 3) {
                $wait = (int) ($response->header('Retry-After')
                    ?: $response->header('X-Rate-Limit-Reset')
                    ?: 2);
                $wait = max(1, min($wait, 10));
                Log::warning("Teamtailor 429 on PATCH {$url} — waiting {$wait}s (attempt ".($attempts + 1).'/3)');
                sleep($wait);
                $response = Http::timeout($this->timeout)
                    ->withHeaders($this->headers())
                    ->withBody($body, 'application/vnd.api+json')
                    ->patch($url);
                $attempts++;
            }
        } catch (ConnectionException $e) {
            Log::error("Teamtailor PATCH {$endpoint} connection error: ".$e->getMessage());
            throw new \RuntimeException('Could not reach Teamtailor: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $body = $response->body();
            Log::error("Teamtailor PATCH {$endpoint} failed ({$response->status()}): {$body}");
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

            if ($msg !== '') {
                return $msg;
            }
        }

        // Teamtailor frequently returns these statuses with an empty (or
        // non-JSON:API) body, which would otherwise surface as a dead-end
        // "HTTP 403". Add an actionable hint for the ones seen in practice.
        return match ($status) {
            401 => 'Unauthorized — the API token is missing or invalid.',
            403 => 'Forbidden — the token is valid but lacks permission. Listing candidates requires an Admin-scope Teamtailor API key (the key may also be IP-restricted).',
            404 => 'Not found — check the base URL; it should be just the host, e.g. https://api.teamtailor.com.',
            406 => 'Unsupported API version — check the X-Api-Version value.',
            default => "HTTP {$status}",
        };
    }
}
