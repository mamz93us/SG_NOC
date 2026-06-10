<?php

namespace App\Services;

use App\Models\DatabaseBackup;
use App\Models\NocEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Dumps the application MySQL database with mysqldump, gzips it, streams it
 * to Azure Blob (the `azure_db_backups` disk) and records the run in
 * `database_backups`. Called by the daily db-backups:run command and by
 * RunDatabaseBackupJob (the "Backup Now" button).
 *
 * The dump is written to a local temp file first (storage/app/db-backups)
 * and only deleted after the Azure upload is byte-verified, so a failed
 * upload never costs the dump. Credentials go to mysqldump via a 0600
 * --defaults-extra-file, never argv (visible in `ps`).
 */
class DatabaseBackupService
{
    public function run(DatabaseBackup $backup): void
    {
        @set_time_limit(0);

        $backup->forceFill([
            'status' => DatabaseBackup::STATUS_RUNNING,
            'started_at' => now(),
            'error' => null,
        ])->save();

        $connection = config('database.connections.'.config('database.default'));
        $database = (string) ($connection['database'] ?? '');

        $tmpDir = storage_path('app/db-backups');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $stamp = now()->format('Ymd-His');
        $filename = "{$database}-{$stamp}.sql.gz";
        $sqlPath = $tmpDir.DIRECTORY_SEPARATOR."{$database}-{$stamp}.sql";
        $gzPath = $tmpDir.DIRECTORY_SEPARATOR.$filename;
        $cnfPath = $tmpDir.DIRECTORY_SEPARATOR.'.dump-'.Str::lower(Str::random(12)).'.cnf';

        $disk = (string) config('db_backup.disk', 'azure_db_backups');
        $azurePath = now()->format('Y/m').'/'.$filename;

        try {
            if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
                throw new \RuntimeException('Database backups require a MySQL/MariaDB connection (default connection is '.($connection['driver'] ?? 'unknown').').');
            }
            if ($database === '') {
                throw new \RuntimeException('No database configured on the default connection.');
            }

            $this->writeCredentialsFile($cnfPath, $connection);
            $this->dump($cnfPath, $database, $sqlPath);
            $this->gzip($sqlPath, $gzPath);
            @unlink($sqlPath);

            $size = (int) filesize($gzPath);
            $sha = hash_file('sha256', $gzPath) ?: null;

            $this->upload($disk, $azurePath, $gzPath, $size);

            $backup->forceFill([
                'database' => $database,
                'filename' => $filename,
                'size' => $size,
                'sha256' => $sha,
                'disk' => $disk,
                'azure_path' => $azurePath,
                'status' => DatabaseBackup::STATUS_UPLOADED,
                'completed_at' => now(),
            ])->save();

            @unlink($gzPath);
            $this->syncFailureEvent(null);
        } catch (\Throwable $e) {
            // Best-effort cleanup of a partial remote write so a retry is clean.
            try {
                Storage::disk($disk)->delete($azurePath);
            } catch (\Throwable) {
            }
            @unlink($sqlPath);
            @unlink($gzPath);

            $backup->forceFill([
                'database' => $database,
                'filename' => $filename,
                'status' => DatabaseBackup::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 2000),
                'completed_at' => now(),
            ])->save();

            Log::error('db-backup: failed — '.$e->getMessage());
            $this->syncFailureEvent($e->getMessage());

            throw $e;
        } finally {
            @unlink($cnfPath);
        }
    }

    /** @param  array<string, mixed>  $connection */
    private function writeCredentialsFile(string $cnfPath, array $connection): void
    {
        $cnf = "[client]\n"
            .'host='.($connection['host'] ?? '127.0.0.1')."\n"
            .'port='.($connection['port'] ?? 3306)."\n"
            .'user='.($connection['username'] ?? '')."\n"
            .'password="'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) ($connection['password'] ?? ''))."\"\n";

        if (file_put_contents($cnfPath, $cnf) === false) {
            throw new \RuntimeException('Could not write mysqldump credentials file.');
        }
        @chmod($cnfPath, 0600);
    }

    private function dump(string $cnfPath, string $database, string $sqlPath): void
    {
        $process = new Process([
            (string) config('db_backup.mysqldump_path', 'mysqldump'),
            '--defaults-extra-file='.$cnfPath,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--no-tablespaces',
            '--result-file='.$sqlPath,
            $database,
        ]);
        $process->setTimeout((float) config('db_backup.timeout', 1800));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                'mysqldump failed: '.(trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'exit code '.$process->getExitCode())
            );
        }

        $size = is_file($sqlPath) ? (int) filesize($sqlPath) : 0;
        if ($size <= 0) {
            throw new \RuntimeException('mysqldump produced an empty dump file.');
        }

        // mysqldump ends a complete dump with "-- Dump completed on ..." —
        // a missing footer means it died mid-write without a non-zero exit.
        $handle = fopen($sqlPath, 'rb');
        if ($handle !== false) {
            fseek($handle, -min(300, $size), SEEK_END);
            $tail = (string) stream_get_contents($handle);
            fclose($handle);
            if (! str_contains($tail, 'Dump completed')) {
                throw new \RuntimeException('Dump file is truncated (missing "Dump completed" footer).');
            }
        }
    }

    private function gzip(string $sqlPath, string $gzPath): void
    {
        $in = fopen($sqlPath, 'rb');
        $out = gzopen($gzPath, 'wb6');
        if ($in === false || $out === false) {
            throw new \RuntimeException('Could not open dump file for compression.');
        }
        try {
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Read error while compressing dump.');
                }
                if ($chunk !== '' && gzwrite($out, $chunk) === false) {
                    throw new \RuntimeException('Write error while compressing dump.');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    private function upload(string $disk, string $azurePath, string $gzPath, int $size): void
    {
        $diskApi = Storage::disk($disk);

        $stream = fopen($gzPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Could not open compressed dump for upload.');
        }
        try {
            $ok = $diskApi->writeStream($azurePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($ok === false) {
            throw new \RuntimeException('writeStream returned false.');
        }

        // Verify the byte count landed before we trust (and delete) the local copy.
        $remoteSize = (int) $diskApi->size($azurePath);
        if ($remoteSize !== $size) {
            throw new \RuntimeException("Size mismatch after upload (local {$size}, remote {$remoteSize}).");
        }
    }

    /**
     * Surface a failed backup as a single open NocEvent so the existing
     * notification rules fire; resolve it once a backup succeeds again.
     */
    private function syncFailureEvent(?string $error): void
    {
        try {
            if ($error !== null) {
                $message = 'Database backup to Azure Blob failed: '.Str::limit($error, 500);

                $event = NocEvent::firstOrCreate(
                    ['source_type' => 'db_backup_failed', 'source_id' => 0, 'status' => 'open'],
                    [
                        'module' => 'network',
                        'severity' => 'warning',
                        'title' => 'Database backup failed',
                        'message' => $message,
                        'first_seen' => now(),
                        'last_seen' => now(),
                    ]
                );
                $event->update(['last_seen' => now(), 'message' => $message]);
            } else {
                NocEvent::where('source_type', 'db_backup_failed')
                    ->where('status', 'open')
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('db-backup: could not sync NocEvent: '.$e->getMessage());
        }
    }
}
