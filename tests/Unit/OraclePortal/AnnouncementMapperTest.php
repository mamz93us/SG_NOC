<?php

use App\Services\OraclePortal\AnnouncementMapper;
use Carbon\CarbonImmutable;

/**
 * Oracle's announcements onto announcement columns.
 *
 * The subjects below are real ones from the live feed, checked on 2026-09-20.
 * They are here because they are what defeats every clever idea about this
 * data: three different bilingual shapes, one of which would strand a dead
 * colleague's name in a column nothing renders.
 */
it('maps an announcement onto the columns', function () {
    $mapped = AnnouncementMapper::map([
        'announcementId' => '300000438097080',
        'subject' => 'SAMIR Announcement - Digital Transformation Division',
        'imageName' => 'Company Organization Announcement.jpg',
        'startDate' => '2026-09-17',
        'expireDate' => '2026-09-24',
        'isExpired' => 'N',
    ], CarbonImmutable::parse('2026-09-20 06:00:00'));

    expect($mapped['external_id'])->toBe('300000438097080');
    expect($mapped['title'])->toBe('SAMIR Announcement - Digital Transformation Division');
    expect($mapped['external_image_name'])->toBe('Company Organization Announcement.jpg');
    expect($mapped['published_at']->toDateTimeString())->toBe('2026-09-17 00:00:00');
});

it('expires at the end of the expiry day, not its start', function () {
    // Announcement::scopeLive compares expires_at > now(), so midnight would
    // pull each notice off the board a day early. Oracle agrees the day is
    // inclusive: on the 20th, a row expiring the 23rd still reported N.
    $mapped = AnnouncementMapper::map([
        'announcementId' => '1', 'subject' => 'x', 'expireDate' => '2026-09-23', 'isExpired' => 'N',
    ], CarbonImmutable::parse('2026-09-20 06:00:00'));

    expect($mapped['expires_at']->toDateTimeString())->toBe('2026-09-23 23:59:59');
    expect($mapped['expires_at']->gt(CarbonImmutable::parse('2026-09-23 18:00:00')))->toBeTrue();
});

it('believes Oracle when it says a notice is over', function () {
    // isExpired is Oracle's own precomputed answer. A notice retired early
    // would otherwise sit on every company PC until its printed date passed.
    $now = CarbonImmutable::parse('2026-09-20 06:00:00');

    $mapped = AnnouncementMapper::map([
        'announcementId' => '1', 'subject' => 'x', 'expireDate' => '2026-12-31', 'isExpired' => 'Y',
    ], $now);

    expect($mapped['expires_at']->toDateTimeString())->toBe($now->toDateTimeString());
});

it('expires a notice Oracle calls over even with no expiry date', function () {
    $now = CarbonImmutable::parse('2026-09-20 06:00:00');

    $mapped = AnnouncementMapper::map([
        'announcementId' => '1', 'subject' => 'x', 'isExpired' => 'Y',
    ], $now);

    expect($mapped['expires_at']->toDateTimeString())->toBe($now->toDateTimeString());
});

it('leaves a live notice with no expiry date never expiring', function () {
    $mapped = AnnouncementMapper::map([
        'announcementId' => '1', 'subject' => 'x', 'isExpired' => 'N',
    ], CarbonImmutable::parse('2026-09-20 06:00:00'));

    expect($mapped['expires_at'])->toBeNull();
});

it('keeps a bilingual subject whole rather than splitting it', function () {
    // Splitting on the pipe would put the deceased's name in title_ar — a
    // column the home portal never renders for an announcement. The text
    // would simply disappear.
    $subject = 'Condolences Jeddah   نعـــي جدة | Qamar Uddin Moinuddin';

    $mapped = AnnouncementMapper::map([
        'announcementId' => '1', 'subject' => $subject, 'isExpired' => 'Y',
    ]);

    expect($mapped['title'])->toBe($subject);
    expect($mapped)->not->toHaveKey('title_ar');
});

it('keeps an Arabic-first subject whole too', function () {
    $subject = 'تهنئـة عيـد الأضحـى - Eid Al Adha Greeting';

    expect(AnnouncementMapper::map(['announcementId' => '1', 'subject' => $subject])['title'])
        ->toBe($subject);
});

it('keeps a subject with no separator at all whole', function () {
    $subject = 'National Day holiday announcement-عطلة اليوم الوطني_96';

    expect(AnnouncementMapper::map(['announcementId' => '1', 'subject' => $subject])['title'])
        ->toBe($subject);
});

it('writes an empty body for a notice with no description', function () {
    // announcements.body is NOT NULL, so this must be '' and not null.
    $mapped = AnnouncementMapper::map(['announcementId' => '1', 'subject' => 'x']);

    expect($mapped['body'])->toBe('')
        ->and($mapped['images'])->toBe([])
        ->and($mapped['link_url'])->toBeNull();
});

