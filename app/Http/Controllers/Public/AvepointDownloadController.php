<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AvepointBackup;
use App\Models\AvepointDownloadAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /avepoint/download/{token}
 * Token-validated NOC-proxied stream from Azure Blob (azure_avepoint disk).
 * Every hit logs an AvepointDownloadAudit row.
 */
class AvepointDownloadController extends Controller
{
    public function download(Request $request, string $token)
    {
        $backup = AvepointBackup::where('download_token', $token)->first();

        if (! $backup || ! $backup->isDownloadable()) {
            return response()->view('public.offboarding_form_submitted', [
                'error' => true,
                'message' => 'This download link is invalid or has expired.',
            ], 410);
        }

        $audit = AvepointDownloadAudit::create([
            'avepoint_backup_id' => $backup->id,
            'download_token' => $token,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'started_at' => now(),
        ]);

        $filename = $this->buildFilename($backup);
        $stream = Storage::disk('azure_avepoint')->readStream($backup->file_path);

        if ($stream === false || $stream === null) {
            $audit->update(['completed_at' => now(), 'bytes_sent' => 0]);

            return response()->view('public.offboarding_form_submitted', [
                'error' => true,
                'message' => 'The backup file is missing from storage.',
            ], 410);
        }

        $bytesSent = 0;
        $response = new StreamedResponse(function () use ($stream, &$bytesSent) {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $bytesSent += strlen($chunk);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
            fclose($stream);
        });

        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition',
            'attachment; filename="'.str_replace('"', '', $filename).'"');
        if ($backup->file_size) {
            $response->headers->set('Content-Length', (string) $backup->file_size);
        }

        register_shutdown_function(function () use ($audit, &$bytesSent) {
            try {
                $audit->update([
                    'bytes_sent' => $bytesSent,
                    'completed_at' => now(),
                ]);
            } catch (\Throwable) {
                // ignore
            }
        });

        return $response;
    }

    private function buildFilename(AvepointBackup $backup): string
    {
        $name = $backup->subject_name ?: $backup->subject_upn;
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
        $ext = pathinfo($backup->file_path, PATHINFO_EXTENSION) ?: 'bin';

        return "{$safe}-{$backup->type}.{$ext}";
    }
}
