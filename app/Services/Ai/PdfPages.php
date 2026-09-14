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

        if (preg_match('/May not be a PDF|Couldn\'t read xref|trailer dictionary/i', $stderr)) {
            return 'The file could not be read as a PDF — it may be damaged.';
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $stderr) ?: [])));

        return $lines === [] ? "{$tool} failed." : "{$tool} failed: ".mb_substr(end($lines), 0, 200);
    }
}
