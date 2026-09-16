<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Archive\ArchiveAccessLog;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveFile;
use App\Services\Archive\ArchiveAccess;
use App\Services\Archive\FileViewer;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opening a document: its index values, its files, and the bytes themselves.
 *
 * Four routes — the page, the inline bytes its viewer frame loads, the
 * download, and the raw-file fallback — and EVERY one of them re-runs the
 * access check from scratch. Not because the previous page was untrustworthy,
 * but because these URLs are shared, forwarded and bookmarked, and a
 * membership can be withdrawn between two clicks. HomeDocumentController does
 * the same thing for the same reason.
 *
 * Every open and every download is recorded in archive_access_logs. These are
 * supplier invoices, contracts and HR files; who looked at one is part of the
 * record.
 */
class DocumentController extends Controller
{
    public function __construct(private FileViewer $viewer) {}

    public function show(Request $request, string $id): View
    {
        $access = ArchiveAccess::for($request->user());
        $document = $access->findDocument($id);

        abort_unless($document, 404);

        $document->load(['archive.fields', 'values.field', 'files']);

        ArchiveAccessLog::record(
            $request->user()?->getKey(),
            $document,
            ArchiveAccessLog::ACTION_VIEW,
            null,
            $request->ip(),
            $request->userAgent(),
        );

        // What the viewer frame opens on. A chosen file wins — that is how the
        // file list switches the preview — but it is looked up within THIS
        // document's files, so a file id from the URL cannot reach another
        // document's scan. Otherwise: the first file that can actually be
        // rendered, rather than simply the first, so a document whose first item
        // is an attached e-mail still opens on its scan.
        $chosen = $request->query('file');

        $primary = ($chosen ? $document->files->firstWhere('id', (int) $chosen) : null)
            ?? $document->files->first(fn (ArchiveFile $file) => $file->isViewable())
            ?? $document->files->first();

        return view('archive.document', [
            'archive' => $document->archive,
            'document' => $document,
            'files' => $document->files,
            'primary' => $primary,
            'missing' => $primary && ! $this->viewer->exists($primary),
            'converterMissing' => $primary && $this->viewer->needsMissingConverter($primary),
            'canEdit' => $access->canOnArchive($document->archive, 'can_edit'),
            // Both halves, because they are granted separately and refused
            // separately: the person needs use-archive-ai, and the archive itself
            // has to have ai_chat switched on (HR is why that switch exists). A
            // panel that renders and then refuses every question is worse than no
            // panel at all.
            'canAsk' => $access->mayUseAi() && (bool) $document->archive?->ai_chat,
        ]);
    }

    /**
     * The bytes, inline, for the viewer frame.
     *
     * Same origin, so this is an ordinary authenticated request and not a hole
     * around the gate. A TIFF arrives here as the PDF it was converted into,
     * because no browser will display the original.
     */
    public function stream(Request $request, string $id, string $fileId): Response
    {
        [$document, $file] = $this->resolve($request, $id, $fileId);

        abort_unless($this->viewer->exists($file), 404);

        return $this->viewer->response($file, download: false);
    }

    /** The original file, as archived — never the converted copy. */
    public function download(Request $request, string $id, string $fileId): Response
    {
        [$document, $file] = $this->resolve($request, $id, $fileId);

        abort_unless($this->viewer->exists($file), 404);

        ArchiveAccessLog::record(
            $request->user()?->getKey(),
            $document,
            ArchiveAccessLog::ACTION_DOWNLOAD,
            $file,
            $request->ip(),
            $request->userAgent(),
        );

        return $this->viewer->response($file, download: true);
    }

    /**
     * Resolve a document and one of its files, or 404.
     *
     * The file is looked up THROUGH the document rather than by its own id, so
     * a file id from the URL can never point at another document's file even if
     * the two rows disagree.
     *
     * @return array{0:ArchiveDocument, 1:ArchiveFile}
     */
    private function resolve(Request $request, string $id, string $fileId): array
    {
        $access = ArchiveAccess::for($request->user());
        $document = $access->findDocument($id);

        abort_unless($document, 404);

        $file = $access->fileOfDocument($document, $fileId);

        abort_unless($file, 404);

        return [$document, $file];
    }
}
