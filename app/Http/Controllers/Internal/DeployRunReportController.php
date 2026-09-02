<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\DeployRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal-only endpoint the Node proxy POSTs to when an exec run finishes.
 *
 * The proxy reports, not the browser — so a deploy that outlives the tab still
 * lands a complete transcript and a trustworthy exit code.
 *
 * Protected by:
 *  1. Caller must be 127.0.0.1 (the `internal.ip` middleware in routes/web.php).
 *  2. X-Telnet-Secret must match config('telnet.internal_secret').
 */
class DeployRunReportController extends Controller
{
    /** Hard cap so a runaway command can't blow up the row (or the request). */
    private const MAX_OUTPUT_BYTES = 2 * 1024 * 1024;

    public function store(Request $request, DeployRun $run): JsonResponse
    {
        // Fail closed when the secret is unset — same contract as TelnetTokenController.
        $secret = config('telnet.internal_secret');
        $provided = (string) $request->header('X-Telnet-Secret', '');
        if (! is_string($secret) || $secret === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        // A run only reports once. Anything else (already reaped as `timeout`,
        // replayed, or finished by the reconnect path) is left alone.
        if (! $run->isRunning()) {
            return response()->json(['ok' => true, 'ignored' => 'run already closed']);
        }

        $data = $request->validate([
            'exit_code' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:success,failed,timeout,aborted'],
            'output' => ['nullable', 'string'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $exitCode = $data['exit_code'] ?? null;
        $status = $data['status'] ?? ($exitCode === 0 ? 'success' : 'failed');

        $run->forceFill([
            'status' => $status,
            'exit_code' => $exitCode,
            'output' => $this->truncate($data['output'] ?? null),
            'finished_at' => now(),
            'duration_ms' => $data['duration_ms']
                ?? ($run->started_at ? (int) $run->started_at->diffInMilliseconds(now()) : null),
        ])->save();

        return response()->json(['ok' => true, 'status' => $run->status]);
    }

    private function truncate(?string $output): ?string
    {
        if ($output === null || strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_OUTPUT_BYTES)
            ."\n\n… output truncated at ".self::MAX_OUTPUT_BYTES." bytes …\n";
    }
}
