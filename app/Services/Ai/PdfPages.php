<?php

namespace App\Services\Ai;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The three things the PDF import needs from a file, from poppler-utils:
 * pdfinfo, pdftotext and pdftoppm, already installed on the NOC.
 *
 * Its own class so PdfKnowledgeImporter can be tested without poppler, which
 * the Windows dev box does not have.
 */
class PdfPages
{
    /**
     * Longest side of a rendered page, in pixels. Azure shrinks a high-detail
     * image to 768 px on its short side before the model sees it, so an A4
     * page arrives at about 768 × 1086 whatever is sent, and anything larger
     * only costs upload time. Capping the long side rather than rendering at a
     * fixed DPI also stops an A0 drawing becoming a 7,000-pixel image.
     */
    private const LONG_SIDE_PX = 1600;

    /** How many strips a portrait page is cut into (strips()). */
    private const STRIPS = 4;

    /** How much each strip overlaps the next, as a share of the page's height. */
    private const STRIP_OVERLAP = 0.05;

    /** The height the page is rendered at before it is cut: an A4 strip is then 1,697 × 690 px. */
    private const STRIP_PAGE_LONG_SIDE_PX = 2400;

    public function count(string $path): int
    {
        $info = $this->run(['pdfinfo', $path], 30);

        if (! preg_match('/^Pages:\s+(\d+)/m', $info, $m)) {
            throw new RuntimeException('Could not read how many pages the PDF has.');
        }

        return (int) $m[1];
    }

    /** The page's embedded text: '' when it has none, as with a scan. */
    public function text(string $path, int $page): string
    {
        return $this->run(['pdftotext', '-enc', 'UTF-8', '-f', (string) $page, '-l', (string) $page, $path, '-'], 60);
    }

    /**
     * The page enlarged in overlapping horizontal strips, top to bottom, as
     * JPEG bytes. Azure shrinks a high-detail image to 768 px on its short side,
     * so a whole portrait page reaches the model 768 px across and small print
     * blurs: the labor law's "لاستراحتهن" came back as "لراحتهم". A strip is as
     * wide as the page and short, so it arrives at up to 1,700 px across. The
     * strips overlap by a couple of lines so no line is cut in two. A landscape
     * page is wide already and is not cut.
     *
     * @return array<int, string>
     */
    public function strips(string $path, int $page): array
    {
        $info = $this->run(['pdfinfo', '-f', (string) $page, '-l', (string) $page, $path], 30);

        if (! preg_match('/^Page\s+'.$page.'\s+size:\s+([\d.]+)\s+x\s+([\d.]+)/m', $info, $size)) {
            return [];
        }

        [$width, $height] = [(float) $size[1], (float) $size[2]];

        if (preg_match('/^Page\s+'.$page.'\s+rot:\s+(90|270)\b/m', $info)) {
            [$width, $height] = [$height, $width];
        }

        if ($width <= 0 || $height <= $width) {
            return [];
        }

        $dpi = self::STRIP_PAGE_LONG_SIDE_PX / ($height / 72);
        $pixelsWide = (int) round($width / 72 * $dpi);
        $pixelsHigh = (int) round($height / 72 * $dpi);
        $stripHigh = (int) ceil($pixelsHigh * (1 + self::STRIP_OVERLAP * (self::STRIPS - 1)) / self::STRIPS);
        $step = intdiv($pixelsHigh - $stripHigh, self::STRIPS - 1);

        $strips = [];

        for ($n = 0; $n < self::STRIPS; $n++) {
            $top = $n === self::STRIPS - 1 ? $pixelsHigh - $stripHigh : $n * $step;

            $strips[] = $this->run([
                'pdftoppm', '-f', (string) $page, '-l', (string) $page, '-singlefile',
                '-r', number_format($dpi, 2, '.', ''),
                '-x', '0', '-y', (string) $top, '-W', (string) $pixelsWide, '-H', (string) $stripHigh,
                '-jpeg', '-jpegopt', 'quality=85', $path,
            ], 120);
        }

        return array_values(array_filter($strips, fn (string $jpeg) => $jpeg !== ''));
    }

    /** The page as JPEG bytes. */
    public function image(string $path, int $page): string
    {
        $jpeg = $this->run([
            'pdftoppm', '-f', (string) $page, '-l', (string) $page, '-singlefile',
            '-jpeg', '-jpegopt', 'quality=85', '-scale-to', (string) self::LONG_SIDE_PX, $path,
        ], 120);

        if ($jpeg === '') {
            throw new RuntimeException("Page {$page} could not be rendered.");
        }

        return $jpeg;
    }

    /** @param  array<int,string>  $command */
    private function run(array $command, int $timeoutSeconds): string
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(self::explain($command[0], $process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /** poppler's stderr as a sentence an admin can act on. */
    public static function explain(string $tool, string $stderr): string
    {
        if (stripos($stderr, 'password') !== false) {
            return 'The PDF is password-protected. Save a copy without the password and upload that.';
        }

        if (stripos($stderr, 'Permission denied') !== false) {
            return 'The import worker is not allowed to read the uploaded file (permission denied). That is a server permissions problem, not the PDF: fix it, then Retry.';
        }

        if (preg_match('/May not be a PDF|Couldn\'t read xref|trailer dictionary/i', $stderr)) {
            return 'The file could not be read as a PDF — it may be damaged.';
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $stderr) ?: [])));

        return $lines === [] ? "{$tool} failed." : "{$tool} failed: ".mb_substr(end($lines), 0, 200);
    }
}
