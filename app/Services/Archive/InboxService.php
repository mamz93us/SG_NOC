<?php

namespace App\Services\Archive;

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveDocumentValue;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveFileText;
use App\Models\Archive\ArchiveInboxItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Capture: a scan arriving, and a scan becoming a document.
 *
 * Two halves, and the split is the point. Receiving must be immediate and must
 * never fail for a reason the sender can do anything about — an MFP at 3am has
 * nobody to tell. Filing is a judgement (which archive, which invoice number)
 * and waits for a person, with AI having proposed what it can in between.
 *
 * Everything lives on the `azure_archive` disk: `inbox/` while it waits,
 * `documents/` once filed. One container, so filing is a MOVE rather than a copy
 * between two stores, and nothing is ever written to a local disk — which is what
 * keeps this clear of the trap the knowledge-PDF import hit, where PHP-FPM writes
 * as www-data and the scheduler reads as azureuser.
 */
class InboxService
{
    /** What a scanner or a person may send. Anything else is refused on arrival. */
    public const ACCEPTED = ['pdf', 'tif', 'tiff', 'jpg', 'jpeg', 'png'];

    public function __construct(private ?ArchiveTransferService $transfers = null)
    {
        $this->transfers ??= new ArchiveTransferService;
    }

    // ─── Arriving ────────────────────────────────────────────────

