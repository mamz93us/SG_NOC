<?php

namespace App\Services\Recruitment;

use App\Services\Ai\PdfPages;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads one applicant's CV and reads it.
 *
 * Teamtailor serves every CV as a PDF on a signed S3 link (the API check on
 * 2026-09-15 found PDFs for both `resume` and `original-resume`), so text comes
 * from poppler's pdftotext. A scanned CV has no text layer: its first pages are
 * then rendered as images for the model to read instead.
 *
 * The file is only ever a temp file, deleted before read() returns.
 */
class CvReader
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    /** CVs run one to four pages; past this the rest is certificates and references. */
    public const MAX_PAGES = 8;

    /** Pages sent as images when a CV has no text layer. */
    public const IMAGE_PAGES = 3;

    /** Less text than this (spaces aside) and the CV is treated as a scan. */
    public const MIN_TEXT_CHARS = 400;

    public function __construct(private PdfPages $pdf) {}

    /**
     * @return array{text: string, pages: int, images: list<string>}
     *
     * @throws CvUnreadable when the file can never be read
     * @throws RuntimeException when the download failed and may work later
     */
    public function read(string $url): array
    {
        try {
            $response = Http::timeout(60)->get($url);
        } catch (\Throwable $e) {
            throw new RuntimeException('The CV could not be downloaded: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('The CV could not be downloaded (HTTP '.$response->status().').');
        }

        $bytes = $response->body();

        if (strlen($bytes) > self::MAX_BYTES) {
            throw new CvUnreadable('The CV is larger than 15 MB.');
        }

        if (! str_starts_with($bytes, '%PDF')) {
            throw new CvUnreadable('The CV is not a PDF, so it cannot be read.');
        }

        $path = tempnam(sys_get_temp_dir(), 'rcv_');
        file_put_contents($path, $bytes);

        try {
            return $this->readFile($path);
        } catch (CvUnreadable $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // poppler failing on a downloaded file will fail the same way next time.
            throw new CvUnreadable($e->getMessage(), 0, $e);
        } finally {
            @unlink($path);
        }
    }

    /** @return array{text: string, pages: int, images: list<string>} */
    private function readFile(string $path): array
    {
        $count = $this->pdf->count($path);

        if ($count < 1) {
            throw new CvUnreadable('The CV has no pages.');
        }

        $pages = min($count, self::MAX_PAGES);
        $text = '';

        for ($page = 1; $page <= $pages; $page++) {
            $pageText = trim($this->pdf->text($path, $page));

            if ($pageText !== '') {
                $text .= ($text === '' ? '' : "\n\n")."[Page {$page}]\n".$pageText;
            }
        }

        $images = [];

        if (self::needsImages($text)) {
            for ($page = 1; $page <= min($pages, self::IMAGE_PAGES); $page++) {
                $images[] = $this->pdf->image($path, $page);
            }
        }

        return ['text' => $text, 'pages' => $count, 'images' => $images];
    }

    public static function needsImages(string $text): bool
    {
        return mb_strlen((string) preg_replace('/\[Page \d+\]|\s+/u', '', $text)) < self::MIN_TEXT_CHARS;
    }
}
