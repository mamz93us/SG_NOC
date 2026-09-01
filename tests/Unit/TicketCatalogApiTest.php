<?php

use App\Models\Setting;
use App\Services\Ticketing\TicketCatalog;
use App\Services\Ticketing\TicketCatalogApi;
use Illuminate\Support\Facades\Http;

/**
 * The category lookup, against the real payload shape the ticketing API
 * returns. No database — everything here works off an in-memory Setting and
 * the array cache.
 */
// Needs a booted app for config/cache/Http, but no database — so this file
// opts into TestCase without the RefreshDatabase that tests/Pest.php pins on
// the Feature suite.
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    $this->settings = new Setting;
    $this->settings->noc_ticket_api_url = 'https://sgapps-test.samirgroup.com/SamirTicketingAPIs/ticketing/api/addTicketingRequestForNOC';
    $this->settings->noc_ticket_api_key = 'test-key';

    $this->api = new TicketCatalogApi;
    $this->api->forget($this->settings);
});

it('derives the lookup base from the submit url', function () {
    expect(TicketCatalogApi::baseUrlFor('https://host/SamirTicketingAPIs/ticketing/api/addTicketingRequestForNOC'))
        ->toBe('https://host/SamirTicketingAPIs/ticketing/api')
        ->and(TicketCatalogApi::baseUrlFor('https://host/a/b/'))->toBe('https://host/a')
        ->and(TicketCatalogApi::baseUrlFor(''))->toBeNull()
        ->and(TicketCatalogApi::baseUrlFor('https://host'))->toBeNull();
});

it('reads the nested subCategories getCategoriesForNOC returns', function () {
    Http::fake(['*/getCategoriesForNOC' => Http::response([
        [
            'categoryId' => 1,
            'categoryName' => 'User Accounts & Access',
            'categoryNameAr' => 'حسابات المستخدمين والصلاحيات',
            'departmentId' => 1,
            'subCategories' => [
                ['subCategoryId' => 1, 'subCategoryName' => 'Password reset', 'subCategoryNameAr' => 'إعادة تعيين كلمة المرور', 'categoryId' => 1, 'typeId' => 1, 'priorityId' => 3, 'departmentId' => 1],
                ['subCategoryId' => 2, 'subCategoryName' => 'Account locked', 'categoryId' => 1, 'typeId' => 1, 'priorityId' => 3, 'departmentId' => 1],
            ],
        ],
    ])]);

    $categories = $this->api->refresh($this->settings);

    expect($categories)->toHaveCount(1)
        ->and($categories[0]['id'])->toBe(1)
        ->and($categories[0]['name'])->toBe('User Accounts & Access')
        ->and($categories[0]['name_ar'])->toBe('حسابات المستخدمين والصلاحيات')
        ->and($categories[0]['subcategories'])->toHaveCount(2)
        ->and($categories[0]['subcategories'][0])
        ->toMatchArray(['id' => 1, 'name' => 'Password reset', 'type_id' => 1, 'priority_id' => 3]);

    // Second read comes from the cache, so the API is hit once.
    expect($this->api->categories($this->settings))->toBe($categories);
    Http::assertSentCount(1);
});

it('falls back to getSubCategoriesForNOC when the nested list is absent', function () {
    Http::fake([
        '*/getCategoriesForNOC' => Http::response([
            ['categoryId' => 3, 'categoryName' => 'Network & Connectivity', 'departmentId' => 1],
        ]),
        '*/getSubCategoriesForNOC*' => Http::response([
            ['subCategoryId' => 13, 'subCategoryName' => 'No internet', 'categoryId' => 3, 'typeId' => 1, 'priorityId' => 3],
            ['subCategoryId' => 14, 'subCategoryName' => 'Slow network', 'categoryId' => 3, 'typeId' => 1, 'priorityId' => 1],
            // Belongs to another category — the endpoint's own categoryId wins.
            ['subCategoryId' => 99, 'subCategoryName' => 'Elsewhere', 'categoryId' => 8, 'typeId' => 1, 'priorityId' => 1],
        ]),
    ]);

    $categories = $this->api->refresh($this->settings);

    expect(array_column($categories[0]['subcategories'], 'id'))->toBe([13, 14]);
});

// The plain getCategories / getSubCategories are a different, JWT-guarded pair
// that answers our X-API-Key with 401. Only the ForNOC variants take the key,
// so the exact paths are pinned here.
it('calls the ForNOC endpoint variants, not the JWT-guarded ones', function () {
    Http::fake([
        '*/getCategoriesForNOC' => Http::response([
            ['categoryId' => 3, 'categoryName' => 'Network & Connectivity'],
        ]),
        '*/getSubCategoriesForNOC*' => Http::response([
            ['subCategoryId' => 13, 'subCategoryName' => 'No internet', 'categoryId' => 3],
        ]),
    ]);

    $this->api->refresh($this->settings);

    $base = 'https://sgapps-test.samirgroup.com/SamirTicketingAPIs/ticketing/api';
    Http::assertSent(fn ($r) => $r->url() === $base.'/getCategoriesForNOC'
        && $r->header('X-API-Key') === ['test-key']);
    Http::assertSent(fn ($r) => str_starts_with($r->url(), $base.'/getSubCategoriesForNOC?'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/getCategories?')
        || str_ends_with($r->url(), '/getCategories'));
});

it('does not cache a failed fetch as an empty catalog', function () {
    Http::fake(['*/getCategoriesForNOC' => Http::response('nope', 500)]);

    expect(fn () => $this->api->refresh($this->settings))->toThrow(RuntimeException::class);
    expect($this->api->categories($this->settings))->toBeNull()
        ->and($this->api->categoriesOrFetch($this->settings))->toBeNull()
        ->and($this->api->lastError($this->settings))->toContain('HTTP 500');
});

it('labels type and priority ids the settings json does not name', function () {
    $catalog = TicketCatalog::fromArray([
        'categories' => [[
            'id' => 3,
            'name' => 'Network',
            'subcategories' => [
                ['id' => 13, 'name' => 'No internet', 'type_id' => 1, 'priority_id' => 3],
            ],
        ]],
        'types' => [['id' => 1, 'name' => 'Incident']],
        // priorities deliberately empty — 3 has to be invented from the sub.
    ]);

    // backfillTypesAndPriorities() runs inside fromSettings(); exercise it the
    // same way here without touching the database.
    $method = new ReflectionMethod($catalog, 'backfillTypesAndPriorities');
    $method->setAccessible(true);
    $method->invoke($catalog);

    expect($catalog->typeName(1))->toBe('Incident')
        ->and($catalog->priorityName(3))->toBe('Priority 3')
        ->and($catalog->isConfigured())->toBeTrue()
        ->and($catalog->subcategory(3, 13))->toMatchArray(['type_id' => 1, 'priority_id' => 3]);
});
