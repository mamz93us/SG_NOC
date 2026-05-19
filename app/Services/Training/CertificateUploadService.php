<?php

namespace App\Services\Training;

use App\Models\Employee;
use App\Models\Training\Course;
use App\Models\Training\CourseCertificate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Handles bulk upload of completion certificates for a Course. Each uploaded
 * filename is treated as `<recipient-email>.<ext>` — the part before the last
 * extension dot is the email, matched case-insensitively against the
 * employees table. Files end up in Azure Blob under
 *   certificates/{course_id}/{token}.{ext}
 * so the disk layout never leaks employee identities.
 *
 * Returns a per-file outcome report so the controller can surface what
 * imported cleanly vs what landed as orphans.
 */
class CertificateUploadService
{
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    /**
     * @param  array<UploadedFile>  $files
     * @return array{imported:int, replaced:int, orphaned:int, rejected:int, items:array<int,array{filename:string, status:string, message?:string, certificate_id?:int}>}
     */
    public function handleUpload(Course $course, array $files, ?int $userId = null): array
    {
        $report = [
            'imported' => 0,
            'replaced' => 0,
            'orphaned' => 0,
            'rejected' => 0,
            'items'    => [],
        ];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                $report['rejected']++;
                $report['items'][] = [
                    'filename' => $file?->getClientOriginalName() ?? '(invalid file)',
                    'status'   => 'rejected',
                    'message'  => 'Upload failed at the HTTP layer.',
                ];

                continue;
            }

            $originalName = $file->getClientOriginalName();
            $ext = strtolower($file->getClientOriginalExtension());

            if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                $report['rejected']++;
                $report['items'][] = [
                    'filename' => $originalName,
                    'status'   => 'rejected',
                    'message'  => 'Unsupported file type: '.$ext,
                ];

                continue;
            }

            $email = $this->extractEmail($originalName);
            if (! $email) {
                $report['rejected']++;
                $report['items'][] = [
                    'filename' => $originalName,
                    'status'   => 'rejected',
                    'message'  => 'Filename is not a valid email (expected <employee@domain>.pdf).',
                ];

                continue;
            }

            $employee = Employee::whereRaw('LOWER(email) = ?', [$email])->first();

            // Replace-or-create: a re-upload for the same (course, email) keeps the
            // existing token and overwrites the file. This means previously-sent
            // links keep working.
            $existing = CourseCertificate::where('course_id', $course->id)
                ->where('email', $email)
                ->first();

            $token = $existing?->token ?? CourseCertificate::generateToken();
            $storedPath = sprintf('%d/%s.%s', $course->id, $token, $ext);

            try {
                Storage::disk(CourseCertificate::DISK)->put(
                    $storedPath,
                    file_get_contents($file->getRealPath()),
                );
            } catch (\Throwable $e) {
                $report['rejected']++;
                $report['items'][] = [
                    'filename' => $originalName,
                    'status'   => 'rejected',
                    'message'  => 'Storage write failed: '.$e->getMessage(),
                ];

                continue;
            }

            // If we're replacing an existing record on the same disk path, the put()
            // above already overwrote the blob. If the previous record used a
            // different extension (jpg → pdf), drop the old blob so we don't leak.
            if ($existing && $existing->file_path !== $storedPath) {
                Storage::disk(CourseCertificate::DISK)->delete($existing->file_path);
            }

            $payload = [
                'course_id'         => $course->id,
                'employee_id'       => $employee?->id,
                'email'             => $email,
                'file_path'         => $storedPath,
                'file_mime'         => $file->getMimeType(),
                'original_filename' => $originalName,
                'file_size'         => $file->getSize(),
                'token'             => $token,
                'created_by'        => $userId,
            ];

            if ($existing) {
                // Re-upload clears the prior viewed/sent state because the file changed.
                $payload['sent_at'] = null;
                $payload['viewed_at'] = null;
                $payload['view_count'] = 0;
                $existing->update($payload);
                $cert = $existing;
                $report['replaced']++;
                $status = 'replaced';
            } else {
                $cert = CourseCertificate::create($payload);
                $report['imported']++;
                $status = 'imported';
            }

            if (! $employee) {
                $report['orphaned']++;
                $status = 'orphaned';
            }

            $report['items'][] = [
                'filename'       => $originalName,
                'status'         => $status,
                'message'        => $employee
                    ? "Linked to {$employee->name}"
                    : 'No active employee with this email — saved as orphan.',
                'certificate_id' => $cert->id,
            ];
        }

        return $report;
    }

    /**
     * Extract the recipient email from "ahmed@samirgroup.com.pdf" →
     * "ahmed@samirgroup.com". Returns null if the filename doesn't look
     * like an email.
     */
    public function extractEmail(string $filename): ?string
    {
        // Strip the final extension only — emails don't contain a final ".pdf" etc.
        $base = preg_replace('/\.[a-zA-Z0-9]+$/', '', $filename);
        $base = strtolower(trim((string) $base));

        if (! filter_var($base, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $base;
    }
}
