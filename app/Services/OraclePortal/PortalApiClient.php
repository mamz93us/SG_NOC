<?php

namespace App\Services\OraclePortal;

use App\Models\OraclePortal\PortalSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Samir Employee Portal API (Oracle HR), read side.
 *
 * Base URL and key live in Admin → Settings, not `.env` — same shape as
 * {@see \App\Services\Ticketing\TicketingApiClient}: stateless, settings
 * passed in or resolved per call, `X-API-Key`, no retry, and a non-2xx turned
 * into a RuntimeException carrying enough of the body to diagnose it.
 *
 * The API is GET-only by design: it answers any other verb with 403 even with
 * a valid key. There is deliberately no write method here.
 *
 * The contract is Oracle's "Samir Employee Portal API" document of 2026-09-27:
 * seven GET endpoints, a wrong or missing key answers 401, errors are JSON
 * `{error, message}`, and every date is yyyy-MM-dd. That release removed the
 * undocumented /attendance endpoint the employee sync used to read, and moved
 * what only it carried — personId — onto /employees. Checked against
 * production on 2026-09-29:
 *
 *  - **Null fields are omitted entirely**, not sent as null. So a missing key
 *    means "Oracle holds no value" — a phone of "-" is Oracle's own
 *    placeholder for none, and is read as none.
 *  - **There is no pagination.** Every list endpoint returns its whole table:
 *    617 employees, 614 balances, ~730 leave records. /announcements is the
 *    heavy one, ~16 MB for 61 rows, because each `description` is the notice's
 *    HTML with its designed picture inlined, base64 inside base64.
 *
 * Nothing here is cached. Unlike the ticketing catalogue, no page renders live
 * data from this API — the sync commands write to our own tables and every
 * screen reads those — so a cache would only add a way for the two to disagree.
 */
class PortalApiClient
{
    /** Plenty for the four small endpoints, which answer in ~0.2 s. */
    private const TIMEOUT = 30;

    /** /announcements (~16 MB) answers in ~2 s; the rest in well under one. */
    private const TIMEOUT_BULK = 60;

    public function isConfigured(?PortalSetting $settings = null): bool
    {
        return ($settings ?? PortalSetting::get())->isConfigured();
    }

    // ─── Announcements ────────────────────────────────────────────

    /**
     * Every announcement, or only those Oracle still counts as live.
     *
     * The sync pulls them all (61 rows, 13 KB): the expired ones are the
     * archive's history, and an announcement whose expiry Oracle later pushes
     * out would never come back if we only ever asked for the live ones.
     *
     * @return list<array<string,mixed>>
     */
    public function announcements(bool $activeOnly = false, ?PortalSetting $settings = null): array
    {
        return $this->list('/announcements', $activeOnly ? ['activeOnly' => 'true'] : [], $settings, self::TIMEOUT_BULK);
    }

    /** One announcement, or null when Oracle does not have that id. */
    public function announcement(string $announcementId, ?PortalSetting $settings = null): ?array
    {
        $body = $this->request('/announcements/'.rawurlencode($announcementId), [], self::TIMEOUT_BULK, $settings, allowMissing: true);

        return is_array($body) && $body !== [] ? $body : null;
    }

    // ─── People ───────────────────────────────────────────────────

    /**
     * The employee list: everyone active, plus anyone whose termination date
     * passed within the last month, who comes back with status "Inactive".
     * Somebody who left longer ago drops out of the list altogether — which
     * is one more reason absence from it never means anything here.
     *
     * Carries `personId`, the only key /employees/{id} and
     * /vacationBalance/{id} accept; a personNumber there 404s. A
     * `?personNumber=` lookup matches exactly ("01003" finds nobody) and
     * returns the person whether active or not.
     *
     * @return list<array<string,mixed>>
     */
    public function employees(?string $personNumber = null, ?PortalSetting $settings = null): array
    {
        return $this->list('/employees', $personNumber !== null ? ['personNumber' => $personNumber] : [], $settings);
    }

    // ─── Leave ────────────────────────────────────────────────────

    /**
     * Oracle's leave balances. `absences` comes back NEGATIVE (539 of 614 rows
     * on 2026-09-29, never positive) — pass it on unchanged; VacationImporter
     * negates it into a positive `used`. Oracle's restricted people are left
     * out of this and of /vacationDetails, by Oracle.
     *
     * @return list<array<string,mixed>>
     */
    public function vacationBalances(?string $personNumber = null, ?PortalSetting $settings = null): array
    {
        return $this->list('/vacationBalance', $personNumber !== null ? ['personNumber' => $personNumber] : [], $settings);
    }

    /**
     * Leave and business-trip records, on a rolling window. Until 2026-09-27
     * it reached ~120 days back (7,668 records); since then it starts on the
     * first of the previous month (~730) — plus everything booked ahead
     * either way.
     *
     * @return list<array<string,mixed>>
     */
    public function vacationDetails(?string $personNumber = null, ?PortalSetting $settings = null): array
    {
        return $this->list(
            '/vacationDetails',
            $personNumber !== null ? ['personNumber' => $personNumber] : [],
            $settings,
            self::TIMEOUT_BULK,
        );
    }

    // ─── Transport ────────────────────────────────────────────────

    /**
     * A list endpoint, always as a zero-indexed array of rows.
     *
     * @return list<array<string,mixed>>
     */
    private function list(string $endpoint, array $query, ?PortalSetting $settings, int $timeout = self::TIMEOUT): array
    {
        $body = $this->request($endpoint, $query, $timeout, $settings);

        if (! array_is_list($body)) {
            throw new RuntimeException('Expected a JSON array from '.$endpoint.', got an object.');
        }

        return array_values(array_filter($body, 'is_array'));
    }

    /**
     * @param  bool  $allowMissing  return [] on a 404 instead of throwing —
     *                              for a by-id lookup, where "no such row" is
     *                              an answer rather than a fault
     *
     * @throws RuntimeException on anything unconfigured, non-2xx or not JSON
     */
    private function request(
        string $endpoint,
        array $query,
        int $timeout,
        ?PortalSetting $settings,
        bool $allowMissing = false,
    ): array {
        $settings ??= PortalSetting::get();

        if ($issue = $settings->configurationIssue()) {
            throw new RuntimeException($issue);
        }

        $url = $settings->baseUrl().'/'.ltrim($endpoint, '/');

        $response = Http::withHeaders([
            'X-API-Key' => $settings->api_key,
            'Accept' => 'application/json',
        ])->timeout($timeout)->get($url, $query);

        if ($allowMissing && $response->status() === 404) {
            return [];
        }

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
}
