<?php

namespace App\Services\AvePoint;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AvePoint Graph API client (Cloud Backup for Microsoft 365).
 *
 * Auth confirmed from public docs:
 *   POST https://identity.avepointonlineservices.com/connect/token
 *   grant_type=client_credentials, client_id, client_secret, scope
 *
 * Base URL: https://graph-{dc}.avepointonlineservices.com  (dc driven by avepoint_region)
 *
 * The public Cloud Backup for M365 API is READ-ONLY (jobs / license consumption /
 * unusual activity / settings). It does NOT publicly document a trigger-export
 * or download-export endpoint. We code monitoring against the documented
 * endpoints and stub the trigger/download paths behind configurable settings —
 * when avepoint_export_endpoint and avepoint_download_endpoint are blank, the
 * offboarding flow falls back to a manual-upload form so IT can export from
 * the AvePoint UI and upload via NOC.
 */
class AvePointApiService
{
    private string $identityUrl;
    private string $baseUrl;
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private ?string $location;
    private ?string $exportEndpoint;
    private ?string $downloadEndpoint;

    /** Illustrative path examples shown in the Settings form — never treat as real endpoints. */
    private const PLACEHOLDER_ENDPOINTS = [
        '/backup/m365/exports',
        'backup/m365/exports',
        '/backup/m365/exports/{jobId}/file',
        'backup/m365/exports/{jobId}/file',
    ];

