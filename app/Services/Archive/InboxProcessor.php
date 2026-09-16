<?php

namespace App\Services\Archive;

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveAiSettings;
use App\Models\Archive\ArchiveInboxItem;
use App\Models\User;
use App\Services\Ai\PdfPages;
use App\Services\Archive\Ai\ArchiveSpendRefused;
use App\Services\Archive\Ai\FieldExtractor;
use App\Services\Archive\Ai\PageReader;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads an arriving scan and fills in the filing form before anybody opens it.
 *
 * The point is the minute between a scan landing and somebody filing it. In that
 * minute the pages can be counted, read, and turned into proposed field values,
 * so the person filing is checking and correcting rather than transcribing an
 * invoice number off an image.
 *
 * Nothing here decides anything. The suggestions land on the item as suggestions
 * and the filing form marks them as AI's — which archive it belongs in and what
 * the fields say are still answered by a person, because these are invoices.
 *
 * The same FieldExtractor as the fill batches, deliberately. The plan called for
 * a separate DocumentFieldExtractor, but that was written before FieldExtractor
 * existed and the job is identical — same validation, same per-field confidence,
 * same evidence page. Two extractors would be two prompts to keep honest.
 */
class InboxProcessor
{
    /** Pages to read of one arriving scan. Enough for an invoice and its backing. */
    private const MAX_PAGES = 10;

    public function __construct(
        private ?PageReader $reader = null,
        private ?FieldExtractor $extractor = null,
        private ?PdfPages $pdf = null,
        private ?TiffConverter $tiff = null,
    ) {
        $this->reader ??= new PageReader;
        $this->extractor ??= new FieldExtractor;
        $this->pdf ??= new PdfPages;
        $this->tiff ??= new TiffConverter;
    }

