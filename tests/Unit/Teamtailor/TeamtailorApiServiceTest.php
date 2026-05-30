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

it('normalizes base URLs so the /v1 prefix is never doubled', function (string $input, string $expected) {
    expect(TeamtailorApiService::normalizeBaseUrl($input))->toBe($expected);
})->with([
    'bare host'            => ['https://api.teamtailor.com', 'https://api.teamtailor.com'],
    'trailing slash'       => ['https://api.teamtailor.com/', 'https://api.teamtailor.com'],
    'pasted /v1'           => ['https://api.teamtailor.com/v1', 'https://api.teamtailor.com'],
    'pasted /v1/'          => ['https://api.teamtailor.com/v1/', 'https://api.teamtailor.com'],
    'full endpoint pasted' => ['https://api.teamtailor.com/v1/candidates', 'https://api.teamtailor.com'],
    'NA stack with /v1'    => ['https://api.na.teamtailor.com/v1', 'https://api.na.teamtailor.com'],
    'blank falls back'     => ['', 'https://api.teamtailor.com'],
]);

it('still calls /v1/candidates when the configured base url already includes /v1', function () {
    Http::fake(['api.teamtailor.com/*' => Http::response(['data' => []], 200)]);

    // Base URL pasted straight from the docs (includes the version prefix) —
    // this is the exact mistake that produced a 404 (/v1/v1/candidates).
    (new TeamtailorApiService(apiKey: 'k', baseUrl: 'https://api.teamtailor.com/v1'))->listCandidates();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.teamtailor.com/v1/candidates')
        && ! str_contains($request->url(), '/v1/v1'));
});
