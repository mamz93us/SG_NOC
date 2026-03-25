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
                    // Only store packets that have MOS data — this is the final
                    // end-of-call summary. Interim RTCP-XR reports (no MOS) are dropped.
                    if ($data['mos_lq'] === null) {
                        continue;
                    }

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

                    // Write directly to DB — one row per CallID (upsert)
                    // Rule: never overwrite a record that already has MOS data
                    // with a late intermediate packet that has no MOS. This prevents
                    // periodic mid-call reports from erasing the final summary report.
                    $callId = $data['call_id'] ?? null;
                    unset($data['call_id']);

                    // At this point $data['mos_lq'] is guaranteed non-null (filtered above).
                    if ($callId) {
                        // Composite key: (call_id + extension)
                        // → same call, same phone = update (dedup periodic reports)
                        // → same call, other phone = new record (both sides stored)
                        VoiceQualityReport::updateOrCreate(
                            ['call_id'   => $callId,
                             'extension' => $data['extension'] ?? ''],
                            $data
                        );
                    } else {
                        // No call_id — create unconditionally (we already have MOS)
                        VoiceQualityReport::create($data);
                    }

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

        // ── Build a flat key→value map from the VQ body ───────────────────────
        //
        // Grandstream GRP phones send lines in two formats:
        //
        //   1. Simple:   LocalID:"6001" <sip:6001@10.9.8.140>
        //   2. Compound: QualityEst:MOSLQ=4.400 MOSCQ=4.400
        //                Timestamps:START=2026-03-25T10:37:01Z STOP=2026-03-25T10:37:05Z
        //                SessionDesc:PT=0 PD=PCMU SR=8000 FD=10
        //                Delay:RTD=7 ESD=140 SOWD=143 IAJ=8
        //                JitterBuffer:JBA=0 JBR=16 JBN=100 JBM=90 JBX=2000
        //                PacketLoss:NLR=0.00
        //
        // Strategy: for each line split at the first ':', then extract ALL
        // KEY=VALUE tokens from the right-hand side into $fields.

        $fields   = [];
        $rawLines = []; // also keep raw line values by section name

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Must have a colon to be a VQ field
            $colonPos = strpos($line, ':');
            if ($colonPos === false) continue;

            $section  = strtolower(substr($line, 0, $colonPos));
            $rest     = trim(substr($line, $colonPos + 1));

            // Store the raw section value (for LocalID, RemoteID, RemoteAddr etc.)
            $rawLines[$section] = $rest;

            // Extract all KEY=VALUE tokens from the rest of the line
            // e.g. "MOSLQ=4.400 MOSCQ=4.400" → moslq=4.400, moscq=4.400
            if (preg_match_all('/([A-Z][A-Z0-9]*)=([\S]+)/i', $rest, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $key = strtolower($m[1]);
                    if (!isset($fields[$key])) {   // first occurrence wins
                        $fields[$key] = $m[2];
                    }
                }
            }
        }

        if (empty($rawLines)) return null;

        // ── Call ID (SIP CallID header) ───────────────────────────────────────
        $callId = trim($rawLines['callid'] ?? $rawLines['call-id'] ?? '');

        // ── Extension ────────────────────────────────────────────────────────
        $localId  = $this->extractExtension($rawLines['localid']  ?? $rawLines['local']  ?? '');
        $remoteId = $this->extractExtension($rawLines['remoteid'] ?? $rawLines['remote'] ?? '');

        // ── Codec: SessionDesc:PT=0 PD=PCMU SR=8000 → fields['pd'] = PCMU ───
        $codec = $fields['pd'] ?? null;

        // ── Timestamps: fields['start'] / fields['stop'] ─────────────────────
        $startTime = $this->parseTimestamp($fields['start'] ?? $fields['starttime'] ?? '');
        $stopTime  = $this->parseTimestamp($fields['stop']  ?? $fields['stoptime']  ?? '');
        $duration  = ($startTime && $stopTime) ? max(0, $stopTime - $startTime) : null;

        // ── Quality: QualityEst:MOSLQ=4.400 MOSCQ=4.400 ─────────────────────
        $mosLq = $this->floatOrNull($fields['moslq'] ?? null);
        $mosCq = $this->floatOrNull($fields['moscq'] ?? null);

        // ── Jitter ────────────────────────────────────────────────────────────
        // IAJ = Inter-Arrival Jitter from Delay: line  → jitter_avg (actual measured)
        // JBM = JitterBuffer Max delay from JitterBuffer: line → jitter_max (buffer capacity)
        // NOTE: do NOT fall back to JBN (nominal buffer size=100ms) as jitter_avg —
        //       JBN is a configuration value, not a measurement of actual jitter.
        $jitterAvg = $this->floatOrNull($fields['iaj'] ?? null);
        $jitterMax = $this->floatOrNull($fields['jbm'] ?? null);

        // ── Packet loss: PacketLoss:NLR=0.00 PLC=... NLC=... ─────────────────
        // NLR = Network Loss Rate (%) — 0.0 is valid (no loss), do NOT use floatOrNull
        $packetLoss = isset($fields['nlr']) && $fields['nlr'] !== ''
            ? (float) $fields['nlr']
            : null;
        // NLC = raw lost packet count (integer)
        $packetsLost = isset($fields['nlc']) && $fields['nlc'] !== ''
            ? (int) $fields['nlc']
            : null;

        // ── RTT / One-way delays: Delay:RTD=7 ESD=140 SOWD=143 IAJ=8 ────────
        $rtt  = isset($fields['rtd'])  ? (int) $fields['rtd']  : null;
        $sowd = isset($fields['sowd']) ? (int) $fields['sowd'] : null;
        $esd  = isset($fields['esd'])  ? (int) $fields['esd']  : null;

        // ── Remote IP / Remote port ───────────────────────────────────────────
        $remoteIp = $fromIp;
        if (!empty($rawLines['remoteaddr'])) {
            if (preg_match('/IP=([\d\.]+)/i', $rawLines['remoteaddr'], $m)) {
                $remoteIp = $m[1];
            }
        }

        return [
            'call_id'               => $callId   ?: null,
            'extension'             => $localId  ?: null,
            'remote_extension'      => $remoteId ?: null,
            'remote_ip'             => $remoteIp,
            'codec'                 => $codec,
            'mos_lq'                => $mosLq,
            'mos_cq'                => $mosCq,
            'r_factor'              => null,   // not in GRP format
            'jitter_avg'            => $jitterAvg,   // IAJ — matches UCM "Jitter"
            'jitter_max'            => $jitterMax,   // JBM — matches UCM "JitterBufferMax"
            'packet_loss'           => $packetLoss,  // NLR rate (0.0 stored, not null)
            'burst_loss'            => null,
            'packets_lost'          => $packetsLost, // NLC raw count
            'rtt'                   => $rtt,         // RTD ms
            'sowd'                  => $sowd,        // Symmetric One-Way Delay ms
            'esd'                   => $esd,         // End System Delay ms
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

        // Compact UTC: 20240325T143022Z — use gmmktime so Z is honoured
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z)?$/', $value, $m)) {
            if (!empty($m[7])) {
                // Z suffix → UTC → gmmktime
                return gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
            }
            return mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
        }

        // strtotime handles ISO-8601 "2026-03-25T10:37:01Z" — correctly treats Z as UTC
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
