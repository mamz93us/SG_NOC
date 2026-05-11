<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Offboarding\SendBackupDownloadLinkJob;
use App\Models\OffboardingBackup;
use App\Models\Setting;
use App\Services\Workflow\OffboardingProcessor;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Admin endpoint that lets IT upload an externally-exported archive (from
 * AvePoint UI or a laptop backup) for an OffboardingBackup row currently in
 * 'manual_upload_required' state. The file is streamed straight to Azure Blob,
 * SHA-256 computed inline, then the download-link email fires to the manager
 * (or the IT user, for laptop backups).
 */
class OffboardingBackupUploadController extends Controller
{
    public function upload(
        Request               $request,
        OffboardingBackup     $backup,
        WorkflowEngine        $engine,
        OffboardingProcessor  $processor,
    ) {
        $request->validate([
            'archive' => 'required|file|max:51200000', // up to ~50 GB
        ]);

        if ($backup->status === 'completed') {
            return back()->with('error', 'This backup is already completed.');
        }

        $ow      = $backup->offboardingWorkflow;
        $emplId  = $ow?->employee?->id ?? 'unknown';
        $ext     = $request->file('archive')->getClientOriginalExtension() ?: match ($backup->type) {
            'mailbox'  => 'pst',
            'onedrive' => 'zip',
            'laptop'   => 'zip',
            default    => 'bin',
        };
        $blobPath = "{$emplId}/{$backup->type}-" . date('Ymd-His') . ".{$ext}";

        $hashCtx = hash_init('sha256');
        $bytes   = 0;
        $src     = fopen($request->file('archive')->getRealPath(), 'rb');

        // Tee through SHA + size counters while streaming to Azure.
        $tmpStream = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
        while (! feof($src)) {
            $chunk = fread($src, 1024 * 1024);
            if ($chunk === false) break;
            fwrite($tmpStream, $chunk);
            hash_update($hashCtx, $chunk);
            $bytes += strlen($chunk);
        }
        fclose($src);
        rewind($tmpStream);

        try {
            Storage::disk('azure_offboarding')->writeStream($blobPath, $tmpStream);
        } catch (\Throwable $e) {
            return back()->with('error', 'Azure Blob upload failed: ' . $e->getMessage());
        } finally {
            if (is_resource($tmpStream)) fclose($tmpStream);
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

        $engine->logEvent($ow?->workflow, 'success',
            "Manual upload completed for {$backup->type} ({$backup->humanSize()}).");

        SendBackupDownloadLinkJob::dispatch($backup->id)->onQueue('emails');

        // Laptop backup completion releases the gated Intune device delete.
        if ($backup->type === 'laptop' && $ow) {
            $processor->onLaptopBackupComplete($ow);
        }

        return back()->with('success', 'Backup uploaded. Download link emailed to the manager.');
    }
}