it('takes a base64 description apart instead of storing it', function () {
    // Written into body as it stands, this overflowed the TEXT column on every
    // hourly pull from 2026-09-28 — and would have put a screen of base64 on
    // every company PC had it fitted.
    $html = '<div style="text-align:center"><img src="'.announcementPngUri().'" alt="" /></div>'
        .'<p>The office closes at <b>2pm</b>.</p><a href="https://portal.example.com/register">Register</a>';

    $mapped = AnnouncementMapper::map([
        'announcementId' => '1', 'subject' => 'x', 'description' => base64_encode($html),
    ]);

    expect($mapped['body'])->toBe("The office closes at 2pm.\nRegister")
        ->and($mapped['link_url'])->toBe('https://portal.example.com/register')
        ->and($mapped['images'])->toHaveCount(1)
        ->and($mapped['images'][0]['mime'])->toBe('image/png');
});

it('reads plain text as it stands', function () {
    $mapped = AnnouncementMapper::map([
        'announcementId' => '1', 'subject' => 'x', 'description' => 'The office closes at 2pm.',
    ]);

    expect($mapped['body'])->toBe('The office closes at 2pm.');
});

it('refuses a row with no id', function () {
    expect(AnnouncementMapper::map(['subject' => 'x']))->toBeNull();
    expect(AnnouncementMapper::map(['announcementId' => '   ', 'subject' => 'x']))->toBeNull();
});

it('falls back to a title when Oracle sends no subject', function () {
    expect(AnnouncementMapper::map(['announcementId' => '42'])['title'])->toBe('Announcement 42');
});

it('ignores a date it cannot read rather than guessing', function () {
    // Announcement dates are yyyy-MM-dd here, unlike the dd-MMM-yy the
    // employee and leave feeds use. A surprise is left absent.
    $mapped = AnnouncementMapper::map([
        'announcementId' => '1', 'subject' => 'x', 'startDate' => '17-SEP-26', 'isExpired' => 'N',
    ]);

    expect($mapped['published_at'])->toBeNull();
});

// ─── The merge rule: an edit made here survives the next pull ──────

it('refreshes a field that still holds what the sync wrote', function () {
    $held = ['title' => 'Oracle title', 'body' => '', 'link_url' => null, 'published_at' => null, 'expires_at' => null];
    $wrote = ['title' => 'Oracle title', 'body' => '', 'link_url' => null, 'published_at' => null, 'expires_at' => null];

    expect(AnnouncementMapper::writableColumns($held, $wrote))
        ->toBe(['title', 'body', 'link_url', 'published_at', 'expires_at']);
});

it('leaves a field somebody edited here alone', function () {
    $held = ['title' => 'A clearer title', 'body' => 'Written by HR', 'published_at' => null, 'expires_at' => null];
    $wrote = ['title' => 'Oracle title', 'body' => '', 'published_at' => null, 'expires_at' => null];

    $writable = AnnouncementMapper::writableColumns($held, $wrote);

    expect($writable)->not->toContain('title');
    expect($writable)->not->toContain('body');
    expect($writable)->toContain('published_at');
});

it('takes a field back when somebody restores Oracle\'s own text', function () {
    $held = ['title' => 'Oracle title', 'body' => '', 'published_at' => null, 'expires_at' => null];
    $wrote = ['title' => 'Oracle title', 'body' => '', 'published_at' => null, 'expires_at' => null];

    expect(AnnouncementMapper::writableColumns($held, $wrote))->toContain('title');
});

it('claims a column it has never written before only while it is empty', function () {
    // A row from before this feature, or a column the sync took on later, as
    // it did link_url on 2026-09-29: there is no snapshot to compare with. An
    // empty column is the sync's to fill; a value is somebody's, and silence
    // is not permission to replace it.
    expect(AnnouncementMapper::writableColumns(['title' => 'x', 'link_url' => 'https://set.by/admin'], []))
        ->toBe(['body', 'published_at', 'expires_at']);

    expect(AnnouncementMapper::writableColumns(['title' => 'x', 'body' => '', 'link_url' => null], ['title' => 'x']))
        ->toBe(['title', 'body', 'link_url', 'published_at', 'expires_at']);
});

it('compares a Carbon date against the stored snapshot string', function () {
    // The held value arrives as Carbon through the model's casts and the
    // snapshot comes back from JSON as a string. Compared naively they never
    // match, and the sync would think every date had been edited by hand.
    $held = [
        'title' => 'x',
        'body' => '',
        'link_url' => null,
        'published_at' => CarbonImmutable::parse('2026-09-17 00:00:00'),
        'expires_at' => CarbonImmutable::parse('2026-09-24 23:59:59'),
    ];

    $snapshot = AnnouncementMapper::snapshot($held);
    $roundTripped = json_decode(json_encode($snapshot), true);

    expect(AnnouncementMapper::writableColumns($held, $roundTripped))
        ->toBe(['title', 'body', 'link_url', 'published_at', 'expires_at']);
});

it('stores dates in the snapshot as plain strings', function () {
    $snapshot = AnnouncementMapper::snapshot([
        'title' => 'x', 'body' => '',
        'published_at' => CarbonImmutable::parse('2026-09-17 00:00:00'),
        'expires_at' => null,
    ]);

    expect($snapshot['published_at'])->toBe('2026-09-17 00:00:00');
    expect($snapshot['expires_at'])->toBeNull();
    expect($snapshot)->toHaveKeys(['title', 'body', 'link_url', 'published_at', 'expires_at'])
        ->and($snapshot)->not->toHaveKey('images');
});

/** A real 1×1 PNG as a data URI, the shape Oracle inlines its pictures in. */
function announcementPngUri(): string
{
    return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
}
