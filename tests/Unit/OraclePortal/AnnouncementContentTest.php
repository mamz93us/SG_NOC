<?php

use App\Services\OraclePortal\AnnouncementContent;

/**
 * Oracle's announcement `description`, as it arrives since 2026-09-27:
 * base64-encoded HTML with the designed picture inlined as a data URI. The
 * shapes below are the ones production held on 2026-09-29.
 */
function tinyPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

function dataUri(string $bytes, string $claimed = 'image/png'): string
{
    return 'data:'.$claimed.';base64,'.base64_encode($bytes);
}

it('takes the picture out of a notice that is only a picture', function () {
    // 57 of 61 notices: a centred div holding one inlined PNG.
    $html = '<div style="text-align:center"><img src="'.dataUri(tinyPng()).'" alt="" /></div>';

    $content = AnnouncementContent::parse(base64_encode($html));

    expect($content['text'])->toBe('')
        ->and($content['link'])->toBeNull()
        ->and($content['images'])->toHaveCount(1)
        ->and($content['images'][0]['mime'])->toBe('image/png')
        ->and($content['images'][0]['bytes'])->toBe(tinyPng())
        ->and($content['images'][0]['sha1'])->toBe(sha1(tinyPng()));
});

it('never lets the picture\'s base64 leak into the text', function () {
    // One live notice's alt text holds an escaped "></div>", the kind of thing
    // a careless tag strip turns into half a megabyte of stray characters.
    $html = '<div style="text-align:center"><img src="'.dataUri(tinyPng()).'" alt=" &gt;&lt;/div&gt;" /></div>';

    expect(AnnouncementContent::parse(base64_encode($html))['text'])->toBe('');
});

it('keeps a notice\'s text readable: blocks as lines, cells apart, entities resolved', function () {
    $html = '<p class="x">Dear&nbsp;colleagues,</p><div><br></div>'
        .'<table><tbody><tr><td><b>Date</b></td><td>1 Oct</td></tr><tr><td>Place</td><td>Riyadh &amp; Jeddah</td></tr></tbody></table>';

    expect(AnnouncementContent::parse(base64_encode($html))['text'])
        ->toBe("Dear colleagues,\n\nDate 1 Oct\nPlace Riyadh & Jeddah");
});

it('keeps Arabic text intact', function () {
    $html = '<div>عطلة اليوم الوطني</div>';

    expect(AnnouncementContent::parse(base64_encode($html))['text'])->toBe('عطلة اليوم الوطني');
});

it('reads an empty editor paragraph as no text', function () {
    expect(AnnouncementContent::parse(base64_encode('<div><br></div>'))['text'])->toBe('');
});

it('reads a description Oracle never encoded as it stands', function () {
    // The test notice is raw HTML, not base64.
    $content = AnnouncementContent::parse('Announcement (Test)<h1 class="xnw" style="color: rgb(255, 2, 3)">Hello</h1>');

    expect($content['text'])->toBe('Announcement (Test)Hello');
});

it('does not mistake a short word for base64', function () {
    // "Test" is valid base64; what it decodes to is not readable text.
    expect(AnnouncementContent::parse('Test')['text'])->toBe('Test');
    expect(AnnouncementContent::parse('Holiday')['text'])->toBe('Holiday');
});

it('judges a picture by its bytes, not by the type the URI claims', function () {
    $html = '<img src="'.dataUri('<script>alert(1)</script>').'">'
        .'<img src="'.dataUri(tinyPng(), 'image/jpeg').'">';

    $images = AnnouncementContent::parse(base64_encode($html))['images'];

    expect($images)->toHaveCount(1)
        ->and($images[0]['mime'])->toBe('image/png');
});

it('keeps a picture repeated on the page once', function () {
    $html = '<img src="'.dataUri(tinyPng()).'"><img src="'.dataUri(tinyPng()).'">';

    expect(AnnouncementContent::parse(base64_encode($html))['images'])->toHaveCount(1);
});

it('takes the first web link and nothing else', function () {
    $html = '<a href="javascript:alert(1)">x</a><a href="mailto:hr@samirgroup.com">mail</a>'
        .'<a href="https://forms.office.com/r/abc?x=1&amp;y=2">Register</a><a href="https://second.example">2</a>';

    expect(AnnouncementContent::parse(base64_encode($html))['link'])
        ->toBe('https://forms.office.com/r/abc?x=1&y=2');
});

it('has no link when there is none', function () {
    expect(AnnouncementContent::parse(base64_encode('<p>No link</p>'))['link'])->toBeNull();
});

it('fits the text into the body column', function () {
    $html = '<p>'.str_repeat('ب', 40000).'</p>';

    expect(strlen(AnnouncementContent::parse(base64_encode($html))['text']))
        ->toBeLessThanOrEqual(AnnouncementContent::MAX_TEXT_BYTES);
});

it('reads a missing or non-string description as empty', function () {
    foreach ([null, '', '   ', ['x'], 42] as $value) {
        expect(AnnouncementContent::parse($value))->toBe(['text' => '', 'images' => [], 'link' => null]);
    }
});