    /**
     * Take an uploaded file into somebody's inbox.
     *
     * The bytes go to Azure first and the row is written after: a row pointing at
     * a blob that does not exist is an item nobody can ever file or discard,
     * whereas a blob with no row is invisible and cleaned up by the orphan sweep.
     *
     * @return array{item:?ArchiveInboxItem, error:?string, duplicate:bool}
     */
    public function receiveUpload(UploadedFile $file, User $owner, ?Archive $archive = null): array
    {
        $name = mb_substr(basename($file->getClientOriginalName()), 0, 255);
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ACCEPTED, true)) {
            return ['item' => null, 'error' => $name.' is not a scan (PDF, TIFF, JPEG or PNG).', 'duplicate' => false];
        }

        $sha256 = hash_file('sha256', $file->getRealPath());

        // Looked up rather than left to a unique index: the same scan arriving
        // twice is an ordinary event (an MFP retrying, a folder swept twice), and
        // it should be reported as "already here", not raised as a database
        // error in front of whoever is uploading.
        $existing = $this->existing($sha256, $owner, $archive);

        if ($existing) {
            return ['item' => $existing, 'error' => null, 'duplicate' => true];
        }

        try {
            $path = $this->store($file, $extension);
        } catch (\Throwable $e) {
            Log::warning('[archive] inbox upload failed for '.$name.': '.$e->getMessage());

            return ['item' => null, 'error' => $name.' could not be stored. Try again.', 'duplicate' => false];
        }

        $item = ArchiveInboxItem::create([
            'user_id' => $owner->getKey(),
            'archive_id' => $archive?->getKey(),
            'source' => ArchiveInboxItem::SOURCE_UPLOAD,
            'source_detail' => $owner->name,
            'path' => $path,
            'original_name' => $name,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'sha256' => $sha256,
            // Queued for reading straight away, but a person may file it before
            // the worker ever gets to it — which for one urgent invoice is
            // exactly the right thing to do.
            'ai_status' => ArchiveInboxItem::AI_QUEUED,
            'received_at' => now(),
        ]);

        return ['item' => $item, 'error' => null, 'duplicate' => false];
    }

    /**
     * An item already holding these bytes, if any.
     *
     * Scoped to the same destination, because the same invoice legitimately
     * arriving for two different archives is two items. A discarded one does not
     * count — discarding then re-sending is how somebody retries.
     */
    private function existing(string $sha256, User $owner, ?Archive $archive): ?ArchiveInboxItem
    {
        return ArchiveInboxItem::query()
            ->where('sha256', $sha256)
            ->where('status', ArchiveInboxItem::STATUS_WAITING)
            ->where(function ($query) use ($owner, $archive) {
                $query->where('user_id', $owner->getKey());

                if ($archive) {
                    $query->orWhere('archive_id', $archive->getKey());
                }
            })
            ->first();
    }

    /** Stream the upload to `inbox/`, under a name of ours rather than the client's. */
    private function store(UploadedFile $file, string $extension): string
    {
        // A generated name: two uploads of "scan.pdf" must not collide, and a
        // client-supplied filename is attacker-controlled.
        $path = 'inbox/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$extension;

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $written = Storage::disk(ArchiveFile::DISK_AZURE)->writeStream($path, $stream);

            if ($written === false) {
                throw new \RuntimeException('The storage account refused the write.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    // ─── Becoming a document ─────────────────────────────────────

    /**
     * File one or more inbox items as a single document.
     *
     * Several items become one document on purpose: a scanner that produces a
     * file per sheet, or an invoice with its delivery note behind it, is one
     * document in the archive and filing them separately is what people
     * currently spend their time undoing.
     *
     * The database work is one transaction, and the blobs are moved only after it
     * commits. Either order can fail; this one fails safely. A blob left in
     * `inbox/` is a duplicate the orphan sweep can find, whereas a committed
     * document whose files were never moved is a document that does not open.
     *
     * @param  array<int,ArchiveInboxItem>  $items
     * @param  array<string,string>  $values  field key => value as typed
     * @return array{document:?ArchiveDocument, error:?string}
     */
    public function file(array $items, Archive $archive, array $values, User $filedBy): array
    {
        $items = array_values(array_filter($items, fn (ArchiveInboxItem $item) => $item->isWaiting()));

        if ($items === []) {
            return ['document' => null, 'error' => 'Those items have already been filed or discarded.'];
        }

        if (! $archive->acceptsNewDocuments()) {
            // A mirrored archive is still ArcMate's to write to, and a read-only
            // one is closed. Filing into either would create a document ArcMate
            // never hears about.
            return ['document' => null, 'error' => $archive->displayName().' does not accept new documents.'];
        }

        $fields = $archive->fields()->get();
        $missing = $this->missingRequired($fields, $values);

        if ($missing !== []) {
            return ['document' => null, 'error' => 'Still needed: '.implode(', ', $missing).'.'];
        }

        try {
            /** @var array{0:ArchiveDocument, 1:array<int,array{0:ArchiveFile, 1:string}>} $result */
            $result = DB::transaction(function () use ($items, $archive, $values, $fields, $filedBy) {
                $document = ArchiveDocument::create([
                    'archive_id' => $archive->getKey(),
                    'status' => ArchiveDocument::STATUS_ACTIVE,
                    // The capture time of what was scanned, not the filing time:
                    // somebody filing last week's invoices must not have them all
                    // land under today.
                    'captured_at' => $this->capturedAt($items),
                    'created_by_user_id' => $filedBy->getKey(),
                    'created_by_name' => $filedBy->name,
                    'file_count' => count($items),
                    'page_count' => array_sum(array_map(fn (ArchiveInboxItem $item) => (int) $item->pages, $items)),
                ]);

                foreach ($fields as $field) {
                    $value = trim((string) ($values[$field->key] ?? ''));

                    if ($value === '') {
                        continue;
                    }

                    ArchiveDocumentValue::create([
                        'archive_document_id' => $document->getKey(),
                        'archive_field_id' => $field->getKey(),
                        'value_text' => mb_substr($value, 0, 250),
                        'value_date' => $field->isDate() ? $this->asDate($value) : null,
                        'value_number' => $field->isNumber() && is_numeric($value) ? $value : null,
                        // Typed here by a person, so the ArcMate sync will never
                        // overwrite it — see ArchiveSyncService.
                        'source' => ArchiveDocumentValue::SOURCE_PERSON,
                        'set_by_user_id' => $filedBy->getKey(),
                    ]);
                }

                $moves = [];

                foreach ($items as $position => $item) {
                    $file = ArchiveFile::create([
                        'archive_id' => $archive->getKey(),
                        'archive_document_id' => $document->getKey(),
                        'position' => $position,
                        'original_name' => $item->displayName(),
                        // Born in Azure. Nothing to transfer, so the transfer
                        // worker never looks at it.
                        'disk' => ArchiveFile::DISK_AZURE,
                        // Replaced below with its filed location; set now so the
                        // row is never momentarily without a path.
                        'path' => $item->path,
                        'mime' => $item->mime,
                        'size' => $item->size,
                        'page_count' => $item->pages,
                        'sha256' => $item->sha256,
                        'transferred_at' => now(),
                    ]);

                    // The same layout the transfer worker writes, from the same
                    // method, so a document filed here and one moved from ArcMate
                    // are indistinguishable afterwards.
                    $file->setRelation('archive', $archive);
                    $file->setRelation('document', $document);
                    $target = $this->transfers->targetPath($file);

                    $file->forceFill(['path' => $target])->save();

                    $this->carryText($file, $item);

                    $item->forceFill([
                        'status' => ArchiveInboxItem::STATUS_FILED,
                        'archive_document_id' => $document->getKey(),
                        'filed_by_user_id' => $filedBy->getKey(),
                        'filed_at' => now(),
                        // Carried across now, so the copy on the item goes.
                        'ai_text' => null,
                    ])->save();

                    $moves[] = [$file, $item->path];
                }

                return [$document, $moves];
            });
        } catch (\Throwable $e) {
            Log::error('[archive] filing failed: '.$e->getMessage());

            return ['document' => null, 'error' => 'Filing failed and nothing was changed. '.$e->getMessage()];
        }

        [$document, $moves] = $result;

        $this->moveBlobs($moves);
        $this->recount($archive);

        return ['document' => $document, 'error' => null];
    }

    /**
     * Put the text AI already read onto the document's own file.
     *
     * A scan is read in the inbox to fill in the filing form, and that reading
     * is charged for. Until this existed the text was then thrown away: the
     * filed document had no text at all, so the first question asked about it —
     * or the first read batch covering it — read and paid for exactly the same
     * pages a second time.
     *
     * Each page keeps the source it actually came from, so a page lifted free
     * off the PDF's own text layer is not recorded as one AI was paid to read.
     *
     * The file is stamped the way PageReader stamps one, and for the same
     * reason: `done` only when every page has text. Capture reads at most ten
     * pages, so a longer scan stays `pending` and a later batch reads the rest —
     * and only the rest, because read() skips a page that already has text.
     */
    private function carryText(ArchiveFile $file, ArchiveInboxItem $item): void
    {
        $pages = (array) ($item->ai_text ?? []);

        if ($pages === []) {
            return;
        }

        $stored = 0;

        foreach ($pages as $page => $read) {
            $text = trim((string) ($read['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            ArchiveFileText::create([
                'archive_file_id' => $file->getKey(),
                'page' => (int) $page,
                'text' => $text,
                'source' => $read['source'] ?? ArchiveFileText::SOURCE_AI,
                'read_at' => now(),
            ]);

            $stored++;
        }

        if ($stored === 0) {
            return;
        }

        $pageCount = (int) ($file->page_count ?: $item->pages);

        $file->forceFill([
            'pages_read' => $stored,
            'page_count' => $pageCount ?: $stored,
            'text_status' => $pageCount > 0 && $stored >= $pageCount
                ? ArchiveFile::TEXT_DONE
                : ArchiveFile::TEXT_PENDING,
            'text_read_at' => now(),
        ])->save();
    }

    /**
     * Move each filed blob from `inbox/` to where its row now says it is.
     *
     * After the commit, deliberately. A failure here leaves the bytes at their
     * old path with the row pointing at the new one, so it is logged loudly and
     * the file reads as missing in the viewer — recoverable, and visible. The
     * alternative (moving first) risks a document that has no files at all.
     *
     * @param  array<int,array{0:ArchiveFile, 1:string}>  $moves
     */
    private function moveBlobs(array $moves): void
    {
        $disk = Storage::disk(ArchiveFile::DISK_AZURE);

        foreach ($moves as [$file, $from]) {
            if ($from === $file->path) {
                continue;
            }

            try {
                if ($disk->exists($file->path)) {
                    // Already there: a retried filing, or a move that succeeded
                    // after its error was logged. Drop the inbox copy.
                    $disk->delete($from);

                    continue;
                }

                $disk->move($from, $file->path);
            } catch (\Throwable $e) {
                Log::error(
                    '[archive] filed file '.$file->getKey().' was not moved from '.$from
                    .' to '.$file->path.': '.$e->getMessage()
                );
            }
        }
    }

    /** Discard an item: the row is kept, the blob is not. */
    public function discard(ArchiveInboxItem $item): void
    {
        // The text goes with the bytes: a discarded scan will never become a
        // document, so keeping what was read off it serves nothing.
        $item->forceFill([
            'status' => ArchiveInboxItem::STATUS_DISCARDED,
            'ai_text' => null,
        ])->save();

        try {
            Storage::disk($item->disk)->delete($item->path);
        } catch (\Throwable $e) {
            Log::warning('[archive] discarded inbox blob not deleted: '.$e->getMessage());
        }
    }

    // ─── Internals ───────────────────────────────────────────────

    /**
     * Required fields with nothing in them.
     *
     * @param  \Illuminate\Support\Collection<int,ArchiveField>  $fields
     * @param  array<string,string>  $values
     * @return array<int,string>
     */
    private function missingRequired($fields, array $values): array
    {
        $missing = [];

        foreach ($fields as $field) {
            if ($field->required && trim((string) ($values[$field->key] ?? '')) === '') {
                $missing[] = $field->label();
            }
        }

        return $missing;
    }

    /**
     * Documents already holding this value for a unique field.
     *
     * Not a refusal — a warning shown while filing. ArcMate's own data has
     * duplicate invoice numbers in it (credit notes, re-issues), so blocking
     * would stop legitimate filing; being told is what people actually need.
     *
     * @return \Illuminate\Support\Collection<int,ArchiveDocument>
     */
    public function duplicatesOf(Archive $archive, ArchiveField $field, string $value)
    {
        if (trim($value) === '') {
            return collect();
        }

        return ArchiveDocument::query()
            ->where('archive_id', $archive->getKey())
            ->where('status', ArchiveDocument::STATUS_ACTIVE)
            ->whereHas('values', fn ($query) => $query
                ->where('archive_field_id', $field->getKey())
                ->where('value_text', trim($value)))
            ->limit(5)
            ->get();
    }

    /** The earliest thing being filed, or now if nothing recorded a time. */
    private function capturedAt(array $items): string
    {
        $times = array_filter(array_map(
            fn (ArchiveInboxItem $item) => $item->received_at,
            $items,
        ));

        return $times === []
            ? now()->format('Y-m-d H:i:s')
            : min($times)->format('Y-m-d H:i:s');
    }

    private function asDate(string $value): ?string
    {
        try {
            return \Carbon\CarbonImmutable::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Keep the archive's card figures honest as documents are filed.
     *
     * The model's own method, shared with the sync and the recount task. Counting
     * it here instead is how this drifted: through a relation it missed the
     * soft-delete guard the other two apply, and it never touched byte_total.
     */
    private function recount(Archive $archive): void
    {
        try {
            $archive->refreshCounts();
        } catch (\Throwable $e) {
            Log::warning('[archive] recount after filing failed: '.$e->getMessage());
        }
    }
}
