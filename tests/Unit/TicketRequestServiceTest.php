<?php

use App\Models\Setting;
use App\Services\Ticketing\TicketingApiClient;
use App\Services\Ticketing\TicketRequestService;
use App\Services\Ticketing\TicketStatus;
use Illuminate\Support\Facades\Http;

/**
 * Reading tickets back, against the payload shape the ticketing API returns.
 * No database — an in-memory Setting and the array cache.
 */
uses(Tests\TestCase::class);

/** One list row, trimmed to the fields the service reads. */
function ticketRow(array $overrides = []): array
{
    return array_merge([
        'ticketId' => 654,
        'ticketTitle' => 'title200',
        'ticketDescription' => 'desc200',
        'ticketCategory' => 8,
        'ticketCategoryName' => 'IT Equipment Requests',
        'ticketCategoryNameAr' => 'طلبات معدات تقنية المعلومات',
        'ticketSubCategory' => 40,
        'ticketSubCategoryName' => 'New laptop/PC',
        'ticketType' => 2,
        'ticketTypeName' => 'Service Request',
        'ticketStatus' => TicketStatus::OPEN,
        'statusName' => 'Open',
        'statusNameAr' => 'مفتوح',
        // The LIST endpoint's spelling.
        'ticketPriotity' => 4,
        'ticketPriorityName' => 'Low',
        'createdDate' => '2026-08-30T13:19:45',
        'createdBy' => 'Alaa.Sakr@sssegypt.com',
        'ticketingRequestAttachmentsDTO' => [],
        'ticketingRequestDetails' => [],
    ], $overrides);
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    cache()->flush();

    $this->settings = new Setting;
    $this->settings->noc_ticket_api_url = 'https://host/SamirTicketingAPIs/ticketing/api/addTicketingRequestForNOC';
    $this->settings->noc_ticket_api_key = 'test-key';
    $this->settings->noc_ticket_api_enabled = true;

    // Pinned settings, so nothing here touches the database.
    $this->service = new TicketRequestService(new TicketingApiClient, $this->settings);
});

it('normalizes a list row and sorts newest first', function () {
    Http::fake(['*/getTicketingRequestsByStatusForNOC*' => Http::response([
        ticketRow(['ticketId' => 1, 'createdDate' => '2026-01-01T09:00:00']),
        ticketRow(['ticketId' => 2, 'createdDate' => '2026-08-30T13:19:45']),
    ])]);

    $tickets = $this->service->listFor('alaa.sakr@sssegypt.com');

    expect(array_column($tickets, 'id'))->toBe([2, 1])
        ->and($tickets[0])->toMatchArray([
            'title' => 'title200',
            'category_name' => 'IT Equipment Requests',
            'subcategory_name' => 'New laptop/PC',
            'status_id' => TicketStatus::OPEN,
            'status_name' => 'Open',
            'priority_id' => 4,
            'priority_name' => 'Low',
        ]);
});

// The list endpoint spells it ticketPriotity, the details endpoint
// ticketPriotiry. Both are the API's own typos and both must be read.
it('reads either spelling of the priority field', function () {
    Http::fake([
        '*/getTicketingRequestsByStatusForNOC*' => Http::response([ticketRow(['ticketPriotity' => 4])]),
        '*/getTicketingRequestDetailsForNOC*' => Http::response(
            array_merge(ticketRow(), ['ticketPriotity' => null, 'ticketPriotiry' => 2])
        ),
    ]);

    expect($this->service->listFor('a@b.com')[0]['priority_id'])->toBe(4)
        ->and($this->service->details(654)['priority_id'])->toBe(2);
});

it('counts live tickets as open, in progress and waiting on the user', function () {
    Http::fake(['*/getTicketingRequestsByStatusForNOC*' => Http::response([
        ticketRow(['ticketId' => 1, 'ticketStatus' => TicketStatus::OPEN]),
        ticketRow(['ticketId' => 2, 'ticketStatus' => TicketStatus::IN_PROGRESS]),
        ticketRow(['ticketId' => 3, 'ticketStatus' => TicketStatus::WAITING_FOR_USER]),
        ticketRow(['ticketId' => 4, 'ticketStatus' => TicketStatus::CLOSED]),
        ticketRow(['ticketId' => 5, 'ticketStatus' => TicketStatus::REJECTED]),
    ])]);

    $summary = $this->service->summaryFor('a@b.com');

    expect($summary['total'])->toBe(5)
        ->and($summary['live'])->toBe(3)
        ->and($summary['by_status'][TicketStatus::CLOSED])->toBe(1)
        ->and($summary['error'])->toBeNull();
});