    /**
     * Work the queue until the time budget is spent.
     *
     * @param  callable|null  $shouldStop  true when the budget is spent
     * @return array{items:int, read:int, suggested:int, failed:int, reason:?string}
     */
    public function run(int $maxItems = 25, ?callable $shouldStop = null): array
    {
        $shouldStop ??= static fn (): bool => false;
        $stats = ['items' => 0, 'read' => 0, 'suggested' => 0, 'failed' => 0, 'reason' => null];

        for ($n = 0; $n < $maxItems; $n++) {
            if ($shouldStop()) {
                $stats['reason'] = 'Time budget spent; continues next run.';
                break;
            }

            $item = ArchiveInboxItem::query()->needingAi()->orderBy('id')->first();

            if (! $item) {
                break;
            }

            $stats['items']++;

            $done = $this->item($item);

            $stats['read'] += $done['pages'];
            $stats['suggested'] += $done['fields'];

            if ($done['error'] !== null) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * One item: count its pages, read them, propose what its fields say.
     *
     * @return array{pages:int, fields:int, error:?string}
     */
    public function item(ArchiveInboxItem $item): array
    {
        if (! $item->isReadable()) {
            // Not something with pages. Still filed by hand perfectly well, so
            // this is done rather than failed.
            $item->forceFill(['ai_status' => ArchiveInboxItem::AI_DONE])->save();

            return ['pages' => 0, 'fields' => 0, 'error' => null];
        }

        // Marked before the first call, not after: a worker killed mid-read would
        // otherwise leave the item looking untouched and be charged for the same
        // pages again. needingAi() picks `reading` back up, and the attempt
        // counter is what stops it looping for ever.
        $item->forceFill([
            'ai_status' => ArchiveInboxItem::AI_READING,
            'ai_attempts' => (int) $item->ai_attempts + 1,
        ])->save();

        $local = null;

        try {
            $local = $this->localPdf($item);

            if ($local === null) {
                $item->markAiFailed('The scan could not be opened for reading.');

                return ['pages' => 0, 'fields' => 0, 'error' => 'unopenable'];
            }

            $pageCount = $this->pdf->count($local);

            $item->forceFill(['pages' => $pageCount])->save();

            // Page count first and cheaply, because the filing form wants it even
            // when there is no budget to read anything.
            if (! ArchiveAiSettings::get()->withinBudget()) {
                $item->forceFill([
                    'ai_status' => ArchiveInboxItem::AI_QUEUED,
                    'error' => 'Waiting for AI budget.',
                ])->save();

                return ['pages' => 0, 'fields' => 0, 'error' => null];
            }

            [$text, $refused] = $this->read($item, $local, $pageCount);

            // A cap, not a fault. The item goes back in the queue with its
            // attempt given back, because being told "tomorrow" three times must
            // not permanently write off a perfectly good scan.
            if ($refused !== null && trim($text) === '') {
                $item->forceFill([
                    'ai_status' => ArchiveInboxItem::AI_QUEUED,
                    'ai_attempts' => max(0, (int) $item->ai_attempts - 1),
                    'error' => $refused,
                ])->save();

                return ['pages' => 0, 'fields' => 0, 'error' => null];
            }

            if (trim($text) === '') {
                $item->forceFill([
                    'ai_status' => ArchiveInboxItem::AI_DONE,
                    'error' => 'Nothing could be read from the scan.',
                ])->save();

                return ['pages' => 0, 'fields' => 0, 'error' => null];
            }

            $archive = $this->guessArchive($item, $text);
            $fields = $archive ? $archive->fields()->get()->all() : [];
            $suggestions = [];

            if ($fields !== []) {
                $suggestions = $this->extractor->propose($text, $fields, $archive->displayName());
            }

            $item->forceFill([
                'ai_status' => ArchiveInboxItem::AI_DONE,
                'ai_suggestions' => $suggestions ?: null,
                'ai_archive_id' => $archive?->getKey(),
                'ai_confidence' => $archive ? $this->confidenceOf($suggestions) : null,
                'error' => null,
            ])->save();

            return ['pages' => $pageCount, 'fields' => count($suggestions), 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('[archive] inbox item '.$item->getKey().' could not be read: '.$e->getMessage());
            $item->markAiFailed($e->getMessage());

            return ['pages' => 0, 'fields' => 0, 'error' => $e->getMessage()];
        } finally {
            $this->cleanUp($item, $local);
        }
    }

    // ─── Reading ─────────────────────────────────────────────────

    /**
     * The scan's text.
     *
     * Free first, exactly as PageReader does it for a filed file: a PDF made by a
     * "scan to PDF" driver often carries its own text layer, and paying to look at
     * a page whose words are already in the file buys nothing.
     *
     * @return array{0:string, 1:?string} the text, and why reading stopped early
     *                                    if a cap refused it
     */
    private function read(ArchiveInboxItem $item, string $local, int $pageCount): array
    {
        $parts = [];
        $pages = min($pageCount, self::MAX_PAGES);
        $refused = null;

        for ($page = 1; $page <= $pages; $page++) {
            $layer = '';

            try {
                $layer = trim($this->pdf->text($local, $page));
            } catch (\Throwable) {
                // No text layer, or poppler could not read that page. Either way
                // the image is the fallback.
            }

            if (mb_strlen($layer) >= 8) {
                $parts[] = '[page '.$page.']'.PHP_EOL.$layer;

                continue;
            }

            // PageReader's own vision call, on a file that has no archive_files
            // row yet. Reached through its public method rather than duplicated,
            // so there is one reading prompt in this codebase and not two — and
            // it enforces BOTH caps, the month's budget and this person's pages
            // today, rather than leaving the second to a caller that forgot it.
            try {
                $parts[] = '[page '.$page.']'.PHP_EOL.$this->reader->readUnfiledPage(
                    $local,
                    $page,
                    $item->archive_id,
                    $item->user_id,
                );
            } catch (ArchiveSpendRefused $e) {
                // Checked per page rather than once per item: a 10-page scan can
                // cross a cap half way through, and the pages already read stay
                // useful.
                $refused = $e->getMessage();
                break;
            }
        }

        return [trim(implode(PHP_EOL.PHP_EOL, $parts)), $refused];
    }

    // ─── Where does it belong ────────────────────────────────────

    /**
     * Which archive this probably belongs in.
     *
     * Only ever one the item's owner may actually add to: a suggestion the person
     * cannot act on is noise, and suggesting an archive they cannot see would
     * leak that it exists. An item that arrived FOR an archive (a scan address or
     * folder) is not guessed at — it already has its answer.
     */
    private function guessArchive(ArchiveInboxItem $item, string $text): ?Archive
    {
        if ($item->archive_id) {
            return $item->archive;
        }

        $candidates = $this->candidates($item);

        if ($candidates->count() <= 1) {
            return $candidates->first();
        }

        // Deliberately not an AI call. With a handful of candidates, matching the
        // archive's own name against the page beats asking, costs nothing, and is
        // explainable when it gets it wrong. The person filing chooses anyway.
        $haystack = mb_strtolower($text);

        foreach ($candidates as $archive) {
            $name = mb_strtolower(trim((string) $archive->name));

            if ($name !== '' && str_contains($haystack, $name)) {
                return $archive;
            }
        }

        return $candidates->first();
    }

    /**
     * The archives the item's owner may file into.
     *
     * @return \Illuminate\Support\Collection<int,Archive>
     */
    private function candidates(ArchiveInboxItem $item)
    {
        $owner = $item->user_id ? User::find($item->user_id) : null;

        if (! $owner) {
            return collect();
        }

        return ArchiveAccess::for($owner)
            ->archives('can_add')
            ->where('mode', Archive::MODE_NATIVE)
            ->readable()
            ->with('fields')
            ->get();
    }

    /**
     * How sure the whole reading is, as the lowest confidence among the fields.
     *
     * The lowest rather than an average: the filing form uses this to decide
     * whether to lead with the suggestions, and one badly-read invoice number is
     * enough to want a person looking closely.
     *
     * @param  array<string,array{value:string, confidence:int, page:?int}>  $suggestions
     */
    private function confidenceOf(array $suggestions): ?int
    {
        $values = array_map(fn (array $one) => (int) $one['confidence'], $suggestions);

        return $values === [] ? null : max(0, min(100, (int) min($values)));
    }

    // ─── Files ───────────────────────────────────────────────────

    /**
     * A local PDF whose pages can be rendered.
     *
     * poppler reads a file, not a stream, so the blob comes down to the shared
     * archive cache directory — the same place PageReader and FileViewer put
     * theirs, with the 0711/0644 permissions that keep www-data and azureuser
     * both able to read.
     */
    private function localPdf(ArchiveInboxItem $item): ?string
    {
        $source = $this->fetch($item);

        if ($source === null) {
            return null;
        }

        if ($item->isPdf()) {
            return $source;
        }

        // A TIFF or a bare image is rewrapped as PDF, which is what poppler needs
        // and the one converter this host is guaranteed to have.
        return $this->tiff->toPdf($source, 'inbox-'.$item->getKey());
    }

    private function fetch(ArchiveInboxItem $item): ?string
    {
        $extension = $item->extension() ?: 'pdf';
        $target = $this->tiff->cacheDirectory().'/inbox-src-'.$item->getKey().'.'.$extension;

        if (@is_file($target) && @filesize($target) > 0) {
            return $target;
        }

        try {
            $stream = Storage::disk($item->disk)->readStream($item->path);

            if (! $stream) {
                return null;
            }

            $out = @fopen($target, 'wb');

            if (! $out) {
                fclose($stream);

                return null;
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            @chmod($target, 0644);

            return $target;
        } catch (\Throwable $e) {
            Log::warning('[archive] could not fetch inbox item '.$item->getKey().': '.$e->getMessage());

            return null;
        }
    }

    /**
     * Drop the local copies once the item is read.
     *
     * Not left to the cache pruner: an arriving scan is read once and never
     * again, so its temporary copy has no second use — unlike a converted TIFF of
     * a filed document, which the viewer wants every time somebody opens it.
     */
    private function cleanUp(ArchiveInboxItem $item, ?string $local): void
    {
        $directory = $this->tiff->cacheDirectory();

        foreach (glob($directory.'/inbox-src-'.$item->getKey().'.*') ?: [] as $file) {
            @unlink($file);
        }

        if ($local !== null && str_contains($local, 'inbox-'.$item->getKey())) {
            @unlink($local);
        }
    }
}
