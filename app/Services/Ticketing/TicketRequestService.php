<?php

namespace App\Services\Ticketing;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reads a person's tickets back out of the ticketing system.
 *
 *   GET {base}/getTicketingRequestsByStatusForNOC?email=…&requestStatus=…
 *   GET {base}/getTicketingRequestDetailsForNOC?requestId=…
 *
 * The list endpoint returns **whole ticket objects**, not summaries — the same
 * shape the details endpoint gives, timeline and attachments included. So the
 * counts for every state come from a single `requestStatus=0` call grouped
 * locally, rather than eight calls; and the detail page still asks the details
 * endpoint, which is cheap and authoritative for one ticket.
 *
 * Results are cached for a short while only. Someone refreshing this page is
 * usually waiting on an engineer, and stale-by-a-minute is the most staleness
 * that is defensible.
 */
class TicketRequestService
{
    public const ENDPOINT_LIST = '/getTicketingRequestsByStatusForNOC';

    public const ENDPOINT_DETAILS = '/getTicketingRequestDetailsForNOC';

    /** Adds a comment (and optionally one file) to an existing ticket. */
    public const ENDPOINT_ADD_DETAIL = '/addTicketingRequestDetailMobileForNOC';

    /** Short: this page is refreshed by people waiting for an answer. */
    public const TTL_SECONDS = 60;

    /**
     * @param  Setting|null  $settings  pinned settings, used for every call.
     *                                  Null means "read the live row", which is
     *                                  what the container-resolved instance
     *                                  does; passing one keeps callers (and
     *                                  tests) off the database.
     */
    public function __construct(
        private TicketingApiClient $client,
        private ?Setting $settings = null,
    ) {}

    private function settings(): Setting
    {
        return $this->settings ?? Setting::get();
    }

    public function isConfigured(?Setting $settings = null): bool
    {
        $settings ??= $this->settings();

        return (bool) ($settings->noc_ticket_api_enabled
            && $this->client->baseUrl($settings)
            && $settings->nocTicketApiKey());
    }

