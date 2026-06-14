<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DownloadFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Download Center admin. Files are streamed straight to the azure_downloads disk
 * on direct upload; URL fetches are queued as `pending` rows and picked up by the
 * downloads:fetch-remote command (no queue worker in prod). Everything is served
 * back through auth-gated NOC streams — Azure's adapter has no temporaryUrl(), so
 * the NOC proxies all bytes, exactly like the offboarding/certificate downloads.
 */
class DownloadCenterController extends Controller
{
    /** Max direct-upload size in KB (2 GB). */
    private const MAX_UPLOAD_KB = 2_097_152;

    public function index(Request $request)
    {
        $query = DownloadFile::with('uploader')->latest();

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('original_filename', 'like', "%{$search}%")
                    ->orWhere('source_url', 'like', "%{$search}%");
            });
        }

        $files = $query->paginate(20)->withQueryString();

        return view('admin.downloads.index', compact('files'));
    }

    /**
     * Direct upload → stream to Azure. Returns JSON for XHR (so the page can show
     * a progress bar and insert the row live) or redirects for a plain form post.
     */
    public function storeUpload(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'file' => 'required|file|max:'.self::MAX_UPLOAD_KB,
        ]);

        $file = $request->file('file');
        $original = $file->getClientOriginalName();
        $blobPath = Str::uuid().'/'.$original;

        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            return $this->fail($request, 'Could not read the uploaded file.');
        }

        try {
            $ok = Storage::disk(DownloadFile::DISK)->writeStream($blobPath, $stream);
        } catch (\Throwable $e) {
            return $this->fail($request, 'Upload to storage failed: '.$e->getMessage());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($ok === false) {
            return $this->fail($request, 'Upload to storage failed.');
        }

        $download = DownloadFile::create([
            'title' => $validated['title'] ?: pathinfo($original, PATHINFO_FILENAME),
            'original_filename' => $original,
            'disk' => DownloadFile::DISK,
            'azure_path' => $blobPath,
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
            'sha256' => @hash_file('sha256', $file->getRealPath()) ?: null,
            'source' => DownloadFile::SOURCE_UPLOAD,
            'status' => DownloadFile::STATUS_STORED,
            'uploaded_by' => Auth::id(),
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'id' => $download->id, 'status' => $download->status]);
        }

        return redirect()->route('admin.downloads.index')
            ->with('success', "Uploaded “{$download->title}”.");
    }

    /**
     * Queue a remote URL for server-side fetch. The downloads:fetch-remote command
     * streams it to Azure on the next scheduler tick (or run it manually).
     */
    public function storeUrl(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'source_url' => 'required|url|max:2048',
        ]);

        $name = basename(parse_url($validated['source_url'], PHP_URL_PATH) ?: '') ?: 'remote-file';

        $download = DownloadFile::create([
            'title' => $validated['title'] ?: $name,
            'original_filename' => $name,
            'disk' => DownloadFile::DISK,
            'source' => DownloadFile::SOURCE_URL,
            'source_url' => $validated['source_url'],
            'status' => DownloadFile::STATUS_PENDING,
            'uploaded_by' => Auth::id(),
        ]);

        return redirect()->route('admin.downloads.index')
            ->with('success', "Queued “{$download->title}” — it will fetch shortly.");
    }

    /**
     * Lightweight JSON status for the index page's poller (ingest progress + the
     * share state) so rows update without a reload.
     */
    /** Requeue a failed (or stuck) URL fetch so the next worker tick retries it. */
    public function retry(DownloadFile $download)
    {
        abort_unless($download->source === DownloadFile::SOURCE_URL, 422, 'Only URL fetches can be retried.');

        $download->update(['status' => DownloadFile::STATUS_PENDING, 'error' => null]);

        return back()->with('success', "Requeued “{$download->title}” for fetch.");
    }

    public function status(DownloadFile $download): JsonResponse
    {
        $total = $download->download_total_bytes;
        $received = $download->download_received_bytes;
        $uploading = $download->status === DownloadFile::STATUS_FETCHING
            && $total > 0 && $received >= $total;

        return response()->json([
            'id' => $download->id,
            'status' => $download->status,
            'size' => $download->size,
            'human_size' => $download->humanSize(),
            'error' => $download->error,
            'download_count' => $download->download_count,
            'public_state' => $download->publicState(),
            // Live ingest progress (URL fetches).
            'received_bytes' => $received,
            'total_bytes' => $total,
            'percent' => $total > 0 ? min(100, (int) floor($received / $total * 100)) : null,
            'uploading' => $uploading,
        ]);
    }

    /** Auth-gated download — stream the blob from Azure through the NOC. */
    public function downloadFile(DownloadFile $download)
    {
        abort_unless($download->isStored() && $download->azure_path, 404, 'This file is not stored yet.');

        $disk = Storage::disk($download->disk ?: DownloadFile::DISK);
        abort_unless($disk->exists($download->azure_path), 404, 'File no longer exists in storage.');

        $download->forceFill([
            'download_count' => $download->download_count + 1,
            'last_downloaded_at' => now(),
        ])->saveQuietly();

        // Streams the blob (no full-file buffering) — fine for multi-GB files.
        return $disk->download($download->azure_path, $download->suggestedDownloadName());
    }

    /** Enable/disable the public link (and set/clear an optional expiry). */
    public function togglePublic(Request $request, DownloadFile $download)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'expires_at' => 'nullable|date|after:now',
        ]);

        if ($request->boolean('enabled')) {
            abort_unless($download->isStored(), 422, 'File is not stored yet.');
            $download->update([
                'public_enabled' => true,
                'public_token' => $download->public_token ?: Str::random(40),
                'public_expires_at' => $validated['expires_at'] ?? null,
            ]);

            return back()->with('success', 'Public link enabled.');
        }

        // Disable = revoke: drop the token so the old URL dies immediately.
        $download->update([
            'public_enabled' => false,
            'public_token' => null,
            'public_expires_at' => null,
        ]);

        return back()->with('success', 'Public link revoked.');
    }

    /** Regenerate the token, killing the old public URL. */
    public function rotateToken(DownloadFile $download)
    {
        abort_unless($download->public_enabled, 422, 'Public link is not enabled.');

        $download->update(['public_token' => Str::random(40)]);

        return back()->with('success', 'Public link rotated — the old URL no longer works.');
    }

    public function destroy(DownloadFile $download)
    {
        if ($download->azure_path) {
            try {
                Storage::disk($download->disk ?: DownloadFile::DISK)->delete($download->azure_path);
            } catch (\Throwable) {
                // Blob already gone / storage unreachable — still drop the row.
            }
        }

        $title = $download->title;
        $download->delete();

        return redirect()->route('admin.downloads.index')
            ->with('success', "Deleted “{$title}”.");
    }

    private function fail(Request $request, string $message)
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => false, 'message' => $message], 422);
        }

        return back()->with('error', $message);
    }
}
