<?php

namespace App\Services\Archive;

use App\Models\Archive\ArchiveFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a stored file into something a browser will show.
 *
 * Two storage shapes have to look identical to the rest of the app, because a
 * file moves from one to the other mid-life without anything else noticing:
 *
 *   disk `arcmate`       an absolute path on the read-only cifs mount
 *   disk `azure_archive` a blob, once the transfer worker has verified a copy
 *
 * A local file is served as a BinaryFileResponse so the browser gets range
 * requests — a PDF viewer asks for the last bytes first, and without ranges it
 * downloads whole megabytes before showing page one.
 *
 * TIFFs are the awkward case: browsers cannot display them, and about 35 GB of
 * the archive is multi-page G4 TIFF. They are converted to PDF with tiff2pdf on
 * first view and kept in a small cache, because converting on every view of a
 * 3-page scan is work nobody needs done twice.
 */
class FileViewer
{
    public function __construct(private ?TiffConverter $tiff = null)
    {
        $this->tiff ??= new TiffConverter;
    }

    /** Whether the bytes are actually there. */
    public function exists(ArchiveFile $file): bool
    {
        if ($file->isOnArcMate()) {
            return $file->path !== '' && @is_file($file->path);
        }

        try {
            return Storage::disk($file->disk)->exists($file->path);
        } catch (\Throwable $e) {
            Log::warning('[archive] storage check failed for file '.$file->getKey().': '.$e->getMessage());

            return false;
        }
    }

    /**
     * The bytes, inline for the viewer or as a download.
     *
     * Content-Type is always explicit. The app sends X-Content-Type-Options:
     * nosniff, so a missing or wrong type means the browser refuses to render
     * the PDF rather than quietly guessing at it.
     */
    public function response(ArchiveFile $file, bool $download = false): Response
    {
        $disposition = $download
            ? \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT
            : \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE;

        // A TIFF is shown as the PDF it was converted into; a download still
        // gets the original file, because that is what was archived.
        if (! $download && $file->isTiff()) {
            $converted = $this->viewablePath($file);

            if ($converted !== null) {
                return $this->localResponse($converted, 'application/pdf', $disposition, $file->downloadName().'.pdf');
            }
        }

        if ($file->isOnArcMate()) {
            return $this->localResponse($file->path, $file->contentType(), $disposition, $file->downloadName());
        }

        return $this->streamedResponse($file, $disposition);
    }

    /**
     * A path the browser can render, converting a TIFF if need be.
     *
     * Null when there is nothing to show — the file is missing, or tiff2pdf is
     * not installed on this host, which is a deployment fact the caller should
     * report rather than a broken image.
     */
    public function viewablePath(ArchiveFile $file): ?string
    {
        if (! $file->isTiff()) {
            return $file->isOnArcMate() && $this->exists($file) ? $file->path : null;
        }

        $source = $this->localCopy($file);

        return $source === null ? null : $this->tiff->toPdf($source, $this->cacheKey($file));
    }

    /**
     * Whether this file can be converted for viewing but the tool is missing,
     * so the page can say so instead of showing an empty frame.
     */
    public function needsMissingConverter(ArchiveFile $file): bool
    {
        return $file->isTiff() && ! $this->tiff->available();
    }

    // ─── Internals ───────────────────────────────────────────────

    /**
     * A path on this host for the file's bytes.
     *
     * Already local on the mount; pulled down to the cache directory when it
     * lives in Azure, because tiff2pdf reads a file, not a stream.
     */
    private function localCopy(ArchiveFile $file): ?string
    {
        if ($file->isOnArcMate()) {
            return $this->exists($file) ? $file->path : null;
        }

        if (! $this->exists($file)) {
            return null;
        }

        $target = $this->tiff->cacheDirectory().'/src-'.$this->cacheKey($file).'.'.$file->extension();

        if (@is_file($target)) {
            return $target;
        }

        try {
            $stream = Storage::disk($file->disk)->readStream($file->path);

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
            Log::warning('[archive] could not fetch file '.$file->getKey().' for conversion: '.$e->getMessage());

            return null;
        }
    }

    private function localResponse(string $path, string $contentType, string $disposition, string $name): Response
    {
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $contentType);
        $response->setContentDisposition($disposition, $this->safeName($name));

        return $response;
    }

    private function streamedResponse(ArchiveFile $file, string $disposition): Response
    {
        $disk = Storage::disk($file->disk);
        $path = $file->path;

        return new StreamedResponse(function () use ($disk, $path) {
            $stream = $disk->readStream($path);

            if ($stream) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $file->contentType(),
            'Content-Length' => (string) ($file->size ?: $disk->size($path)),
            'Content-Disposition' => $disposition.'; filename="'.$this->safeName($file->downloadName()).'"',
        ]);
    }

    /** Stable per file, so a converted TIFF is found again next time. */
    private function cacheKey(ArchiveFile $file): string
    {
        return sha1($file->getKey().'|'.$file->path);
    }

    /**
     * A filename safe to put in a header.
     *
     * ArcMate's original names come off a 2005 system and include quotes,
     * newlines and Arabic; anything outside a conservative set is dropped
     * rather than escaped, because a header injection here would be served to
     * the browser.
     */
    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? 'document';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return $name === '' ? 'document' : mb_substr($name, 0, 120);
    }
}
