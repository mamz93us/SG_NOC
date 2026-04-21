<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\CiscoTelnetClient;
use Illuminate\Console\Command;

/**
 * Long-running telnet daemon for the in-browser terminal. Communicates with the
 * HTTP front-end via append-only files inside storage/app/telnet-sessions/{token}.
 *
 *  in.log   — browser → daemon (raw bytes to forward to the switch)
 *  out.log  — daemon → browser (raw bytes received from the switch)
 *  status   — connecting|ready|closed|error:<reason>
 *  stop     — browser writes any content here to signal shutdown
 *  meta.json — user_id, device_id, started_at
 */
class SwitchTelnetSession extends Command
{
    protected $signature   = 'switch:telnet-session {device} {token}';
    protected $description = 'Run a persistent telnet session daemon backing the browser terminal';

    public function handle(): int
    {
        $deviceId = (int) $this->argument('device');
        $token    = (string) $this->argument('token');

        if (!preg_match('/^[a-zA-Z0-9]{16,64}$/', $token)) {
            $this->error('Invalid token');
            return 1;
        }

        $sessionDir = storage_path('app/telnet-sessions/' . $token);
        if (!is_dir($sessionDir)) @mkdir($sessionDir, 0775, true);

        $status = fn (string $s) => @file_put_contents($sessionDir . '/status', $s);
        $append = function (string $s) use ($sessionDir): void {
            $fh = @fopen($sessionDir . '/out.log', 'ab');
            if ($fh) { @fwrite($fh, $s); @fclose($fh); }
        };

        @file_put_contents($sessionDir . '/pid', (string) getmypid());
        $status('connecting');

        $device = Device::find($deviceId);
        if (!$device) {
            $append("*** Device {$deviceId} not found ***\n");
            $status('error:device-not-found');
            return 1;
        }

        $telnet = $device->credentials()->where('category', 'telnet')->first();
        $enable = $device->credentials()->where('category', 'enable')->first();
        if (!$telnet) {
            $append("*** No 'telnet' credential for this device. Add one in Setup. ***\n");
            $status('error:no-credential');
            return 1;
        }

        $client = new CiscoTelnetClient();
        try {
            $client->connect($device->ip_address, 23, 10.0);
            $client->waitFor(['Password:'], 10.0);
            $client->send((string) $telnet->password);
            $append($client->drain(65536, 0.5));

            if ($enable) {
                $client->send('enable');
                $client->waitFor(['Password:'], 10.0);
                $client->send((string) $enable->password);
                $append($client->drain(65536, 0.5));
            }

            $client->send('terminal length 0');
            $append($client->drain(65536, 0.5));
        } catch (\Throwable $e) {
            $append("\n*** Login failed: {$e->getMessage()} ***\n");
            $status('error:login');
            $client->close();
            return 1;
        }

        $status('ready');

        $inFile  = $sessionDir . '/in.log';
        $stopFile = $sessionDir . '/stop';
        $inOffset = 0;
        $idleTicks = 0;
        $maxIdleTicks = 6000;  // ~10 min at 100ms

        try {
            while (true) {
                if (file_exists($stopFile))       break;
                if (!$client->isConnected())      break;
                if ($idleTicks >= $maxIdleTicks)  break;

                // Forward any pending browser input to the switch.
                if (file_exists($inFile)) {
                    clearstatcache(true, $inFile);
                    $size = (int) @filesize($inFile);
                    if ($size > $inOffset) {
                        $fh = @fopen($inFile, 'rb');
                        if ($fh) {
                            @fseek($fh, $inOffset);
                            $pending = @fread($fh, $size - $inOffset);
                            @fclose($fh);
                            if ($pending !== false && $pending !== '') {
                                try { $client->send($pending, false); }
                                catch (\Throwable $e) { break; }
                                $inOffset = $size;
                                $idleTicks = 0;
                            }
                        }
                    }
                }

                // Pull anything the switch has to say.
                $chunk = $client->drain(65536, 0.15);
                if ($chunk !== '') {
                    $append($chunk);
                    $idleTicks = 0;
                } else {
                    $idleTicks++;
                }
            }
        } catch (\Throwable $e) {
            $append("\n*** Session error: {$e->getMessage()} ***\n");
            $status('error:' . substr($e->getMessage(), 0, 120));
            $client->close();
            return 1;
        }

        try { $client->send('exit'); } catch (\Throwable) {}
        $client->close();
        $status('closed');
        return 0;
    }
}
