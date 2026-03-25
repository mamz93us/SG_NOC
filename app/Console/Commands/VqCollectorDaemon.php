<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VqCollectorDaemon extends Command
{
    protected $signature   = 'vq:collect {--port=5099}';
    protected $description = 'Listen for SIP NOTIFY vq-rtcpxr packets and store voice quality reports';

    public function handle(): int
    {
        $port   = (int) $this->option('port');
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if (!$socket) {
            $this->error("Cannot create UDP socket: " . socket_strerror(socket_last_error()));
            return 1;
        }

        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!@socket_bind($socket, '0.0.0.0', $port)) {
            $this->error("Cannot bind UDP socket on port {$port}: " . socket_strerror(socket_last_error($socket)));
            return 1;
        }

        $this->info("VQ Collector listening on UDP port {$port}...");

        while (true) {
            $buf  = '';
            $from = '';
            $fromPort = 0;
            $bytes = @socket_recvfrom($socket, $buf, 65535, 0, $from, $fromPort);

            if ($bytes === false) {
                usleep(10000);
                continue;
            }

            try {
                $data = $this->parseVqPacket($buf, $from);
                if ($data) {
                    $this->info(sprintf(
                        "[VQ] ext=%s remote=%s MOS-LQ=%.2f codec=%s",
                        $data['extension'] ?? '?',
                        $data['remote_extension'] ?? '?',
                        $data['mos_lq'] ?? 0,
                        $data['codec'] ?? '?'
                    ));

                    Http::post(url('/api/internal/vq-report'), $data);
                }
            } catch (\Throwable $e) {
                Log::error("VqCollector: " . $e->getMessage());
            }
        }
    }

    private function parseVqPacket(string $raw, string $fromIp): ?array
    {
        // Only handle packets with vq-rtcpxr body
        if (!str_contains($raw, 'vq-rtcpxr') && !str_contains($raw, 'VQSessionReport')) {
            return null;
        }

        $fields = [];
        // Parse key:value lines (handles VQSessionReport format)
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (preg_match('/^([\w\-]+)\s*[=:]\s*(.+)$/', $line, $m)) {
                $fields[strtolower(trim($m[1]))] = trim($m[2]);
            }
        }

        if (empty($fields)) return null;

        $startTime = isset($fields['starttime']) ? strtotime($fields['starttime']) : null;
        $stopTime  = isset($fields['stoptime'])  ? strtotime($fields['stoptime'])  : null;
        $duration  = ($startTime && $stopTime)   ? max(0, $stopTime - $startTime)  : null;

        $mosLq = isset($fields['moslq'])    ? (float) $fields['moslq']    : null;
        $mosCq = isset($fields['moscq'])    ? (float) $fields['moscq']    : null;

        return [
            'extension'              => $fields['localid']        ?? $fields['local']  ?? null,
            'remote_extension'       => $fields['remoteid']       ?? $fields['remote'] ?? null,
            'remote_ip'              => $fields['remoteip']       ?? $fromIp,
            'codec'                  => $fields['payloadtype']    ?? $fields['codec']  ?? null,
            'mos_lq'                 => $mosLq,
            'mos_cq'                 => $mosCq,
            'r_factor'               => isset($fields['rfactor'])        ? (float) $fields['rfactor']        : null,
            'jitter_avg'             => isset($fields['jitteravg'])       ? (float) $fields['jitteravg']       : null,
            'jitter_max'             => isset($fields['jittermax'])       ? (float) $fields['jittermax']       : null,
            'packet_loss'            => isset($fields['packetlossrate'])  ? (float) $fields['packetlossrate']  : null,
            'burst_loss'             => isset($fields['burstlossrate'])   ? (float) $fields['burstlossrate']   : null,
            'rtt'                    => isset($fields['rtdelay'])         ? (int)   $fields['rtdelay']         : null,
            'call_start'             => $startTime ? date('Y-m-d H:i:s', $startTime) : null,
            'call_end'               => $stopTime  ? date('Y-m-d H:i:s', $stopTime)  : null,
            'call_duration_seconds'  => $duration,
        ];
    }
}
