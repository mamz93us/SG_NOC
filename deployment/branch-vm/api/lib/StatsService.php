<?php
/**
 * Collects host + DB + ingestion telemetry for the branch VM, returned
 * by the /api/stats endpoint. Used by the NOC's "Test" button on the
 * Branch Log Collectors page to surface disk / RAM / DB-size / ingest
 * rate without anyone having to SSH in.
 */

declare(strict_types=1);

class StatsService
{
    public function __construct(private PDO $pdo) {}

    public function collect(): array
    {
        return [
            'host'      => [
                'time'      => gmdate('c'),
                'uptime_s'  => $this->uptimeSeconds(),
                'load_1min' => function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? null) : null,
            ],
            'disk'      => $this->diskUsage('/'),
            'ram'       => $this->ramUsage(),
            'db'        => $this->dbStats(),
            'ingestion' => $this->ingestionStats(),
        ];
    }

    private function diskUsage(string $path): array
    {
        $total = (float) (@disk_total_space($path) ?: 0);
        $free  = (float) (@disk_free_space($path)  ?: 0);
        $used  = max(0.0, $total - $free);
        return [
            'path'      => $path,
            'total_gb'  => round($total / 1073741824, 2),
            'used_gb'   => round($used  / 1073741824, 2),
            'free_gb'   => round($free  / 1073741824, 2),
            'used_pct'  => $total > 0 ? (int) round(($used / $total) * 100) : 0,
        ];
    }

    private function ramUsage(): array
    {
        $info = [];
        if (is_readable('/proc/meminfo')) {
            foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                    $info[$m[1]] = (int) $m[2];   // values are kB
                }
            }
        }
        $total = $info['MemTotal']     ?? 0;
        $avail = $info['MemAvailable'] ?? 0;
        $used  = max(0, $total - $avail);
        return [
            'total_mb' => (int) round($total / 1024),
            'used_mb'  => (int) round($used  / 1024),
            'free_mb'  => (int) round($avail / 1024),
            'used_pct' => $total > 0 ? (int) round(($used / $total) * 100) : 0,
        ];
    }

    private function uptimeSeconds(): int
    {
        if (!is_readable('/proc/uptime')) return 0;
        return (int) (float) explode(' ', (string) file_get_contents('/proc/uptime'))[0];
    }

    private function dbStats(): array
    {
        $rows = (int) $this->pdo->query("SELECT COUNT(*) FROM syslog_messages")->fetchColumn();

        $sizeRow = $this->pdo->query("
            SELECT
                ROUND(SUM(data_length+index_length)/1024/1024/1024, 2) AS gb,
                ROUND(SUM(data_length)/1024/1024/1024, 2) AS data_gb,
                ROUND(SUM(index_length)/1024/1024/1024, 2) AS idx_gb
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name   = 'syslog_messages'
        ")->fetch();

        $partRow = $this->pdo->query("
            SELECT
                MIN(partition_name) AS oldest,
                MAX(partition_name) AS newest,
                COUNT(*)            AS partitions
            FROM information_schema.partitions
            WHERE table_schema = DATABASE()
              AND table_name   = 'syslog_messages'
              AND partition_name REGEXP '^p[0-9]{8}$'
        ")->fetch();

        return [
            'rows'             => $rows,
            'size_gb'          => (float) ($sizeRow['gb']      ?? 0),
            'data_gb'          => (float) ($sizeRow['data_gb'] ?? 0),
            'index_gb'         => (float) ($sizeRow['idx_gb']  ?? 0),
            'partitions'       => (int)   ($partRow['partitions'] ?? 0),
            'oldest_partition' => $partRow['oldest'] ?? null,
            'newest_partition' => $partRow['newest'] ?? null,
        ];
    }

    private function ingestionStats(): array
    {
        $row = $this->pdo->query("
            SELECT
                COUNT(*)            AS n5,
                MAX(received_at)    AS last_seen
            FROM syslog_messages
            WHERE received_at > UTC_TIMESTAMP() - INTERVAL 5 MINUTE
        ")->fetch();

        $n5    = (int) ($row['n5'] ?? 0);
        $spool = @filesize('/var/spool/sg-noc-ingest/queue.jsonl') ?: 0;

        return [
            'rows_last_5min'   => $n5,
            'rows_per_sec'     => $n5 > 0 ? round($n5 / 300, 1) : 0,
            'last_message_at'  => $row['last_seen'] ?? null,
            'spool_size_bytes' => (int) $spool,
        ];
    }
}
