<?php

use App\Support\Marketing;

uses(Tests\TestCase::class);

beforeEach(fn () => Marketing::flush());
afterEach(fn () => Marketing::flush());

it('falls back to the default host when the marketing_domain column is absent', function () {
    // Unit tests don't migrate, so the settings table/column is absent here.
    // The resolver must not throw and must not leak a bogus value — notably the
    // SQLite double-quoted-identifier quirk, where selecting a missing column
    // returns the column name as a string literal. It must return the default.
    expect(Marketing::domain())->toBe('em.samirgroup.net');
});

it('builds absolute https urls on the marketing host', function () {
    expect(Marketing::url())->toBe('https://em.samirgroup.net/');
    expect(Marketing::url('campaigns'))->toBe('https://em.samirgroup.net/campaigns');
    expect(Marketing::url('/email/unsubscribe/x'))->toBe('https://em.samirgroup.net/email/unsubscribe/x');
});