    public function __construct(
        ?string $tenantId = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $region = null,
    ) {
        $settings = Setting::get();
        $this->tenantId         = $tenantId      ?? $settings->avepoint_tenant_id    ?? '';
        $this->clientId         = $clientId      ?? $settings->avepoint_client_id    ?? '';
        $this->clientSecret     = $clientSecret  ?? $settings->avepoint_client_secret ?? '';
        $this->location         = $settings->avepoint_location ?: null;

        $exportEp = trim((string) ($settings->avepoint_export_endpoint ?? ''));
        $downloadEp = trim((string) ($settings->avepoint_download_endpoint ?? ''));
        $this->exportEndpoint   = ($exportEp   === '' || in_array($exportEp,   self::PLACEHOLDER_ENDPOINTS, true)) ? null : $exportEp;
        $this->downloadEndpoint = ($downloadEp === '' || in_array($downloadEp, self::PLACEHOLDER_ENDPOINTS, true)) ? null : $downloadEp;

        $resolvedRegion = $region ?? $settings->avepoint_region ?? 'us';
        $this->baseUrl  = rtrim(
            $settings->avepoint_base_url
                ?: "https://graph-{$resolvedRegion}.avepointonlineservices.com",
            '/'
        );

        // Identity service URL — same for all commercial AvePoint environments.
        // Override by setting avepoint_base_url to a *-aos2 or *-gov URL pattern
        // and providing the matching identity URL via env if you need to.
        $this->identityUrl = config('services.avepoint.identity_url')
            ?: 'https://identity.avepointonlineservices.com/connect/token';
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function hasExportEndpoints(): bool
    {
        return $this->exportEndpoint !== null && $this->downloadEndpoint !== null;
    }

    /**
     * OAuth2 client_credentials token (cached ~58 min — token TTL is ~3600s).
     * Scope determines which API surface the token can access.
     */
    private function getAccessToken(string $scope = 'microsoft365backup.jobInfo.read.all'): string
    {
        $cacheKey = "avepoint_token_{$this->clientId}_" . md5($scope);

        return Cache::remember($cacheKey, 3500, function () use ($scope) {
            $response = Http::asForm()->post($this->identityUrl, [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => $scope,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    'AvePoint OAuth failed: HTTP ' . $response->status() . ' — ' . $response->body()
                );
            }

            $token = $response->json('access_token');
            if (! $token) {
                throw new \RuntimeException('AvePoint OAuth returned no access_token.');
            }

            return $token;
        });
    }

    /**
     * Test connection — probes the OAuth token endpoint, then two read-only API
     * endpoints. Returns a verbose detail string so 404s point at the right cause
     * (unlicensed product vs missing scope vs wrong DC).
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'detail' => 'AvePoint client_id/client_secret not set in settings.'];
        }

        try {
            $token = $this->getAccessToken('microsoft365backup.subscriptionInfo.read.all');
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'OAuth: ' . $e->getMessage()];
        }

        $subUrl = "{$this->baseUrl}/backup/m365/cloudbackuplicenseconsumption";
        $sub    = Http::withToken($token)->timeout(15)->get($subUrl);

        if ($sub->successful()) {
            $data = $sub->json('data');
            return [
                'ok'     => true,
                'detail' => 'Connected. Subscription seats: '
                    . ($data['assignedUserSeats']  ?? '?') . ' / '
                    . ($data['purchasedUserSeats'] ?? '?')
                    . ' · Protected: '
                    . ($data['protectedSize']     ?? '?') . ' GB',
            ];
        }

        // Fallback probe — Jobs API uses a different scope. Try it with a fresh
        // token so a 200 here vs 404 on subscriptions narrows the diagnosis down
        // to a permission/scope issue rather than a DC / base-URL issue.
        try {
            $jobsToken = $this->getAccessToken('microsoft365backup.jobInfo.read.all');
            $jobsUrl   = "{$this->baseUrl}/backup/m365/cloudbackupjobs?pageSize=1";
            $jobs      = Http::withToken($jobsToken)->timeout(15)->get($jobsUrl);

            if ($jobs->successful()) {
                return [
                    'ok'     => false,
                    'detail' => "Jobs API works but Subscription API returned HTTP {$sub->status()} ({$subUrl}). "
                              . "Most likely the 'microsoft365backup.subscriptionInfo.read.all' scope is "
                              . "not granted to your app registration in AvePoint Online Services.",
                ];
            }

            return [
                'ok'     => false,
                'detail' => "Both probes failed. Subscription: HTTP {$sub->status()} ({$subUrl}). "
                          . "Jobs: HTTP {$jobs->status()} ({$jobsUrl}). "
                          . "Check: (1) the Cloud Backup for M365 product is enabled on this AvePoint tenant; "
                          . "(2) the app registration has both 'microsoft365backup.subscriptionInfo.read.all' "
                          . "and 'microsoft365backup.jobInfo.read.all' permissions; "
                          . "(3) the data-center URL matches the tenant (you're set to {$this->baseUrl}).",
            ];
        } catch (\Throwable $e) {
            return [
                'ok'     => false,
                'detail' => "Subscription API: HTTP {$sub->status()} ({$subUrl}). Jobs API token error: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Read the configured AvePoint base URL — used by views that want to
     * link out to the AvePoint UI for a given job.
     */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Recent AvePoint jobs (mailbox + OneDrive backups + exports) — used by
     * the admin jobs-monitor page. Returns an empty array when AvePoint
     * is not configured rather than throwing, so the page degrades gracefully.
     *
     * @param array $filter optional: ['objectType' => 1|3, 'jobType' => 1|3, 'state' => 1|2|3, 'pageSize' => int]
     */
    public function listRecentJobs(array $filter = []): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        try {
            $token = $this->getAccessToken('microsoft365backup.jobInfo.read.all');

            // AvePoint's request sample uses Pascal-case query params; the parameter
            // table uses camelCase. The Pascal form is what's shown in their
            // worked example, so we use that for safety.
            // Time window defaults to 30 days — wide enough that a freshly-set-up
            // tenant with weekly backups still shows entries.
            $params = array_filter([
                'StartTime'  => $filter['startTime']  ?? now()->subDays(30)->utc()->format('Y-m-d'),
                'FinishTime' => $filter['finishTime'] ?? now()->addDay()->utc()->format('Y-m-d'),
                'JobType'    => $filter['jobType']    ?? null,
                'ObjectType' => $filter['objectType'] ?? null,
                'JobState'   => $filter['jobState']   ?? null,
                'PageSize'   => $filter['pageSize']   ?? 50,
                'PageIndex'  => $filter['pageIndex']  ?? 0,
                // Location only when explicitly set — passing it on a non-multi-geo
                // tenant returns an empty list.
                'Location'   => $this->location,
            ], fn($v) => $v !== null && $v !== '');

            $response = Http::withToken($token)
                ->timeout(20)
                ->get("{$this->baseUrl}/backup/m365/cloudbackupjobs", $params);

            if (! $response->successful()) {
                Log::warning('AvePointApiService::listRecentJobs non-2xx', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 400),
                    'params' => $params,
                ]);
                return [];
            }

