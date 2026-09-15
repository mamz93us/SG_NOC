<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveInboxItem;
use App\Services\Archive\ArchiveAccess;
use App\Services\Archive\InboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Capture, from the portal: scans arriving, and scans being filed.
 *
 * Gated by `can_add` membership on at least one archive — a separate question
 * from reading. Somebody who may search the finance archive is not thereby
 * someone who may put documents into it.
 *
 * Every action re-resolves the item through the same ownership check rather than
 * trusting an id from the URL, exactly as the document routes do: an inbox item
 * is an unfiled invoice, and whose inbox it is in is the only thing protecting
 * it.
 *
 * Nothing here reads a page or calls Azure OpenAI. `archive:process-inbox` does
 * that in the background, so an upload returns immediately even when the scan
 * behind it is a 40-page contract.
 */
class InboxController extends Controller
{
    /** php.ini's max_file_uploads on the NOC. */
    private const MAX_FILES = 20;

    /** 50 MB a scan: an A3 colour scan of a long contract, with room spare. */
    private const MAX_KILOBYTES = 51200;

    public function __construct(private InboxService $inbox) {}

    public function index(Request $request): View
    {
        $access = ArchiveAccess::for($request->user());

        return view('archive.inbox', [
            'items' => $this->mine($request)
                ->with(['suggestedArchive', 'archive'])
                ->orderByDesc('id')
                ->paginate(30),
            'archives' => $this->fileableArchives($access),
            'recentlyFiled' => $this->mine($request, ArchiveInboxItem::STATUS_FILED)
                ->with('document')
                ->orderByDesc('filed_at')
                ->limit(5)
                ->get(),
            'maxKilobytes' => self::MAX_KILOBYTES,
            'maxFiles' => self::MAX_FILES,
            'accepted' => InboxService::ACCEPTED,
        ]);
    }

