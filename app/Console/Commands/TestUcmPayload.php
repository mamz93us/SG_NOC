<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UcmServer;
use App\Services\IppbxApiService;
use Illuminate\Support\Facades\Log;

class TestUcmPayload extends Command
{
    protected $signature = 'ucm:test-payload {ucm_id=1}';
    protected $description = 'Test various addSIPAccountAndUser payloads';

    public function handle()
    {
        $ucm = UcmServer::find($this->argument('ucm_id'));
        if (!$ucm) {
            $this->error("UCM not found.");
            return;
        }

        $api = new IppbxApiService($ucm);
        $api->login();

        $this->info("Logged in successfully. Testing payloads...");

        $tests = [
            '1_basic' => [
                'extension' => '6039',
                'secret' => 'A1b2C3d4*',
                'user_password' => 'A1b2C3d4*',
                'permission' => 'internal',
            ],
            '2_basic_plus_vmsecret' => [
                'extension' => '6040',
                'secret' => 'A1b2C3d4*',
                'user_password' => 'A1b2C3d4*',
                'vmsecret' => 'A1b2C3d4*',
                'permission' => 'internal',
            ],
            '3_full_example' => [
                'extension' => '6041',
                'max_contacts' => '3',
                'permission' => 'internal',
                'language' => 'en',
                'secret' => 'Abc123456!',
                'vmsecret' => 'Abc123456!',
                'user_password' => 'Abc123456!',
                'wave_privilege_id' => '0',
            ],
            '4_numeric_vmsecret' => [
                'extension' => '6042',
                'secret' => 'A1b2C3d4*',
                'user_password' => 'A1b2C3d4*',
                'vmsecret' => '123456',
                'permission' => 'internal',
            ],
            '5_fullname' => [
                'extension' => '6043',
                'secret' => 'A1b2C3d4*',
                'user_password' => 'A1b2C3d4*',
                'vmsecret' => 'A1b2C3d4*',
                'permission' => 'internal',
                'fullname' => 'Test User',
                'first_name' => 'Test',
                'last_name' => 'User',
            ],
        ];

        foreach ($tests as $name => $payload) {
            $this->info("Testing: {$name}");
            
            // Raw HTTP POST bypassing IppbxApiService payload validation/enforcement
            $resp = $this->sendRaw($api, $payload);
            
            if (($resp['status'] ?? -1) === 0) {
                $this->info("  [SUCCESS] $name worked!");
            } else {
                $this->error("  [FAILED] $name -> status: " . ($resp['status'] ?? '?') . " / " . json_encode($resp));
            }
        }
    }

    private function sendRaw($api, array $data)
    {
        // Use reflection to access the protected 'post' method and 'cookie' property
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
