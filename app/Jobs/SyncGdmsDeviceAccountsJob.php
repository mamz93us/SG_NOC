<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class SyncGdmsDeviceAccountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes max
    public int $tries   = 1;   // don't retry — just log failure

    protected ?int $userId;
    protected bool $unsyncedOnly;

    public function __construct(?int $userId = null, bool $unsyncedOnly = false)
    {
        $this->userId       = $userId;
        $this->unsyncedOnly = $unsyncedOnly;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $args = $this->unsyncedOnly ? ['--unsynced' => true] : [];

        Artisan::call('gdms:sync-device-accounts', $args);

        ActivityLog::create([
            'model_type' => 'PhoneRequestLog',
            'model_id'   => 0,
            'action'     => 'synced',
            'changes'    => ['type' => $this->unsyncedOnly ? 'unsynced_only' : 'full_sync'],
            'user_id'    => $this->userId,
        ]);
    }
}