    /**
     * Take uploads into this person's inbox.
     *
     * Each file is reported on individually: a batch where one scan is a
     * spreadsheet and two are duplicates should say exactly that, not fail as a
     * whole and leave somebody guessing which one was the problem.
     */
    public function store(Request $request): RedirectResponse
    {
        $access = ArchiveAccess::for($request->user());

        abort_unless($this->fileableArchives($access)->isNotEmpty(), 403);

        $data = $request->validate([
            'files' => ['required', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
            'archive_id' => ['nullable', 'integer'],
        ], [], ['files' => 'scans', 'files.*' => 'scan']);

        // An archive chosen up front is a hint, and it must still be one this
        // person may add to — otherwise the choice would be a way to put a
        // document into somewhere they cannot reach.
        $archive = null;

        if ($data['archive_id'] ?? null) {
            $archive = $this->fileableArchives($access)->firstWhere('id', (int) $data['archive_id']);

            abort_unless($archive, 404);
        }

        $taken = 0;
        $duplicates = [];
        $errors = [];

        foreach ($request->file('files') as $file) {
            $result = $this->inbox->receiveUpload($file, $request->user(), $archive);

            if ($result['duplicate']) {
                $duplicates[] = basename($file->getClientOriginalName());
            } elseif ($result['error'] !== null) {
                $errors[] = $result['error'];
            } else {
                $taken++;
            }
        }

        $message = [];

        if ($taken > 0) {
            $message[] = $taken === 1
                ? 'One scan is in your inbox. It is read in the background, usually within a minute.'
                : $taken.' scans are in your inbox. They are read in the background, usually within a minute.';
        }

        if ($duplicates !== []) {
            $message[] = 'Already waiting, so not added again: '.implode(', ', $duplicates).'.';
        }

        return redirect()
            ->route('archive.inbox')
            ->with($taken > 0 ? 'status' : 'error', implode(' ', array_merge($message, $errors)));
    }

    /** The filing form: the scan on one side, its fields on the other. */
    public function edit(Request $request, string $id): View
    {
        $access = ArchiveAccess::for($request->user());
        $item = $this->findMine($request, $id);

        $archives = $this->fileableArchives($access);

        // AI's guess, but only if it is still an archive this person may file
        // into — membership can be withdrawn between the reading and the filing.
        $chosen = $request->query('archive')
            ? $archives->firstWhere('id', (int) $request->query('archive'))
            : ($item->ai_archive_id ? $archives->firstWhere('id', $item->ai_archive_id) : null);

        $chosen ??= $archives->count() === 1 ? $archives->first() : null;

        $duplicates = collect();

        if ($chosen) {
            $chosen->load('fields');

            // Shown before saving, not after: the unique fields are invoice
            // numbers, and knowing one is already filed is the whole point.
            foreach ($chosen->fields as $field) {
                if (! $field->is_unique) {
                    continue;
                }

                $suggestion = $item->suggestionFor($field->key);

                if ($suggestion) {
                    $found = $this->inbox->duplicatesOf($chosen, $field, $suggestion['value']);

                    if ($found->isNotEmpty()) {
                        $duplicates[$field->key] = $found;
                    }
                }
            }
        }

        return view('archive.file', [
            'item' => $item,
            'archives' => $archives,
            'archive' => $chosen,
            'duplicates' => $duplicates,
            // Other scans still waiting, so several sheets of one document can be
            // filed together instead of one at a time.
            'alsoWaiting' => $this->mine($request)
                ->whereKeyNot($item->getKey())
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /** File it: this is where an arriving scan becomes a document. */
    public function file(Request $request, string $id): RedirectResponse
    {
        $access = ArchiveAccess::for($request->user());
        $item = $this->findMine($request, $id);

        $data = $request->validate([
            'archive_id' => ['required', 'integer'],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'string', 'max:250'],
            'also' => ['nullable', 'array'],
            'also.*' => ['integer'],
        ]);

        $archive = $this->fileableArchives($access)->firstWhere('id', (int) $data['archive_id']);

        abort_unless($archive, 404);

        // Every extra item re-resolved as this person's own: an id in the form
        // must not reach somebody else's inbox.
        $items = [$item];

        foreach ($data['also'] ?? [] as $alsoId) {
            $also = $this->mine($request)->whereKey($alsoId)->first();

            if ($also && $also->getKey() !== $item->getKey()) {
                $items[] = $also;
            }
        }

        $result = $this->inbox->file($items, $archive, $data['values'] ?? [], $request->user());

        if ($result['document'] === null) {
            return back()->withInput()->with('error', $result['error']);
        }

        return redirect()
            ->route('archive.document', $result['document']->getKey())
            ->with('status', 'Filed into '.$archive->displayName().'.');
    }

    public function discard(Request $request, string $id): RedirectResponse
    {
        $item = $this->findMine($request, $id);

        $this->inbox->discard($item);

        return redirect()->route('archive.inbox')->with('status', 'Discarded.');
    }

    /**
     * The scan itself, inline, so the filing form can show what is being filed.
     *
     * Its own small streamer rather than FileViewer, which works on an
     * archive_files row: an inbox item is one blob on one known disk, and
     * generalising the viewer to cover both would make the filed path harder to
     * read for no gain. A TIFF is offered rather than converted here — the
     * conversion cache is for documents people open repeatedly, not for a scan
     * about to become one.
     */
    public function preview(Request $request, string $id): Response
    {
        $item = $this->findMine($request, $id);

        $disk = Storage::disk($item->disk);

        abort_unless($disk->exists($item->path), 404);

        $inline = $item->isPdf() || $item->isImage();

        return new StreamedResponse(function () use ($disk, $item) {
            $stream = $disk->readStream($item->path);

            if ($stream) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            // Explicit, because the app sends X-Content-Type-Options: nosniff —
            // a missing type means the browser refuses to render the PDF rather
            // than guessing.
            'Content-Type' => $this->contentType($item),
            'Content-Length' => (string) ($item->size ?: $disk->size($item->path)),
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.$this->safeName($item->displayName()).'"',
        ]);
    }

    // ─── Internals ───────────────────────────────────────────────

    /**
     * This person's own waiting items.
     *
     * An item belongs to somebody either directly or through an archive's shared
     * inbox, and a shared one is reachable only by people who may add to that
     * archive — which is what stops a departmental scan address becoming a way
     * to read documents.
     */
    private function mine(Request $request, string $status = ArchiveInboxItem::STATUS_WAITING)
    {
        $access = ArchiveAccess::for($request->user());
        $archiveIds = $access->archiveIds('can_add');
        $userId = $request->user()?->getKey();

        return ArchiveInboxItem::query()
            ->where('status', $status)
            ->where(function ($query) use ($userId, $archiveIds) {
                $query->where('user_id', $userId);

                if ($archiveIds !== []) {
                    $query->orWhereIn('archive_id', $archiveIds);
                }
            });
    }

    private function findMine(Request $request, string $id): ArchiveInboxItem
    {
        $item = $this->mine($request)->whereKey($id)->first();

        // 404 rather than 403: that an item exists is itself information.
        abort_unless($item, 404);

        return $item;
    }

    /**
     * Archives this person may file into.
     *
     * Native and readable only. A mirrored archive still belongs to ArcMate, and
     * offering it here would let somebody create a document ArcMate never hears
     * about.
     *
     * @return \Illuminate\Support\Collection<int,Archive>
     */
    private function fileableArchives(ArchiveAccess $access)
    {
        return $access->archives('can_add')
            ->where('mode', Archive::MODE_NATIVE)
            ->readable()
            ->with('fields')
            ->get();
    }

    private function contentType(ArchiveInboxItem $item): string
    {
        return match ($item->extension()) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'tif', 'tiff' => 'image/tiff',
            default => $item->mime ?: 'application/octet-stream',
        };
    }

    /** Anything unusual dropped rather than escaped: this goes in a header. */
    private function safeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\w.\- ]+/u', '_', $name) ?? '') ?? '');

        return $name === '' ? 'scan' : mb_substr($name, 0, 120);
    }
}