it('sends the email and requestStatus the caller asked for, and caches the result', function () {
    Http::fake(['*/getTicketingRequestsByStatusForNOC*' => Http::response([ticketRow()])]);

    $this->service->listFor('Someone@Samirgroup.com', TicketStatus::WAITING_FOR_USER);
    $this->service->listFor('Someone@Samirgroup.com', TicketStatus::WAITING_FOR_USER);

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'email=Someone%40Samirgroup.com')
        && str_contains($r->url(), 'requestStatus=3'));
});

it('reports a failure in the summary instead of throwing', function () {
    Http::fake(['*/getTicketingRequestsByStatusForNOC*' => Http::response('boom', 500)]);

    $summary = $this->service->summaryFor('a@b.com');

    expect($summary['error'])->toContain('HTTP 500')
        ->and($summary['total'])->toBe(0)
        ->and($summary['live'])->toBe(0);

    // The portal badge swallows the same failure and shows nothing.
    expect($this->service->liveCountFor('a@b.com'))->toBe(0);
});

it('builds the timeline oldest first and keeps attachment-only rows', function () {
    Http::fake(['*/getTicketingRequestDetailsForNOC*' => Http::response(ticketRow([
        'ticketingRequestDetails' => [
            ['detailId' => 2, 'comments' => 'second', 'createdDate' => '2026-06-02T12:30:00'],
            ['detailId' => 1, 'comments' => 'first', 'createdDate' => '2026-06-02T12:27:22'],
            ['detailId' => 3, 'comments' => null, 'attachmentName' => 'shot.png', 'createdDate' => '2026-06-02T12:40:00'],
        ],
        'ticketingRequestAttachmentsDTO' => [
            ['attachmentId' => 467, 'attachmentName' => 'shot.png', 'attachmentType' => 'image/png'],
            ['attachmentId' => 999],
        ],
    ]))]);

    $ticket = $this->service->details(654);

    expect(array_column($ticket['timeline'], 'id'))->toBe([1, 2, 3])
        ->and($ticket['timeline'][2]['attachment_name'])->toBe('shot.png')
        // The nameless attachment is dropped — there is nothing to show for it.
        ->and($ticket['attachments'])->toHaveCount(1);
});

// The write endpoint takes multipart with a JSON `data` part, not a JSON body.
// This pins the exact shape observed working in Postman.
it('posts a comment as multipart with a JSON data part', function () {
    Http::fake(['*/addTicketingRequestDetailMobileForNOC' => Http::response([
        'detailId' => 631,
        'statusName' => 'In Progress',
        'comments' => 'TEST',
        'createdBy' => 'alaa.sakr@sssegypt.com',
        'createdDate' => '2026-09-02T09:10:34',
    ])]);

    $created = $this->service->addComment(620, 'TEST', 'alaa.sakr@sssegypt.com');

    expect($created['detailId'])->toBe(631);

    Http::assertSent(function ($request) {
        $parts = collect($request->data())->keyBy('name');

        return str_ends_with($request->url(), '/addTicketingRequestDetailMobileForNOC')
            && $request->method() === 'POST'
            && $request->hasHeader('X-API-Key', 'test-key')
            && str_contains($request->header('Content-Type')[0] ?? '', 'multipart/form-data')
            && $parts['data']['contents'] === '{"ticketId":620,"comments":"TEST"}'
            && $parts['email']['contents'] === 'alaa.sakr@sssegypt.com';
    });
});

it('busts the cached ticket and lists after commenting', function () {
    Http::fake([
        '*/getTicketingRequestsByStatusForNOC*' => Http::response([ticketRow(['ticketId' => 620])]),
        '*/getTicketingRequestDetailsForNOC*' => Http::response(ticketRow(['ticketId' => 620])),
        '*/addTicketingRequestDetailMobileForNOC' => Http::response(['detailId' => 1]),
    ]);

    $this->service->listFor('a@b.com');
    $this->service->details(620);
    $this->service->addComment(620, 'hello', 'a@b.com');

    // Both were cached; both must be re-fetched, or the reply appears to vanish.
    $this->service->listFor('a@b.com');
    $this->service->details(620);

    Http::assertSentCount(5);
});

it('refuses to comment when the API rejects it', function () {
    Http::fake(['*/addTicketingRequestDetailMobileForNOC' => Http::response('nope', 500)]);

    expect(fn () => $this->service->addComment(620, 'hi', 'a@b.com'))
        ->toThrow(RuntimeException::class);
});
