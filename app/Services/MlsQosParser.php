<?php

namespace App\Services;

class MlsQosParser
{
    /**
     * Parse raw `show mls qos interface [<if>] statistics` output.
     *
     * Returns a list of per-interface associative arrays matching SwitchQosStat fillable
     * columns (minus device/branch/polled_at which the caller sets).
     */
    public function parse(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", $raw);
        $count = count($lines);

        $results = [];
        $current = null;
        $i = 0;

        while ($i < $count) {
            $trimmed = trim($lines[$i]);

            // Interface header line — e.g. "GigabitEthernet0/1" or "TenGigabitEthernet1/2".
            if (preg_match('/^([A-Z][a-zA-Z]+\d+\/\d+(?:\/\d+)?)$/', $trimmed, $m)) {
                if ($current !== null) {
                    $results[] = $current;
                }
                $current = $this->emptyInterface($m[1]);
                $i++;
                continue;
            }

            if ($current === null) {
                $i++;
                continue;
            }

            if (str_contains($trimmed, 'output queues enqueued:')) {
                $i = $this->readQueueMatrix($lines, $i + 1, $current, 'enq');
                continue;
            }

            if (str_contains($trimmed, 'output queues dropped:')) {
                $i = $this->readQueueMatrix($lines, $i + 1, $current, 'drop');
                continue;
            }

            if (preg_match('/Policer:\s*Inprofile:\s*(\d+)\s+OutofProfile:\s*(\d+)/i', $trimmed, $m)) {
                $current['policer_in_profile'] = (int) $m[1];
                $current['policer_out_of_profile'] = (int) $m[2];
            }

            $i++;
        }

        if ($current !== null) {
            $results[] = $current;
        }

        foreach ($results as &$r) {
            $total = 0;
            for ($q = 0; $q <= 3; $q++) {
                for ($t = 1; $t <= 3; $t++) {
                    $total += (int) $r["q{$q}_t{$t}_drop"];
                }
            }
            $r['total_drops'] = $total;
        }

        return $results;
    }

    private function emptyInterface(string $name): array
    {
        $data = ['interface_name' => $name];
        for ($q = 0; $q <= 3; $q++) {
            for ($t = 1; $t <= 3; $t++) {
                $data["q{$q}_t{$t}_enq"] = 0;
                $data["q{$q}_t{$t}_drop"] = 0;
            }
        }
        $data['policer_in_profile'] = 0;
        $data['policer_out_of_profile'] = 0;
        return $data;
    }

    private function readQueueMatrix(array $lines, int $start, array &$current, string $suffix): int
    {
        $count = count($lines);
        $i = $start;
        $found = 0;

        while ($i < $count && $found < 4) {
            $line = trim($lines[$i]);

            if (preg_match('/^queue\s+(\d+)\s*:\s*(\d+)\s+(\d+)\s+(\d+)\s*$/', $line, $m)) {
                $q = (int) $m[1];
                if ($q >= 0 && $q <= 3) {
                    $current["q{$q}_t1_{$suffix}"] = (int) $m[2];
                    $current["q{$q}_t2_{$suffix}"] = (int) $m[3];
                    $current["q{$q}_t3_{$suffix}"] = (int) $m[4];
                }
                $found++;
            }

            $i++;
        }

        return $i;
    }
}
