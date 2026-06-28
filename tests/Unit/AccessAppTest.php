<?php

use App\Services\Access\AccessVisitRecorder;
use App\Support\Marketing;
use Illuminate\Http\Request;

// Pure logic — appFor() resolves the app from host + path. Marketing::domain()
// is bootstrap-safe (falls back to em.samirgroup.net when settings/DB are absent),
// so this runs without booting the framework or a database.

beforeEach(fn () => Marketing::flush());

it('tags the marketing host as em', function () {
    $r = Request::create('https://em.samirgroup.net/', 'GET');
    expect(AccessVisitRecorder::appFor($r))->toBe('em');
});

it('tags /portal paths as portal', function () {
    expect(AccessVisitRecorder::appFor(Request::create('https://noc.samirgroup.net/portal', 'GET')))->toBe('portal');
    expect(AccessVisitRecorder::appFor(Request::create('https://noc.samirgroup.net/portal/assets', 'GET')))->toBe('portal');
});

it('tags everything else as noc', function () {
    expect(AccessVisitRecorder::appFor(Request::create('https://noc.samirgroup.net/admin/devices', 'GET')))->toBe('noc');
    expect(AccessVisitRecorder::appFor(Request::create('https://noc.samirgroup.net/', 'GET')))->toBe('noc');
});
