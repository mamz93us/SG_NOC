<?php

namespace App\Services\Teamtailor;

use App\Models\TeamtailorCvExport;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Builds one zip of every applicant's résumé for a job and uploads it to Azure
 * Blob, recording progress on a TeamtailorCvExport row.
 *
 * Runs out-of-band from the teamtailor:process-cv-exports scheduled command —
 * never from a web request — because a job with hundreds of applicants means
 * hundreds of remote downloads, which would blow the request timeout. The
 * production box runs no queue worker, so the scheduler is the async path.
 *
 * process() is deliberately total: it records its own failure on the export row
 * rather than throwing, so one bad export never aborts the batch drain.
 */
class TeamtailorCvExportService
{
    /** Teamtailor caps page[size] at 30. */
    private const PAGE_SIZE = 30;

    /** Hard ceiling so a pathological meta.page-count can't loop forever. */
    private const MAX_PAGES = 1000;

    /** Per-file résumé download timeout (seconds) — S3 links can be slow. */
    private const DOWNLOAD_TIMEOUT = 120;

    public function __construct(private TeamtailorApiService $api) {}

    /**
     * Produce the zip for one export request. Total by design — failures are
     * stamped on the row, not thrown.
     */
    public function process(TeamtailorCvExport $export): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->fail($export, 'The PHP zip extension is not available on this server, so CV exports cannot be built.');

            return;
        }

        $export->forceFill([
            'status' => TeamtailorCvExport::STATUS_PROCESSING,
            'started_at' => now(),
            'error' => null,
        ])->save();

        $tmpFiles = [];
        $zipPath = null;

        try {
            // Gather every applicant for the job, then keep only those with a
            // résumé URL — the others contribute nothing to the zip.
            $candidates = $this->collectCandidates($export->job_id);
            $withResume = array_values(array_filter(
                $candidates,
                fn ($c) => ! empty($c['resume'])
            ));

            $export->forceFill(['total_candidates' => count($candidates)])->save();

            if ($withResume === []) {
                $this->fail($export, 'No résumé files were found for this job\'s applicants. Candidates may have applied without uploading a CV.');

                return;
            }

            $zipPath = tempnam(sys_get_temp_dir(), 'ttcvzip_');
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                $this->fail($export, 'Could not create a temporary zip archive on the server.');

                return;
            }

            $cvCount = 0;
            $failed = 0;
            $index = 0;

            foreach ($withResume as $candidate) {
                $index++;

                $tmp = $this->downloadResume($candidate['resume']);
                if ($tmp === null) {
                    $failed++;
                } else {
                    $tmpFiles[] = $tmp;
                    $zip->addFile($tmp, $this->entryName($index, $candidate['name'], $candidate['resume']));
                    $cvCount++;
                }

                // Persist progress occasionally so the UI can show a live count
                // without hammering the DB on every iteration.
                if ($index % 10 === 0) {
                    $export->forceFill(['cv_count' => $cvCount, 'failed_count' => $failed])->save();
                }
            }

            // close() is when ZipArchive actually reads the temp files, so it
            // must happen before we upload or clean anything up.
            $zip->close();

            if ($cvCount === 0) {
                $this->fail($export, 'Every résumé download failed — none could be added to the zip. The résumé links may have expired.');

                return;
            }

            $disk = $export->disk ?: 'azure_resumes';
            $blobPath = $this->blobPath($export);

            $stream = fopen($zipPath, 'rb');
            Storage::disk($disk)->writeStream($blobPath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $export->forceFill([
                'status' => TeamtailorCvExport::STATUS_COMPLETED,
                'disk' => $disk,
                'file_path' => $blobPath,
                'file_size' => @filesize($zipPath) ?: null,
                'cv_count' => $cvCount,
                'failed_count' => $failed,
                'completed_at' => now(),
                'error' => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::error("Teamtailor CV export #{$export->id} failed: ".$e->getMessage());
            $this->fail($export, $e->getMessage());
        } finally {
            foreach ($tmpFiles as $f) {
                @unlink($f);
            }
            if ($zipPath !== null && is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    /**
     * Walk every page of a job's applicants, deduped by candidate id. Returns a
     * flat list of ['id', 'name', 'resume'] — the only fields the zip needs.
     *
     * @return array<int,array{id:string,name:string,resume:?string}>
     */
    private function collectCandidates(string $jobId): array
    {
        $candidates = [];
        $seen = [];
        $page = 1;
        $pageCount = null;

        do {
            $body = $this->api->listJobApplicants($jobId, $page, self::PAGE_SIZE);
            $rows = Arr::get($body, 'data', []);

            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $attr = $row['attributes'] ?? [];
                $name = trim(($attr['first-name'] ?? '').' '.($attr['last-name'] ?? ''));

                $candidates[] = [
                    'id' => $id,
                    'name' => $name !== '' ? $name : $id,
                    'resume' => $attr['resume'] ?? null,
                ];
            }

            if ($pageCount === null) {
                $pageCount = (int) Arr::get($body, 'meta.page-count', 0);
            }

            $page++;
            $hasMore = $pageCount > 0
                ? ($page <= $pageCount)
                : (count($rows) === self::PAGE_SIZE);
        } while ($hasMore && $page <= self::MAX_PAGES);

        return $candidates;
    }

    /**
     * Download one résumé to a temp file. Returns the path, or null if the
     * fetch failed or came back empty (caller counts it as a failure).
     */
    private function downloadResume(string $url): ?string
    {
        try {
            $response = Http::timeout(self::DOWNLOAD_TIMEOUT)->get($url);
        } catch (\Throwable $e) {
            Log::warning("Teamtailor résumé download error ({$url}): ".$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if ($body === '') {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ttcv_');
        // Not ->sink(): fake HTTP responses in tests don't populate a sink file,
        // and one small CV in memory at a time is well within limits.
        file_put_contents($tmp, $body);

        return $tmp;
    }

    /**
     * Build a stable, human-readable, collision-free entry name. The numeric
     * prefix guarantees uniqueness even when two candidates slug identically.
     */
    private function entryName(int $index, string $name, ?string $url): string
    {
        $slug = Str::slug($name) ?: 'candidate';

        $ext = strtolower((string) pathinfo((string) parse_url((string) $url, PHP_URL_PATH), PATHINFO_EXTENSION));
        // S3 résumé links sometimes omit an extension; default to pdf.
        if ($ext === '' || strlen($ext) > 5) {
            $ext = 'pdf';
        }

        return sprintf('%03d-%s.%s', $index, $slug, $ext);
    }

    /** Blob key for the finished zip, scoped under the job id. */
    private function blobPath(TeamtailorCvExport $export): string
    {
        $slug = Str::slug((string) ($export->job_title ?: $export->job_id)) ?: 'job';

        return $export->job_id.'/'.$slug.'-cvs-'.now()->format('Ymd-His').'.zip';
    }

    private function fail(TeamtailorCvExport $export, string $message): void
    {
        $export->forceFill([
            'status' => TeamtailorCvExport::STATUS_FAILED,
            'error' => Str::limit($message, 1000, ''),
            'completed_at' => now(),
        ])->save();
    }
}
