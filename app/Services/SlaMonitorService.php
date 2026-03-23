<?php

namespace App\Services;

use App\Models\IspConnection;
use App\Models\LinkCheck;
use Carbon\Carbon;

class SlaMonitorService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Ping the ISP gateway/static IP and record the result.
     * If the check fails (100% packet loss), notify admins.
     */
    public function checkLink(IspConnection $isp): LinkCheck
    {
        $target = $isp->gateway ?: $isp->static_ip;

        if (!$target) {
            return LinkCheck::create([
                'isp_id'      => $isp->id,
                'latency'     => null,
                'packet_loss' => 100,
                'success'     => false,
                'checked_at'  => now(),
            ]);
        }

        $result = $this->ping($target);

        $check = LinkCheck::create([
            'isp_id'      => $isp->id,
            'latency'     => $result['latency'],
            'packet_loss' => $result['packet_loss'],
            'success'     => $result['success'],
            'checked_at'  => now(),
        ]);

        // Notify admins on complete link failure
        if (!$result['success']) {
            $this->notifications->notifyAdmins(
                'system_alert',
                "ISP Link Down: {$isp->name}",
                "ISP connection '{$isp->name}' at {$target} is not responding (100% packet loss).",
                null,
                'critical'
            );
        }

        return $check;
    }

    /**
     * Calculate monthly uptime percentage.
     */
    public function monthlyUptime(int $ispId, ?Carbon $month = null): float
    {
        $month = $month ?? now();
        $start = $month->copy()->startOfMonth();
        $end   = $month->copy()->endOfMonth();

        $total   = LinkCheck::where('isp_id', $ispId)->whereBetween('checked_at', [$start, $end])->count();
        $success = LinkCheck::where('isp_id', $ispId)->whereBetween('checked_at', [$start, $end])->where('success', true)->count();

        return $total > 0 ? round(($success / $total) * 100, 2) : 0;
    }

    public function avgLatency(int $ispId, ?Carbon $month = null): float
    {
        $month = $month ?? now();
        $start = $month->copy()->startOfMonth();
        $end   = $month->copy()->endOfMonth();

        return round(
            LinkCheck::where('isp_id', $ispId)
                ->whereBetween('checked_at', [$start, $end])
                ->where('success', true)
                ->whereNotNull('latency')
                ->avg('latency') ?? 0,
            2
        );
    }

    public function avgPacketLoss(int $ispId, ?Carbon $month = null): float
    {
        $month = $month ?? now();
        $start = $month->copy()->startOfMonth();
        $end   = $month->copy()->endOfMonth();

        return round(
            LinkCheck::where('isp_id', $ispId)
                ->whereBetween('checked_at', [$start, $end])
                ->avg('packet_loss') ?? 0,
            2
        );
    }

    private function ping(string $target, int $count = 3): array
    {
        $isWin  = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $cmd    = $isWin
            ? "ping -n {$count} -w 2000 " . escapeshellarg($target) . " 2>&1"
            : "ping -c {$count} -W 2 " . escapeshellarg($target) . " 2>&1";

        exec($cmd, $lines, $code);
        $output = implode("\n", $lines);

        $latency    = null;
        $packetLoss = 100;

        if ($isWin) {
            if (preg_match('/Average\s*=\s*(\d+)ms/i', $output, $m)) { $latency = (float) $m[1]; }
            if (preg_match('/\((\d+)%\s*loss\)/i', $output, $m))      { $packetLoss = (float) $m[1]; }
        } else {
            if (preg_match('/rtt min\/avg\/max\/mdev\s*=\s*[\d.]+\/([\d.]+)\//i', $output, $m)) { $latency = (float) $m[1]; }
            if (preg_match('/(\d+(?:\.\d+)?)%\s*packet loss/i', $output, $m))                   { $packetLoss = (float) $m[1]; }
        }

        $success = $packetLoss < 100 && $latency !== null;

        return compact('latency', 'packetLoss', 'success');
    }
}
