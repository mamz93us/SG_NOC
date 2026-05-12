<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Avepoint\SendAvepointBackupReadyJob;
use App\Models\AvepointBackup;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Admin endpoint that lets IT upload an export they ran from the AvePoint web
 * UI for an AvepointBackup row in 'manual_upload_required' state. The file is
 * streamed straight to the azure_avepoint disk, SHA-256 computed inline, then
 * the download-link email fires to the requester.
 */
class AvepointBackupUploadController extends Controller
{
    public function upload(Request $request, AvepointBackup $backup)
    {
        $request->validate([
            'archive' => 'required|file|max:51200000', // ~50 GB
        ]);

        if ($backup->status === 'completed') {
            return back()->with('error', 'This backup is already completed.');
        }

        $ext = $request->file('archive')->getClientOriginalExtension() ?: match ($backup->type) {
            'mailbox'  => 'pst',
            'onedrive' => 'zip',
            default    => 'bin',
        };
        $sub      = preg_replace('/[^A-Za-z0-9._-]+/', '-', $backup->subject_upn);
        $blobPath = "{$sub}/{$backup->type}-" . date('Ymd-His') . ".{$ext}";

        $hashCtx = hash_init('sha256');
        $bytes   = 0;
        $src     = fopen($request->file('archive')->getRealPath(), 'rb');
        $tmp     = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');

        while (! feof($src)) {
            $chunk = fread($src, 1024 * 1024);
            if ($chunk === false) break;
            fwrite($tmp, $chunk);
            hash_update($hashCtx, $chunk);
            $bytes += strlen($chunk);
        }
        fclose($src);
        rewind($tmp);

        try {
            Storage::disk('azure_avepoint')->writeStream($blobPath, $tmp);
        } catch (\Throwable $e) {
            return back()->with('error', 'Azure Blob upload failed: ' . $e->getMessage());
        } finally {
            if (is_resource($tmp)) fclose($tmp);
        }

        $expiryDays = (int) (Setting::get()->offboarding_download_expiry_days ?? 5);

        $backup->update([
            'source'              => 'manual_upload',
            'file_path'           => $blobPath,
            'file_size'           => $bytes,
            'file_sha256'         => hash_final($hashCtx),
            'status'              => 'completed',
            'download_token'      => Str::random(64),
            'download_expires_at' => now()->addDays($expiryDays),
        ]);

        SendAvepointBackupReadyJob::dispatch($backup->id)->onQueue('emails');

        return back()->with('success', 'Backup uploaded. Download link emailed to the requester.');
    }
}
