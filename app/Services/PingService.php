<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PingService
{
    /**
     * Ping a host and return latency and packet loss.
     */
    public function ping(string $host, int $count = 4): array
    {
        // Security: Validate IP/Hostname format
        if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9.-]+$/', $host)) {
            return ['success' => false, 'message' => 'Invalid host format'];
        }

        $command = $this->getPingCommand($host, $count);
        $process = new Process($command);
        $process->run();

        $output = $process->getOutput();
        
        return $this->parsePingOutput($output, $host);
    }

    /**
     * Perform a TCP port check.
     */
    public function tcpCheck(string $host, int $port, int $timeout = 5): array
    {
        $start = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $end = microtime(true);

        $latency = round(($end - $start) * 1000, 2);

        if ($fp) {
            fclose($fp);
            return [
                'success' => true,
                'latency' => $latency,
                'message' => "Port $port is open on $host"
            ];
        }

        return [
            'success' => false,
            'latency' => null,
            'message' => "Port $port is closed or unreachable on $host: $errstr"
        ];
    }

    protected function getPingCommand(string $host, int $count): array
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return ['ping', '-n', (string)$count, '-w', '1000', $host]; // -w 1000ms per reply
        }
        // -W 1 = 1 second wait per packet, -q = quiet mode for faster parsing
        return ['ping', '-c', (string)$count, '-W', '1', '-q', $host];
    }

    protected function parsePingOutput(string $output, string $host): array
    {
        $success = false;
        $latency = null;
        $packetLoss = 100;

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows parsing
            if (preg_match('/Average = (\d+)ms/', $output, $matches)) {
                $latency = (float)$matches[1];
                $success = true;
            }
            if (preg_match('/\((\d+)% loss\)/', $output, $matches)) {
                $packetLoss = (float)$matches[1];
            }
        } else {
            // Linux/Unix parsing
            if (preg_match('/min\/avg\/max\/mdev = [\d.]+\/([\d.]+)\/[\d.]+\/[\d.]+ ms/', $output, $matches)) {
                $latency = (float)$matches[1];
                $success = true;
            }
            if (preg_match('/(\d+)% packet loss/', $output, $matches)) {
                $packetLoss = (float)$matches[1];
            }
        }

        return [
            'success' => $success && $packetLoss < 100,
            'latency' => $latency,
            'packet_loss' => $packetLoss,
            'output' => $output
        ];
    }
}
