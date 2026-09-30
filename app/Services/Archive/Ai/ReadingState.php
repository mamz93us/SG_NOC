<?php

namespace App\Services\Archive\Ai;

use App\Models\Archive\ArchiveFile;

/**
 * How far AI has read a file, or all of a document's files, in one word and
 * a flag. Pure: it is handed the files' text_status / pages_read / page_count
 * (PageReader stamps those) and reads nothing else, so the document list —
 * fifty documents at a time from one query — and the document page say it
 * the same way.
 *
 * A file PageReader marked unreadable (an attached Outlook e-mail, an
 * encrypted scan) is left out rather than counted as unread: nothing will
 * ever read it, and "partly read" for ever would be a flag nobody can clear.
 */
final class ReadingState
{
    public const DONE = 'done';

    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    public const NONE = 'none';

    public function __construct(
        public readonly string $state,
        public readonly int $pagesRead = 0,
        // 0 when a file's page count is not known yet: ArcMate never recorded
        // one, and PageReader learns it only when it opens the file.
        public readonly int $pageCount = 0,
    ) {}

    /**
     * @param  iterable<ArchiveFile|object|array<string, mixed>>  $files
     */
    public static function of(iterable $files): self
    {
        $readable = 0;
        $done = 0;
        $failed = 0;
        $pagesRead = 0;
        $pageCount = 0;
        $countKnown = true;

        foreach ($files as $file) {
            $status = (string) (data_get($file, 'text_status') ?: ArchiveFile::TEXT_NONE);

            if ($status === ArchiveFile::TEXT_UNREADABLE) {
                continue;
            }

            $readable++;
            $read = (int) data_get($file, 'pages_read');
            $pages = (int) data_get($file, 'page_count');
            $pagesRead += $read;

            if ($status === ArchiveFile::TEXT_DONE) {
                $done++;
                $pageCount += max($pages, $read);
            } elseif ($pages > 0) {
                $pageCount += $pages;
            } else {
                $countKnown = false;
            }

            $failed += (int) ($status === ArchiveFile::TEXT_FAILED);
        }

        $state = match (true) {
            $readable > 0 && $done === $readable => self::DONE,
            $pagesRead > 0 => self::PARTIAL,
            $failed > 0 => self::FAILED,
            default => self::NONE,
        };

        return new self($state, $pagesRead, $countKnown ? $pageCount : 0);
    }

    /** Whether there is any AI text to show or search. */
    public function hasText(): bool
    {
        return $this->pagesRead > 0;
    }

    public function label(): string
    {
        return match ($this->state) {
            self::DONE => 'Read by AI',
            self::PARTIAL => $this->pageCount > $this->pagesRead
                ? "AI read {$this->pagesRead} of {$this->pageCount} pages"
                : "AI read {$this->pagesRead} ".($this->pagesRead === 1 ? 'page' : 'pages').' so far',
            self::FAILED => 'AI could not read it',
            default => 'Not read by AI yet',
        };
    }

    /** The longer sentence for a tooltip. */
    public function detail(): string
    {
        return match ($this->state) {
            self::DONE => 'Every page has been read ('.$this->pagesRead.' '.($this->pagesRead === 1 ? 'page' : 'pages').'). Its words can be searched and asked about.',
            self::PARTIAL => 'Some pages have been read; the rest are read by the next AI batch, or when somebody asks about this document.',
            self::FAILED => 'The last attempt to read it failed. The scan itself is unaffected.',
            default => 'No page has been read yet, so a word search cannot find it.',
        };
    }

    public function icon(): string
    {
        return match ($this->state) {
            self::DONE => 'bi-check2-circle',
            self::PARTIAL => 'bi-hourglass-split',
            self::FAILED => 'bi-exclamation-triangle',
            default => 'bi-dash-circle',
        };
    }

    public function cssClass(): string
    {
        return match ($this->state) {
            self::DONE => 'text-success',
            self::PARTIAL => 'text-warning-emphasis',
            self::FAILED => 'text-danger',
            default => 'arc-muted',
        };
    }
}
