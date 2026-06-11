<?php

namespace App\Jobs;

use App\Models\UcmActiveCall;
use App\Models\UcmServer;
use App\Services\IppbxApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncUcmActiveCallsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(): void
    {
        $servers = UcmServer::where('is_active', true)->get();

        // Collect all calls across servers, then replace table contents
        $allCalls = [];

        foreach ($servers as $server) {
            try {
                $api = new IppbxApiService($server);
                $api->login();

                $calls = $api->listActiveCalls();

                foreach ($calls as $call) {
                    $allCalls[] = [
                        'ucm_id' => $server->id,
                        'caller' => $call['caller_id'] ?? $call['caller'] ?? $call['src'] ?? '-',
                        'callee' => $call['callee_id'] ?? $call['callee'] ?? $call['dst'] ?? $call['dest'] ?? '-',
                        'start_time' => ! empty($call['start_time']) ? date('Y-m-d H:i:s', is_numeric($call['start_time']) ? (int) $call['start_time'] : strtotime($call['start_time'])) : now(),
                        'answered_time' => ! empty($call['answer_time']) ? date('Y-m-d H:i:s', is_numeric($call['answer_time']) ? (int) $call['answer_time'] : strtotime($call['answer_time'])) : null,
                        'duration' => (int) ($call['duration'] ?? 0),
                        'call_id' => $call['call_id'] ?? $call['uniqueid'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug("SyncUcmActiveCallsJob: Failed for {$server->name}: {$e->getMessage()}");
            }
        }

        // Clear and re-insert (active calls are transient). DELETE, not
        // TRUNCATE: TRUNCATE is DDL in MySQL and kills any concurrent
        // --single-transaction mysqldump with error 1412 (db-backups:run runs
        // while this job ticks every minute). The table holds at most a few
        // dozen rows, so DELETE costs nothing.
        UcmActiveCall::query()->delete();

        if (! empty($allCalls)) {
            // Chunk insert for efficiency
            foreach (array_chunk($allCalls, 50) as $chunk) {
                UcmActiveCall::insert($chunk);
            }
        }
    }
}