    /**
     * Every ticket for one person, newest first.
     *
     * @param  int  $status  a {@see TicketStatus} value; ALL (0) by default
     * @return array<int, array<string,mixed>>
     *
     * @throws RuntimeException when the API cannot be reached
     */
    public function listFor(string $email, int $status = TicketStatus::ALL): array
    {
        $email = trim($email);

        if ($email === '') {
            return [];
        }

        $key = 'noc_tickets_by_status:'.md5(mb_strtolower($email)).':'.$status;

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->client->get(self::ENDPOINT_LIST, [
            'email' => $email,
            'requestStatus' => $status,
        ], $this->settings());

        $tickets = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['ticketId'])) {
                $tickets[] = $this->normalize($row);
            }
        }

        // Newest first. The API's own order is not documented, so it is not
        // trusted — an unparsable date sorts last rather than throwing.
        usort($tickets, fn ($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));

        Cache::put($key, $tickets, self::TTL_SECONDS);

        return $tickets;
    }

    /**
     * One ticket, in full. Returns null when the id is unknown.
     *
     * @throws RuntimeException when the API cannot be reached
     */
    public function details(int $requestId): ?array
    {
        $key = 'noc_ticket_details:'.$requestId;

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $row = $this->client->get(self::ENDPOINT_DETAILS, ['requestId' => $requestId], $this->settings());

        if (! isset($row['ticketId'])) {
            return null;
        }

        $ticket = $this->normalize($row);
        Cache::put($key, $ticket, self::TTL_SECONDS);

        return $ticket;
    }

    /**
     * Add a comment — and optionally one attachment — to an existing ticket.
     *
     *   POST {base}/addTicketingRequestDetailMobileForNOC   (multipart)
     *     data   {"ticketId": 620, "comments": "…"}
     *     email  the person the comment is from
     *     file   optional attachment
     *
     * Returns the created timeline row, which is the same shape the details
     * endpoint nests under `ticketingRequestDetails`.
     *
     * **This is a write that other people see**, so callers must satisfy
     * themselves the person owns the ticket first — the API takes a bare
     * ticketId and does not check.
     *
     * @throws RuntimeException when the API refuses or cannot be reached
     */
    public function addComment(
        int $ticketId,
        string $comment,
        string $email,
        ?UploadedFile $attachment = null,
    ): array {
        $created = $this->client->postMultipart(
            self::ENDPOINT_ADD_DETAIL,
            [
                'data' => json_encode(
                    ['ticketId' => $ticketId, 'comments' => $comment],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'email' => $email,
            ],
            $attachment,
            $this->settings(),
        );

        // The ticket and this person's lists are now stale by definition.
        Cache::forget('noc_ticket_details:'.$ticketId);
        $this->forget($email);

        return $created;
    }

    /**
     * How many tickets this person has in each state, plus the totals the
     * summary strip shows. One API call, grouped here.
     *
     * Never throws: a summary that cannot be built is reported as unavailable
     * rather than taking the page down with it.
     *
     * @return array{total:int, live:int, by_status:array<int,int>, error:?string}
     */
    public function summaryFor(string $email): array
    {
        try {
            $tickets = $this->listFor($email, TicketStatus::ALL);
        } catch (\Throwable $e) {
            Log::warning('TicketRequestService: summary failed', ['error' => $e->getMessage()]);

            return ['total' => 0, 'live' => 0, 'by_status' => [], 'error' => $e->getMessage()];
        }

        $byStatus = [];
        $live = 0;

        foreach ($tickets as $ticket) {
            $id = $ticket['status_id'];
            $byStatus[$id] = ($byStatus[$id] ?? 0) + 1;

            if (TicketStatus::isLive($id)) {
                $live++;
            }
        }

        return [
            'total' => count($tickets),
            'live' => $live,
            'by_status' => $byStatus,
            'error' => null,
        ];
    }

    /**
     * Just the number of live tickets, for the badge on the portal's start
     * page.
     *
     * That page is opened unattended on every company PC, so this gets its own
     * longer cache and never throws or blocks on a failure: a ticketing system
     * having a bad morning must not slow down, or break, the page everyone
     * lands on. A miss shows no badge, which is the honest answer.
     */
    public function liveCountFor(string $email): int
    {
        $email = trim($email);

        if ($email === '' || ! $this->isConfigured()) {
            return 0;
        }

        return Cache::remember(
            'noc_tickets_live_count:'.md5(mb_strtolower($email)),
            300,
            function () use ($email) {
                try {
                    $live = 0;
                    foreach ($this->listFor($email, TicketStatus::ALL) as $t) {
                        if (TicketStatus::isLive($t['status_id'])) {
                            $live++;
                        }
                    }

                    return $live;
                } catch (\Throwable $e) {
                    Log::warning('TicketRequestService: live count failed', ['error' => $e->getMessage()]);

                    return 0;
                }
            }
        );
    }

    public function forget(string $email): void
    {
        $hash = md5(mb_strtolower(trim($email)));

        foreach (TicketStatus::filterable() as $status) {
            Cache::forget('noc_tickets_by_status:'.$hash.':'.$status);
        }

        Cache::forget('noc_tickets_live_count:'.$hash);
    }

    /**
     * API shape → one stable shape for both pages.
     *
     * Watch the priority key: the list endpoint spells it `ticketPriotity` and
     * the details endpoint `ticketPriotiry`. Both are the API's own typos, in
     * different directions, so both are read.
     */
    private function normalize(array $r): array
    {
        return [
            'id' => (int) $r['ticketId'],
            'title' => (string) ($r['ticketTitle'] ?? ''),
            'description' => (string) ($r['ticketDescription'] ?? ''),

            'category_id' => self::int($r['ticketCategory'] ?? null),
            'category_name' => self::str($r['ticketCategoryName'] ?? null),
            'category_name_ar' => self::str($r['ticketCategoryNameAr'] ?? null),
            'subcategory_id' => self::int($r['ticketSubCategory'] ?? null),
            'subcategory_name' => self::str($r['ticketSubCategoryName'] ?? null),
            'subcategory_name_ar' => self::str($r['ticketSubCategoryNameAr'] ?? null),

            'type_id' => self::int($r['ticketType'] ?? null),
            'type_name' => self::str($r['ticketTypeName'] ?? null),
            'type_name_ar' => self::str($r['ticketTypeNameAr'] ?? null),

            // See the docblock — two spellings of the same field.
            'priority_id' => self::int($r['ticketPriotity'] ?? $r['ticketPriotiry'] ?? null),
            'priority_name' => self::str($r['ticketPriorityName'] ?? null),
            'priority_name_ar' => self::str($r['ticketPriorityNameAr'] ?? null),

            'status_id' => self::int($r['ticketStatus'] ?? null),
            'status_name' => self::str($r['statusName'] ?? null) ?? TicketStatus::label(self::int($r['ticketStatus'] ?? null)),
            'status_name_ar' => self::str($r['statusNameAr'] ?? null),

            'created_at' => self::str($r['createdDate'] ?? null),
            'updated_at' => self::str($r['updatedDate'] ?? null),
            'opened_at' => self::str($r['openedDate'] ?? null),
            'closed_at' => self::str($r['closeDate'] ?? null),
            'completed_at' => self::str($r['completedDate'] ?? null),
            'estimated_finish_at' => self::str($r['estimatedFinishDate'] ?? null),

            'created_by' => self::str($r['createdBy'] ?? null),
            'engineer_name' => self::str($r['engineerName'] ?? null),
            'department_name' => self::str($r['dispatchDepartmentName'] ?? null),
            'assigned_task_desc' => self::str($r['engAssignedTaskDesc'] ?? null),
            'sla_hours' => self::int($r['priorityTimeIntervalHours'] ?? null),

            'attachments' => $this->normalizeAttachments($r['ticketingRequestAttachmentsDTO'] ?? null),
            'timeline' => $this->normalizeTimeline($r['ticketingRequestDetails'] ?? null),
        ];
    }

    private function normalizeAttachments(mixed $raw): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $a) {
            if (! is_array($a)) {
                continue;
            }
            $name = self::str($a['attachmentName'] ?? null);
            if ($name === null) {
                continue;
            }
            $out[] = [
                'id' => self::int($a['attachmentId'] ?? null),
                'name' => $name,
                'type' => self::str($a['attachmentType'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * The per-ticket history. Rows that carry nothing but an attachment name
     * are kept — that is how an upload shows up on the timeline.
     */
    private function normalizeTimeline(mixed $raw): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $d) {
            if (! is_array($d)) {
                continue;
            }
            $out[] = [
                'id' => self::int($d['detailId'] ?? null),
                'status_name' => self::str($d['statusName'] ?? null),
                'comments' => self::str($d['comments'] ?? null),
                'created_by' => self::str($d['createdBy'] ?? null),
                'assigned_to' => self::str($d['assignedTo'] ?? null),
                'created_at' => self::str($d['createdDate'] ?? null),
                'attachment_name' => self::str($d['attachmentName'] ?? null),
            ];
        }

        // Oldest first — a history reads downward.
        usort($out, fn ($a, $b) => ($a['created_at'] ?? '') <=> ($b['created_at'] ?? ''));

        return $out;
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    /** Empty strings are as absent as nulls here, and the views test for null. */
    private static function str(mixed $v): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
