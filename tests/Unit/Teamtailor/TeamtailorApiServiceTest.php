<?php

use App\Services\Teamtailor\TeamtailorApiService;
use Illuminate\Support\Facades\Http;

// Boot the Laravel app (for config + Http facade) WITHOUT RefreshDatabase —
// this service never touches the database, so we avoid the Feature suite's
// full migration run.
uses(Tests\TestCase::class);

beforeEach(function () {
    config()->set('teamtailor.base_url', 'https://api.teamtailor.com');
    config()->set('teamtailor.api_key', 'test-key-123');
    config()->set('teamtailor.api_version', '20240904');
    config()->set('teamtailor.page_size', 25);
    config()->set('teamtailor.timeout', 5);
});

it('sends token auth, version header and pagination params', function () {
    Http::fake([
        'api.teamtailor.com/*' => Http::response([
            'data' => [],
            'meta' => ['record-count' => 0],
        ], 200),
    ]);

    (new TeamtailorApiService)->listCandidates(['filter[email]' => 'a@b.com'], page: 2, size: 25, sort: '-created-at');

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://api.teamtailor.com/v1/candidates')
            && $request->hasHeader('Authorization', 'Token token=test-key-123')
            && $request->hasHeader('X-Api-Version', '20240904')
            && $request['filter[email]'] === 'a@b.com'
            && $request['page[size]'] == 25
            && $request['page[number]'] == 2
            && $request['sort'] === '-created-at';
    });
});

it('caps page size at the Teamtailor maximum of 30', function () {
    Http::fake(['api.teamtailor.com/*' => Http::response(['data' => []], 200)]);

    (new TeamtailorApiService)->listCandidates([], size: 500);

    Http::assertSent(fn ($request) => $request['page[size]'] == 30);
});

it('parses the JSON:API candidate body', function () {
    Http::fake([
        'api.teamtailor.com/*' => Http::response([
            'data' => [[
                'id' => '42',
                'type' => 'candidates',
                'attributes' => ['first-name' => 'Mona', 'last-name' => 'Ali', 'email' => 'mona@example.com'],
            ]],
            'meta' => ['record-count' => 1],
        ], 200),
    ]);

    $body = (new TeamtailorApiService)->listCandidates();

    expect($body['data'][0]['id'])->toBe('42')
        ->and($body['data'][0]['attributes']['email'])->toBe('mona@example.com')
        ->and($body['meta']['record-count'])->toBe(1);
});

it('throws a readable error from a JSON:API error body', function () {
    Http::fake([
        'api.teamtailor.com/*' => Http::response([
            'errors' => [['title' => 'Forbidden', 'detail' => 'Admin scope required']],
        ], 403),
    ]);

    expect(fn () => (new TeamtailorApiService)->listCandidates())
        ->toThrow(RuntimeException::class, 'Admin scope required');
});

it('reports not configured when the api key is blank', function () {
    config()->set('teamtailor.api_key', '');

    $service = new TeamtailorApiService;

    expect($service->isConfigured())->toBeFalse()
        ->and(fn () => $service->listCandidates())->toThrow(RuntimeException::class, 'not configured');
});
