<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\DownloadFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, tokenised access to a Download Center file. The token is the only
 * credential — long and unguessable. Honours the opt-in flag and the optional
 * expiry; an unavailable link 410s. The NOC proxies the bytes (Azure has no
 * working signed URLs), like the offboarding/certificate public downloads.
 */
class DownloadShareController extends Controller
{
    public function show(string $token)
    {
        $file = DownloadFile::where('public_token', $token)->first();

        if (! $file || ! $file->isPublicAvailable()) {
            return response()->view('public.download_share', ['file' => null], 410);
        }

        return view('public.download_share', ['file' => $file]);
    }

    public function stream(string $token): StreamedResponse
    {
        $file = DownloadFile::where('public_token', $token)->first();
        abort_unless($file && $file->isPublicAvailable(), 410, 'This link is invalid or has expired.');

        $disk = Storage::disk($file->disk ?: DownloadFile::DISK);
        abort_unless($disk->exists($file->azure_path), 404, 'File no longer exists in storage.');

        $file->forceFill([
            'download_count' => $file->download_count + 1,
            'last_downloaded_at' => now(),
        ])->saveQuietly();

        return $disk->download($file->azure_path, $file->suggestedDownloadName());
    }
}
