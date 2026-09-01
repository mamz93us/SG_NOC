<?php

namespace App\Services\KnowBe4;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * KnowBe4 Reporting API client.
 *
 * Only reads. Feeds the home portal's Security Score card, which shows a person
 * their own risk score and phishing-failure count and nobody else's.
 *
 * The API host is REGION-SPECIFIC. A token issued in the EU tenant returns 401
 * against the US host, which is indistinguishable from a bad token — so the
 * region is an explicit setting rather than a default anyone can drift from.
 */
class KnowBe4Service
{
    /** @see https://developer.knowbe4.com — one base URL per tenant region. */
    public const REGIONS = [
        'us' => 'https://us.api.knowbe4.com',
        'eu' => 'https://eu.api.knowbe4.com',
        'ca' => 'https://ca.api.knowbe4.com',
        'uk' => 'https://uk.api.knowbe4.com',
        'de' => 'https://de.api.knowbe4.com',
    ];

    private const PER_PAGE = 500;

    /**
     * KnowBe4 rate-limits hard, and a full sync makes ~50 calls. A small gap
     * between them keeps a cron run well under the ceiling; it costs a few
     * seconds on a job that runs daily.
     */
    private const THROTTLE_MICROSECONDS = 300000;

    /**
     * A phishing "failure" is any interaction beyond opening the mail. Opening
     * is not a failure — reading an email is not the mistake the test is
     * looking for; clicking, replying, opening the attachment, enabling the
     * macro, scanning the QR or typing credentials are.
     */
    public const FAILURE_FIELDS = [
        'clicked_at',
        'replied_at',
        'attachment_opened_at',
        'macro_enabled_at',
        'data_entered_at',
        'qr_code_scanned',
    ];

    public function isConfigured(?Setting $settings = null): bool
    {
        $settings ??= Setting::get();

        return (bool) $settings->knowbe4_enabled && (bool) $settings->knowbe4_api_token;
    }

    public function baseUrl(?Setting $settings = null): string
    {
        $settings ??= Setting::get();
        $region = strtolower(trim((string) $settings->knowbe4_region)) ?: 'us';

        return self::REGIONS[$region] ?? self::REGIONS['us'];
    }

    /**
     * Every user KnowBe4 knows about, following its pagination.
     *
     * @return array<int, array<string, mixed>>
     */
    public function users(?Setting $settings = null): array
    {
        return $this->paginate('/v1/users', $settings ?? Setting::get());
    }

    /**
     * Every training enrollment, which is where course progress actually lives.
     *
     * `/v1/users` carries NO course or phishing counts — only
     * `current_risk_score` and `phish_prone_percentage` — so anything else has
     * to be aggregated from here and from the phishing tests.
     *
     * Status vocabulary observed on the live tenant: Passed, Not Started,
     * In Progress, Past Due.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trainingEnrollments(?Setting $settings = null): array
    {
        return $this->paginate('/v1/training/enrollments', $settings ?? Setting::get());
    }

    /**
     * Every phishing security test (one row per send).
     *
     * @return array<int, array<string, mixed>>
     */
    public function securityTests(?Setting $settings = null): array
    {
        return $this->paginate('/v1/phishing/security_tests', $settings ?? Setting::get());
    }

    /**
     * Per-person results for one security test — who clicked, replied, entered
     * data and so on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function securityTestRecipients(int $pstId, ?Setting $settings = null): array
    {
        return $this->paginate('/v1/phishing/security_tests/'.$pstId.'/recipients', $settings ?? Setting::get());
    }

    /** True when this recipient row counts as a failed phishing test. */
    public static function recipientFailed(array $recipient): bool
    {
        foreach (self::FAILURE_FIELDS as $field) {
            if (! empty($recipient[$field])) {
                return true;
            }
        }

        return false;
    }

    /** The moment this recipient first failed, if they did. */
    public static function recipientFailedAt(array $recipient): ?string
    {
        $stamps = [];

        foreach (self::FAILURE_FIELDS as $field) {
            $value = $recipient[$field] ?? null;
            // qr_code_scanned is a flag, not a timestamp — it proves a failure
            // but cannot date it.
            if (! empty($value) && is_string($value)) {
                $stamps[] = $value;
            }
        }

        if ($stamps === []) {
            return null;
        }

        sort($stamps);

        return $stamps[0];
    }

    /**
     * Walk a paginated collection endpoint to the end.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paginate(string $path, Setting $settings): array
    {
        $all = [];
        $page = 1;

        while (true) {
            $batch = $this->get($path, ['page' => $page, 'per_page' => self::PER_PAGE], $settings);

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $all[] = $row;
            }

            // A short page is the last page; the API has no total count.
            if (count($batch) < self::PER_PAGE) {
                break;
            }

            $page++;

            // Hard stop so a paging bug cannot spin forever inside a cron run.
            if ($page > 100) {
                break;
            }
        }

        return $all;
    }

    /** Verifies credentials and region without importing anything. */
    public function testConnection(?Setting $settings = null): string
    {
        $settings ??= Setting::get();

        $result = $this->get('/v1/account', [], $settings);

        $name = $result['name'] ?? $result['company_name'] ?? 'account';
        $subscription = $result['subscription_level'] ?? 'unknown tier';

        return "Connected to {$name} ({$subscription}) via ".$this->baseUrl($settings);
    }

    private function get(string $path, array $query, Setting $settings): array
    {
        if (! $this->isConfigured($settings)) {
            throw new RuntimeException('KnowBe4 is not enabled or has no API token (Settings → KnowBe4).');
        }

        usleep(self::THROTTLE_MICROSECONDS);

        $response = Http::withToken($settings->knowbe4_api_token)
            ->acceptJson()
            ->timeout(60)
            ->get($this->baseUrl($settings).$path, $query);

        if ($response->status() === 401) {
            throw new RuntimeException(
                'KnowBe4 rejected the token (401). Check the token AND the region — '.
                'a token from another region fails exactly like a bad one. Currently set to: '
                .strtoupper((string) $settings->knowbe4_region).'.'
            );
        }

        if ($response->status() === 429) {
            throw new RuntimeException('KnowBe4 rate limit hit (429). The sync will pick up on the next run.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('KnowBe4 GET '.$path.' failed ('.$response->status().'): '
                .mb_substr($response->body(), 0, 500));
        }

        return $response->json() ?? [];
    }
}
