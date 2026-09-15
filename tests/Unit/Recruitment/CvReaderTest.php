<?php

use App\Services\Ai\PdfPages;
use App\Services\Recruitment\CvReader;
use App\Services\Recruitment\CvUnreadable;
use Illuminate\Support\Facades\Http;

// Boots the app for the Http facade; no database. poppler is replaced by a
// fake, since the Windows dev box does not have it.
uses(Tests\TestCase::class);

/** @param  list<string>  $pageTexts */
function fakeCvPages(array $pageTexts): PdfPages
{
    return new class($pageTexts) extends PdfPages
    {
        public function __construct(private array $pageTexts) {}

        public function count(string $path): int
        {
            return count($this->pageTexts);
        }

        public function text(string $path, int $page): string
        {
            return $this->pageTexts[$page - 1] ?? '';
        }

        public function image(string $path, int $page): string
        {
            return "JPEG{$page}";
        }
    };
}

it('reads the text of a CV that has a text layer', function () {
    Http::fake(['cv.example/*' => Http::response('%PDF-1.7 fake', 200)]);

    $cv = (new CvReader(fakeCvPages([str_repeat('Accountant with SAP FICO. ', 30), 'References on request'])))
        ->read('https://cv.example/cv.pdf');

    expect($cv['pages'])->toBe(2)
        ->and($cv['images'])->toBe([])
        ->and($cv['text'])->toStartWith("[Page 1]\nAccountant")
        ->and($cv['text'])->toContain("[Page 2]\nReferences on request");
});

it('reads a scanned CV from images of its first pages', function () {
    Http::fake(['cv.example/*' => Http::response('%PDF-1.4 scan', 200)]);

    $cv = (new CvReader(fakeCvPages(['', '', '', '', ''])))->read('https://cv.example/scan.pdf');

    expect($cv['images'])->toBe(['JPEG1', 'JPEG2', 'JPEG3'])
        ->and($cv['pages'])->toBe(5);
});

it('refuses a file that is not a PDF for good', function () {
    Http::fake(['cv.example/*' => Http::response("PK\x03\x04word", 200)]);

    (new CvReader(fakeCvPages(['x'])))->read('https://cv.example/cv.docx');
})->throws(CvUnreadable::class, 'not a PDF');

it('treats a failed download as worth retrying', function () {
    Http::fake(['cv.example/*' => Http::response('', 403)]);

    try {
        (new CvReader(fakeCvPages(['x'])))->read('https://cv.example/expired.pdf');
        $this->fail('Expected the download to fail.');
    } catch (RuntimeException $e) {
        expect($e)->not->toBeInstanceOf(CvUnreadable::class)
            ->and($e->getMessage())->toContain('HTTP 403');
    }
});

it('calls a CV with only a few words a scan', function () {
    expect(CvReader::needsImages("[Page 1]\nJohn Smith"))->toBeTrue()
        ->and(CvReader::needsImages("[Page 1]\n".str_repeat('word ', 120)))->toBeFalse();
});
