<?php

use App\Services\Identity\OracleHrImportService;

// normalizeMobile() is pure (no DB / no app services), so this runs as a plain
// unit test. The DB-dependent flows (parse / match / apply / resolve / flag)
// can't run here because the full migration chain is MySQL-only — they are
// verified manually against a seeded schema instead.

dataset('mobiles', [
    'bare 9-digit' => ['558304467', '+966558304467'],
    'leading zero' => ['0500855109', '+966500855109'],
    'double-zero trunk' => ['00500855109', '+966500855109'],
    'intl 00966' => ['00966558304467', '+966558304467'],
    'intl 966' => ['966558304467', '+966558304467'],
    'spaces + dashes' => ['050-085 5109', '+966500855109'],
    'placeholder 00' => ['00', null],
    'empty' => ['', null],
    'null' => [null, null],
    'non-numeric' => ['abc', null],
    'too short' => ['12345', null],
    'not a mobile (4...)' => ['0114567890', null],
]);

it('normalizes Saudi mobile numbers to E.164', function (?string $raw, ?string $expected) {
    expect((new OracleHrImportService)->normalizeMobile($raw))->toBe($expected);
})->with('mobiles');