            return $response->json('data', []) ?? [];
        } catch (\Throwable $e) {
            Log::warning('AvePointApiService::listRecentJobs threw', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Same as listRecentJobs() but returns a structured ['data' => [...],
     * 'error' => ?string, 'request_url' => string] so the dashboard can
     * tell the user *why* the list is empty (auth failure / no jobs / bad
     * location filter / etc.).
     */
    public function listRecentJobsVerbose(array $filter = []): array
    {
        if (! $this->isConfigured()) {
            return ['data' => [], 'error' => 'AvePoint is not configured.', 'request_url' => null];
        }

        try {
            $token = $this->getAccessToken('microsoft365backup.jobInfo.read.all');
        } catch (\Throwable $e) {
            return ['data' => [], 'error' => 'OAuth: ' . $e->getMessage(), 'request_url' => null];
        }

        $params = array_filter([
            'StartTime'  => $filter['startTime']  ?? now()->subDays(30)->utc()->format('Y-m-d'),
            'FinishTime' => $filter['finishTime'] ?? now()->addDay()->utc()->format('Y-m-d'),
            'JobType'    => $filter['jobType']    ?? null,
            'ObjectType' => $filter['objectType'] ?? null,
            'JobState'   => $filter['jobState']   ?? null,
            'PageSize'   => $filter['pageSize']   ?? 50,
            'PageIndex'  => $filter['pageIndex']  ?? 0,
            'Location'   => $this->location,
        ], fn($v) => $v !== null && $v !== '');

        $url = "{$this->baseUrl}/backup/m365/cloudbackupjobs?" . http_build_query($params);

        try {
            $response = Http::withToken($token)->timeout(20)->get(
                "{$this->baseUrl}/backup/m365/cloudbackupjobs", $params
            );
        } catch (\Throwable $e) {
            return ['data' => [], 'error' => 'HTTP error: ' . $e->getMessage(), 'request_url' => $url];
        }

        if (! $response->successful()) {
            return [
                'data'        => [],
                'error'       => "HTTP {$response->status()} — " . substr($response->body(), 0, 300),
                'request_url' => $url,
            ];
        }

        return [
            'data'        => $response->json('data', []) ?? [],
            'error'       => null,
            'request_url' => $url,
            'meta'        => $response->json('metadata') ?? null,
        ];
    }

    /**
     * Subscription / consumption snapshot — used for the dashboard "storage used"
     * card. Returns null when unconfigured or on error.
     */
    public function getSubscription(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $token = $this->getAccessToken('microsoft365backup.subscriptionInfo.read.all');

            $params = array_filter(['location' => $this->location], fn($v) => $v !== null);
            $response = Http::withToken($token)
                ->timeout(15)
                ->get("{$this->baseUrl}/backup/m365/cloudbackuplicenseconsumption", $params);

            return $response->successful() ? $response->json('data') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Backup frequency per service from AvePoint Cloud Backup for M365.
     * Documented endpoint: GET /backup/m365/settings/backup/frequency
     * Required scope: microsoft365backup.settings.read.all
     */
    public function getBackupFrequency(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $token = $this->getAccessToken('microsoft365backup.settings.read.all');
            $params = array_filter(['location' => $this->location], fn($v) => $v !== null);

            $response = Http::withToken($token)
                ->timeout(15)
                ->get("{$this->baseUrl}/backup/m365/settings/backup/frequency", $params);

            return $response->successful() ? $response->json('data') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Retention policy snapshot from AvePoint Cloud Backup for M365.
     * Documented endpoint: GET /backup/m365/settings/retention-policy
     * Required scope: microsoft365backup.settings.read.all
     */
    public function getRetentionPolicy(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $token = $this->getAccessToken('microsoft365backup.settings.read.all');
            $params = array_filter(['location' => $this->location], fn($v) => $v !== null);

            $response = Http::withToken($token)
                ->timeout(15)
                ->get("{$this->baseUrl}/backup/m365/settings/retention-policy", $params);

            return $response->successful() ? $response->json('data') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Unusual activity detected by AvePoint Cloud Backup for M365.
     * Documented endpoint: GET /backup/m365/cloudbackupunusualactivitydata
     * Required scope: microsoft365backup.unusualActivity.read.all
     */
    public function getUnusualActivity(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $token = $this->getAccessToken('microsoft365backup.unusualActivity.read.all');
            $params = array_filter(['location' => $this->location], fn($v) => $v !== null);

            $response = Http::withToken($token)
                ->timeout(15)
                ->get("{$this->baseUrl}/backup/m365/cloudbackupunusualactivitydata", $params);

            return $response->successful() ? $response->json('data') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Find the most recent successful backup job for a user.
     *
     * @param string $upn         User UPN to filter by (best-effort — AvePoint job
     *                            data may not include UPN; we search recent jobs
     *                            and trust the caller's verification logic).
     * @param int    $objectType  1=Exchange, 3=OneDrive (per AvePoint docs).
     * @param int    $withinHours How far back to look.
     */
    public function findRecentBackupJob(string $upn, int $objectType, int $withinHours = 48): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $token = $this->getAccessToken('microsoft365backup.jobInfo.read.all');

            $startTime = now()->subHours($withinHours)->utc()->format('Y-m-d');
            $finishTime = now()->utc()->format('Y-m-d');

            $response = Http::withToken($token)
                ->timeout(20)
                ->get("{$this->baseUrl}/backup/m365/cloudbackupjobs", array_filter([
                    'startTime'  => $startTime,
                    'finishTime' => $finishTime,
                    'jobType'    => 1,           // 1 = Backup
                    'objectType' => $objectType,
                    'jobState'   => 2,           // 2 = Finished
                    'pageSize'   => 50,
                    'pageIndex'  => 0,
                    'location'   => $this->location,
                ], fn($v) => $v !== null));

            if (! $response->successful()) {
                Log::warning('AvePointApiService::findRecentBackupJob non-2xx', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 400),
                ]);
                return null;
            }

            $jobs = $response->json('data', []);
            // Return newest first (jobs are typically chronological; sort to be safe)
            usort($jobs, fn($a, $b) => strcmp($b['finishTime'] ?? '', $a['finishTime'] ?? ''));

            return $jobs[0] ?? null;
        } catch (\Throwable $e) {
            Log::warning('AvePointApiService::findRecentBackupJob threw', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Request a mailbox export job. STUBBED until the real endpoint is documented.
     *
     * Returns ['job_id' => …, 'mode' => 'live'|'stub'].
     *
     * TODO(avepoint-export-endpoints): when the trigger endpoint is documented,
     * POST to it here with the user's UPN and capture the returned job id. The
     * shape is expected to mirror /insights/job/{jobId}/exportfile (job-id-keyed
     * GET that streams the file).
     */
    public function requestMailboxExport(string $upn): array
    {
        if (! $this->hasExportEndpoints()) {
            return [
                'job_id' => 'MANUAL-MAILBOX-' . uniqid(),
                'mode'   => 'stub',
                'reason' => 'avepoint_export_endpoint not configured — falling back to manual upload.',
            ];
        }

        $token = $this->getAccessToken('microsoft365backup.jobInfo.read.all');
        $url   = rtrim($this->baseUrl, '/') . '/' . ltrim($this->exportEndpoint, '/');

        $response = Http::withToken($token)
            ->timeout(30)
            ->post($url, ['upn' => $upn, 'objectType' => 1]); // 1 = Exchange

        if (! $response->successful()) {
            throw new \RuntimeException(
                'AvePoint mailbox export request failed: HTTP ' . $response->status() . ' — ' . $response->body()
            );
        }

        return [
            'job_id' => $response->json('data.id') ?? $response->json('job_id') ?? throw new \RuntimeException('AvePoint returned no job id.'),
            'mode'   => 'live',
        ];
    }

    /**
     * Request a OneDrive export job. STUBBED until the real endpoint is documented.
     */
    public function requestOneDriveExport(string $upn): array
    {
        if (! $this->hasExportEndpoints()) {
            return [
                'job_id' => 'MANUAL-ONEDRIVE-' . uniqid(),
                'mode'   => 'stub',
                'reason' => 'avepoint_export_endpoint not configured — falling back to manual upload.',
            ];
        }

        $token = $this->getAccessToken('microsoft365backup.jobInfo.read.all');
        $url   = rtrim($this->baseUrl, '/') . '/' . ltrim($this->exportEndpoint, '/');

        $response = Http::withToken($token)
            ->timeout(30)
            ->post($url, ['upn' => $upn, 'objectType' => 3]); // 3 = OneDrive

        if (! $response->successful()) {
            throw new \RuntimeException(
                'AvePoint OneDrive export request failed: HTTP ' . $response->status() . ' — ' . $response->body()
            );
        }

        return [
            'job_id' => $response->json('data.id') ?? $response->json('job_id') ?? throw new \RuntimeException('AvePoint returned no job id.'),
            'mode'   => 'live',
        ];
    }

    /**
     * Poll an export job's status. STUBBED when trigger endpoints are absent.
     *
     * Returns ['status' => 'pending|running|completed|failed|manual_upload_required', ...].
     */
    public function getExportStatus(string $jobId): array
    {
        if (! $this->hasExportEndpoints()) {
            return ['status' => 'manual_upload_required'];
        }

        // Pattern observed in public docs: /backup/m365/cloudbackupjobs has jobState as int.
        // 1 = In Progress, 2 = Finished, 3 = Failed, 4 = Finished with Exception, 5 = Partial.
        $token = $this->getAccessToken('microsoft365backup.jobInfo.read.all');

        $response = Http::withToken($token)
            ->timeout(20)
            ->get("{$this->baseUrl}/backup/m365/cloudbackupjobs", [
                'pageSize'  => 1,
                'pageIndex' => 0,
            ]);

        if (! $response->successful()) {
            return ['status' => 'failed', 'detail' => "HTTP {$response->status()}"];
        }

        // Caller will pluck the specific job from the data array; this default
        // path covers the case where the real status endpoint is just GET /jobs/{id}.
        $jobs = collect($response->json('data', []));
        $job  = $jobs->firstWhere('id', $jobId);

        if (! $job) {
            return ['status' => 'unknown'];
        }

        $state = strtolower((string) ($job['state'] ?? 'unknown'));
        return [
            'status' => match (true) {
                str_contains($state, 'progress'), str_contains($state, 'running') => 'running',
                str_contains($state, 'finish'), $state === 'completed'             => 'completed',
                str_contains($state, 'fail')                                       => 'failed',
                str_contains($state, 'partial')                                    => 'completed', // treat partial as complete
                default                                                            => $state,
            },
            'raw' => $job,
        ];
    }

    /**
     * Stream a job's export file via a writer callback. Returns total bytes piped.
     * STUBBED when download endpoint is absent — caller falls back to manual upload.
     *
     * @param callable $writeChunk (string $chunk): void — called for each chunk read.
     */
    public function downloadExport(string $jobId, callable $writeChunk): int
    {
        if (! $this->hasExportEndpoints()) {
            throw new \RuntimeException(
                'AvePoint download endpoint not configured — flow should use manual-upload fallback.'
            );
        }

        $token = $this->getAccessToken('microsoft365backup.jobInfo.read.all');
        $url   = rtrim($this->baseUrl, '/') . '/' . str_replace('{jobId}', $jobId, ltrim($this->downloadEndpoint, '/'));

        $response = Http::withToken($token)
            ->withOptions(['stream' => true])
            ->timeout(0)        // no overall timeout — large transfers
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "AvePoint download for job {$jobId} returned HTTP " . $response->status()
            );
        }

        $body  = $response->toPsrResponse()->getBody();
        $bytes = 0;
        while (! $body->eof()) {
            $chunk = $body->read(1024 * 1024); // 1 MB chunks
            if ($chunk === '') {
                break;
            }
            $writeChunk($chunk);
            $bytes += strlen($chunk);
        }

        return $bytes;
    }
}
