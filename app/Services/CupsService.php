<?php

namespace App\Services;

use App\Models\CupsPrinter;

class CupsService
{
    /**
     * Register a printer queue in CUPS on the VPS.
     */
    public function addPrinter(CupsPrinter $printer): array
    {
        $cmd = sprintf(
            'sudo lpadmin -p %s -E -v %s -m %s -L %s -o printer-is-shared=%s 2>&1',
            escapeshellarg($printer->queue_name),
            escapeshellarg($printer->getCupsUri()),
            escapeshellarg($printer->driver ?: 'everywhere'),
            escapeshellarg($printer->location ?? $printer->name),
            $printer->is_shared ? 'true' : 'false'
        );

        exec($cmd, $output, $code);

        return ['success' => $code === 0, 'output' => implode("\n", $output)];
    }

    /**
     * Enable a CUPS queue and accept jobs.
     */
    public function enablePrinter(string $queueName): bool
    {
        exec('sudo cupsenable ' . escapeshellarg($queueName) . ' 2>&1', $out1, $code1);
        exec('sudo cupsaccept ' . escapeshellarg($queueName) . ' 2>&1', $out2, $code2);

        return $code1 === 0 && $code2 === 0;
    }

    /**
     * Disable a CUPS queue.
     */
    public function disablePrinter(string $queueName): bool
    {
        exec('sudo cupsdisable ' . escapeshellarg($queueName) . ' 2>&1', $out, $code);

        return $code === 0;
    }

    /**
     * Remove a printer queue from CUPS.
     */
    public function removePrinter(string $queueName): bool
    {
        exec('sudo lpadmin -x ' . escapeshellarg($queueName) . ' 2>&1', $out, $code);

        return $code === 0;
    }

    /**
     * Get the status of a single CUPS queue.
     */
    public function getStatus(string $queueName): string
    {
        exec('lpstat -p ' . escapeshellarg($queueName) . ' 2>&1', $output, $code);

        if ($code !== 0) {
            return 'unknown';
        }

        $line = implode(' ', $output);

        if (str_contains($line, 'idle')) {
            return 'online';
        }
        if (str_contains($line, 'printing')) {
            return 'printing';
        }
        if (str_contains($line, 'disabled')) {
            return 'offline';
        }

        return 'unknown';
    }

    /**
     * Get all CUPS queue lines from lpstat.
     */
    public function listQueues(): array
    {
        exec('lpstat -p 2>&1', $output);

        return $output;
    }

    /**
     * Get pending/active jobs for a queue.
     */
    public function getJobs(string $queueName): array
    {
        exec('lpstat -o ' . escapeshellarg($queueName) . ' 2>&1', $output);

        return $output;
    }

    /**
     * Get all jobs (active + completed) for a queue, parsed into structured data.
     * Returns array of ['job_id' => int, 'user' => string, 'title' => string, 'size' => string, 'status' => string]
     */
    public function getAllJobs(string $queueName): array
    {
        $jobs = [];

        // Active jobs
        exec('lpstat -o ' . escapeshellarg($queueName) . ' 2>&1', $activeLines);
        foreach ($activeLines as $line) {
            $parsed = $this->parseJobLine($line, $queueName);
            if ($parsed) {
                $parsed['status'] = 'processing';
                $jobs[] = $parsed;
            }
        }

        // Completed jobs
        $completedLines = [];
        exec('lpstat -W completed -o ' . escapeshellarg($queueName) . ' 2>&1', $completedLines);
        foreach ($completedLines as $line) {
            $parsed = $this->parseJobLine($line, $queueName);
            if ($parsed) {
                $parsed['status'] = 'completed';
                $jobs[] = $parsed;
            }
        }

        return $jobs;
    }

    /**
     * Parse a single lpstat job line.
     * Format: "queue-123  username  1024  Mon 14 Apr 2025 10:30:00"
     */
    private function parseJobLine(string $line, string $queueName): ?array
    {
        // Match: queue-JOBID  USER  SIZE  DATE...
        if (preg_match('/^' . preg_quote($queueName, '/') . '-(\d+)\s+(\S+)\s+(\d+)\s+(.*)$/', trim($line), $m)) {
            return [
                'job_id' => (int) $m[1],
                'user'   => $m[2],
                'size'   => $m[3],
                'date'   => trim($m[4]),
            ];
        }

        return null;
    }

    /**
     * Send a file to a CUPS print queue.
     */
    public function printFile(string $queueName, string $filePath, ?string $title = null): array
    {
        $cmd = sprintf(
            'sudo lp -d %s %s %s 2>&1',
            escapeshellarg($queueName),
            $title ? '-t ' . escapeshellarg($title) : '',
            escapeshellarg($filePath)
        );

        exec($cmd, $output, $code);

        return ['success' => $code === 0, 'output' => implode("\n", $output)];
    }

    /**
     * Send the CUPS built-in test page to a queue.
     */
    public function printTestPage(string $queueName): array
    {
        $cmd = sprintf(
            'sudo lp -d %s /usr/share/cups/data/testprint 2>&1',
            escapeshellarg($queueName)
        );

        exec($cmd, $output, $code);

        return ['success' => $code === 0, 'output' => implode("\n", $output)];
    }

    /**
     * Cancel a print job by its CUPS job ID.
     */
    public function cancelJob(string $jobId): bool
    {
        exec('sudo cancel ' . escapeshellarg($jobId) . ' 2>&1', $out, $code);

        return $code === 0;
    }

    /**
     * Check if the CUPS service is running on the VPS.
     */
    public function isCupsRunning(): bool
    {
        exec('systemctl is-active cups 2>&1', $out);

        return trim(implode('', $out)) === 'active';
    }
}
