<?php

namespace App\Console\Commands;

use App\Models\UcmServer;
use App\Services\IppbxApiService;
use Illuminate\Console\Command;

/**
 * Probe a UCM for which storage-related API actions are supported and what
 * fields they return. Run once to discover the right action name + response
 * shape, then wire up getStorageStatus() in IppbxApiService accordingly.
 *
 *   php artisan ucm:probe-storage           # probes UCM id=1
 *   php artisan ucm:probe-storage 3         # probes UCM id=3
 */
class ProbeUcmStorage extends Command
{
    protected $signature = 'ucm:probe-storage {ucm_id=1}';
    protected $description = 'Probe a UCM for supported storage/disk/memory API actions';

    public function handle(): int
    {
        $ucm = UcmServer::find($this->argument('ucm_id'));
        if (!$ucm) {
            $this->error("UCM id={$this->argument('ucm_id')} not found.");
            return self::FAILURE;
        }

        $this->info("Probing {$ucm->name} ({$ucm->url}) ...");

        $api = new IppbxApiService($ucm);
        $api->login();

        $reflection  = new \ReflectionClass($api);
        $postMethod  = $reflection->getMethod('post');
        $postMethod->setAccessible(true);
        $cookieProp  = $reflection->getProperty('cookie');
        $cookieProp->setAccessible(true);
        $cookie      = $cookieProp->getValue($api);

        $candidateActions = [
            'getStorageStatus',
            'getStorageDeviceList',
            'listStorage',
            'getStorageInfo',
            'getStorageInformation',
            'getDiskStatus',
            'getDiskUsage',
            'listSpaceInfo',
            'getMemoryStatus',
            'getCPULoadStatus',
        ];

        foreach ($candidateActions as $action) {
            try {
                $resp = $postMethod->invoke($api, ['action' => $action, 'cookie' => $cookie], 15);
                $status = $resp['status'] ?? '?';
                $line = "[{$action}] status={$status}";
                if ($status === 0) {
                    $this->info($line . ' OK');
                    $this->line(json_encode($resp['response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $this->line(str_repeat('-', 60));
                } else {
                    $msg = $resp['response']['error_msg'] ?? '';
                    $this->warn($line . ($msg ? " ({$msg})" : ''));
                }
            } catch (\Throwable $e) {
                $this->warn("[{$action}] threw: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
