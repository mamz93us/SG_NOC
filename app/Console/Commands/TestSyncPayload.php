<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UcmServer;
use App\Services\IppbxApiService;

class TestSyncPayload extends Command
{
    protected $signature = 'ucm:test-sync {ucm_id=1}';
    protected $description = 'Test the exact AD Sync payload for extension 1006';

    public function handle()
    {
        $ucm = UcmServer::find($this->argument('ucm_id'));
        if (!$ucm) {
            $this->error("UCM not found.");
            return;
        }

        $api = new IppbxApiService($ucm);
        $api->login();

        $complexSecret = 'A1b2C3d4!@#$';

        $payload = [
            'extension'     => '1006',
            'secret'        => $complexSecret,
            'user_password' => $complexSecret, 
            'vmsecret'      => '625728',
            'permission'    => 'internal-local',
        ];

        $this->info("Sending exact payload from the log (1006)...");
        
        $resp = $this->sendRaw($api, $payload);
        
        if (($resp['status'] ?? -1) === 0) {
            $this->info("  [SUCCESS] Payload worked!");
        } else {
            $this->error("  [FAILED] status: " . ($resp['status'] ?? '?') . " / " . json_encode($resp));
            if (($resp['status'] ?? -1) == -25) {
                $this->warn("   It failed with -25. Let's try changing 'permission' to 'internal-local-national'...");
                $payload['permission'] = 'internal-local-national';
                $resp2 = $this->sendRaw($api, $payload);
                if (($resp2['status'] ?? -1) === 0) {
                    $this->info("  [SUCCESS] Changing permission fixed it!");
                } else {
                    $this->error("  [FAILED] status: " . ($resp2['status'] ?? '?') . " / " . json_encode($resp2));
                    
                    $this->warn("   Still failing. Let's try changing the extension to 6049 (maybe 1006 already exists? Code -8 is exists, but maybe UCM throws -25)...");
                    $payload['extension'] = '6049';
                    $resp3 = $this->sendRaw($api, $payload);
                    if (($resp3['status'] ?? -1) === 0) {
                        $this->info("  [SUCCESS] Changing extension to 6049 fixed it! 1006 was the problem.");
                    } else {
                        $this->error("  [FAILED] status: " . ($resp3['status'] ?? '?') . " / " . json_encode($resp3));
                    }
                }
            }
        }
    }

    private function sendRaw($api, array $data)
    {
        $reflection = new \ReflectionClass($api);
        $postMethod = $reflection->getMethod('post');
        $postMethod->setAccessible(true);
        $cookieProp = $reflection->getProperty('cookie');
        $cookieProp->setAccessible(true);
        $cookie = $cookieProp->getValue($api);

        $data['action'] = 'addSIPAccountAndUser';
        $data['cookie'] = $cookie;

        try {
            return $postMethod->invoke($api, $data);
        } catch (\Exception $e) {
            return ['status' => -1, 'error' => $e->getMessage()];
        }
    }
}
