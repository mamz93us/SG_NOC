<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Training\CourseCertificate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, tokenised access to a single CourseCertificate. The token is the
 * only credential — long, unguessable, and embedded in the email merge tag.
 * No expiry by design: employees should always be able to retrieve their
 * own training records.
 */
class PublicCertificateController extends Controller
{
    public function show(string $token)
    {
        $cert = CourseCertificate::with('course')->where('token', $token)->first();
        abort_unless($cert, 404);

        // Tracking. Use a raw atomic increment so concurrent views don't lose
        // a count under racing requests.
        DB::table('course_certificates')->where('id', $cert->id)->update([
            'viewed_at'  => $cert->viewed_at ?? now(),
            'view_count' => DB::raw('view_count + 1'),
        ]);

        $isPdf = $this->isPdf($cert);

        return view('training.certificate', [
            'certificate' => $cert,
            'isPdf'       => $isPdf,
        ]);
    }

    /**
     * Stream the raw file. Used both for the "Download" button and as the
     * src for the embedded preview / image on the show page.
     */
    public function stream(string $token, Request $request): StreamedResponse
    {
        $cert = CourseCertificate::where('token', $token)->first();
        abort_unless($cert, 404);

        $disk = Storage::disk(CourseCertificate::DISK);
        abort_unless($disk->exists($cert->file_path), 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $filename = $cert->suggestedDownloadName();

        return $disk->response(
            $cert->file_path,
            $filename,
            [
                'Content-Type'        => $cert->file_mime ?: 'application/octet-stream',
                'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
                'Cache-Control'       => 'private, max-age=300',
            ],
        );
    }

    private function isPdf(CourseCertificate $cert): bool
    {
        if ($cert->file_mime === 'application/pdf') {
            return true;
        }

        return strtolower(pathinfo($cert->file_path, PATHINFO_EXTENSION)) === 'pdf';
    }
}
