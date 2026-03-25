<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\VoiceQualityReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VqCollectorDaemon extends Command
{
    protected $signature   = 'vq:collect {--port=5099} {--debug}';
    protected $description = 'Listen for SIP NOTIFY vq-rtcpxr packets and store voice quality reports';

    public function handle(): int
    {
        $port  = (int) $this->option('port');
        $debug = (bool) $this->option('debug');

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$socket) {
            $this->error("Cannot create UDP socket: " . socket_strerror(socket_last_error()));
            return 1;
        }

        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!@socket_bind($socket, '0.0.0.0', $port)) {
            $this->error("Cannot bind on port {$port}: " . socket_strerror(socket_last_error($socket)));
            return 1;
        }

        $this->info("VQ Collector listening on UDP port {$port}...");

        // Cache branches for IP matching
        $branches = Branch::all();

        while (true) {
            $buf = $from = '';
            $fromPort = 0;
            $bytes = @socket_recvfrom($socket, $buf, 65535, 0, $from, $fromPort);

            if ($bytes === false) {
                usleep(10000);
                continue;
            }

            try {
                if ($debug) {
                    $this->line("--- RAW PACKET from {$from}:{$fromPort} ---");
                    $this->line($buf);
                    $this->line("--- END ---");
                }

                $data = $this->parseVqPacket($buf, $from);

                if ($data) {
                    // Resolve branch from remote IP
                    $branch = $branches->first(fn($b) =>
                        !empty($b->ip_range) && $this->ipInRange($from, $b->ip_range)
                    );
                    $data['branch_id'] = $branch?->id;
                    $data['branch']    = $branch?->name;

                    // Set quality label
                    if (!empty($data['mos_lq']) && $data['mos_lq'] > 0) {
                        $data['quality_label'] = VoiceQualityReport::mosLabel((float) $data['mos_lq']);
                    }

                    // Write directly to DB — no HTTP roundtrip
                    VoiceQualityReport::create($data);

                    $this->info(sprintf(
                        "[VQ] ext=%s remote=%s MOS-LQ=%.2f codec=%s branch=%s",
                        $data['extension']        ?? '?',
                        $data['remote_extension'] ?? '?',
                        $data['mos_lq']           ?? 0,
                        $data['codec']            ?? '?',
                        $data['branch']           ?? 'unknown'
                    ));
                }
            } catch (\Throwable $e) {
                $this->error("Parse error: " . $e->getMessage());
                Log::error("VqCollector: " . $e->getMessage());
            }
        }
    }

    // ─── Parser ──────────────────────────────────────────────────────────────

    private function parseVqPacket(string $raw, string $fromIp): ?array
    {
        if (!str_contains($raw, 'vq-rtcpxr') && !str_contains($raw, 'VQSessionReport')) {
            return null;
        }

        // Flatten all lines (including indented ones) into a key→value map.
        // Grandstream UCM uses indented sub-sections like:
        //   Jitter:
        //     JitterAvg: 5
        //     JitterMax: 12
        //   QualityEst:
        //     MOSLQ: 4.1
        //     MOSCQ: 4.0
        $fields = [];
        foreach (explode("\n", $raw) as $line) {
            $trimmed = trim($line);
            // Match "Key: value" or "Key=value" (with any leading whitespace)
            if (preg_match('/^([\w\-]+)\s*[:=]\s*(.+)$/', $trimmed, $m)) {
                $key = strtolower(trim($m[1]));
                $val = trim($m[2]);
                // Only store first occurrence so outer fields don't get overwritten
                if (!isset($fields[$key])) {
                    $fields[$key] = $val;
                }
            }
        }

        if (empty($fields)) return null;

        // ── Extension: strip SIP URI, keep only the user part ────────────────
        // Input examples:
        //   "6001" <sip:6001@10.9.8.10>
        //   sip:6001@10.9.8.10
        //   6001
        $localId  = $this->extractExtension($fields['localid']  ?? $fields['local']  ?? '');
        $remoteId = $this->extractExtension($fields['remoteid'] ?? $fields['remote'] ?? '');

        // ── Codec: strip payload number, keep name only ───────────────────────
        // Input: "PCMU 8"  →  "PCMU"
        $codec = null;
        if (!empty($fields['payloadtype'])) {
            $codec = preg_split('/\s+/', trim($fields['payloadtype']))[0] ?? null;
        }

        // ── Timestamps ───────────────────────────────────────────────────────
        // Grandstream format: 20240325T143022Z  or  2024-03-25T14:30:22Z
        $startTime = $this->parseTimestamp($fields['starttime'] ?? '');
        $stopTime  = $this->parseTimestamp($fields['stoptime']  ?? '');
        $duration  = ($startTime && $stopTime) ? max(0, $stopTime - $startTime) : null;

        // ── MOS / Quality metrics ─────────────────────────────────────────────
        $mosLq = $this->floatOrNull($fields['moslq'] ?? $fields['mos-lq'] ?? null);
        $mosCq = $this->floatOrNull($fields['moscq'] ?? $fields['mos-cq'] ?? null);

        // ── Remote IP from RemoteAddr field ──────────────────────────────────
        // Input: "IP=10.9.8.12 Port=10000 Ssrc=xxx"
        $remoteIp = $fromIp;
        if (!empty($fields['remoteaddr'])) {
            if (preg_match('/IP=([\d\.]+)/i', $fields['remoteaddr'], $m)) {
                $remoteIp = $m[1];
            }
        }

        return [
            'extension'             => $localId  ?: null,
            'remote_extension'      => $remoteId ?: null,
            'remote_ip'             => $remoteIp,
            'codec'                 => $codec,
            'mos_lq'                => $mosLq,
            'mos_cq'                => $mosCq,
            'r_factor'              => $this->floatOrNull($fields['rfactor']       ?? $fields['r-factor']       ?? null),
            'jitter_avg'            => $this->floatOrNull($fields['jitteravg']     ?? $fields['jitter-avg']     ?? null),
            'jitter_max'            => $this->floatOrNull($fields['jittermax']     ?? $fields['jitter-max']     ?? null),
            'packet_loss'           => $this->floatOrNull($fields['packetlossrate']?? $fields['packet-loss']    ?? null),
            'burst_loss'            => $this->floatOrNull($fields['burstlossrate'] ?? $fields['burst-loss']     ?? null),
            'rtt'                   => isset($fields['rtdelay']) ? (int) $fields['rtdelay'] : null,
            'call_start'            => $startTime ? date('Y-m-d H:i:s', $startTime) : null,
            'call_end'              => $stopTime  ? date('Y-m-d H:i:s', $stopTime)  : null,
            'call_duration_seconds' => $duration,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Extract just the user/extension number from a SIP URI or display string.
     * "6001" <sip:6001@10.9.8.10>  →  6001
     * sip:6001@10.9.8.10           →  6001
     * 6001                         →  6001
     */
    private function extractExtension(string $value): string
    {
        $value = trim($value, " \t\r\n\"'");

        // sip:USER@host  or  sips:USER@host
        if (preg_match('/<?\s*sips?:([^@>;\s]+)/i', $value, $m)) {
            return $m[1];
        }

        // Plain number or short string
        return preg_replace('/[^\w\-\.]/', '', $value);
    }

    /**
     * Parse various timestamp formats used by Grandstream UCM:
     *   20240325T143022Z
     *   2024-03-25T14:30:22Z
     *   2024-03-25 14:30:22
     */
    private function parseTimestamp(string $value): ?int
    {
        if (empty($value)) return null;

        // Compact: 20240325T143022Z
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/', $value, $m)) {
            return mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
        }

        $ts = strtotime($value);
        return $ts !== false ? $ts : null;
    }

    private function floatOrNull(mixed $val): ?float
    {
        if ($val === null || $val === '') return null;
        $f = (float) $val;
        return $f > 0 ? $f : null;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        if (str_contains($range, '/')) {
            [$net, $mask] = explode('/', $range);
            $ipLong  = ip2long($ip);
            $netLong = ip2long($net);
            $maskLong = ~((1 << (32 - (int)$mask)) - 1);
            return ($ipLong & $maskLong) === ($netLong & $maskLong);
        }
        return $ip === $range;
    }
}
