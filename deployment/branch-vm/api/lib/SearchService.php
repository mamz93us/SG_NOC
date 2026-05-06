<?php
/**
 * Builds parameterized SELECT and aggregate queries against
 * syslog_messages. All user input goes through PDO params — no string
 * concatenation into SQL.
 */

declare(strict_types=1);

class SearchService
{
    public function __construct(private PDO $pdo) {}

    /**
     * GET /api/logs/search
     *
     * Accepted query params:
     *   from       (ISO-8601 datetime, default = now - 1h)
     *   to         (ISO-8601 datetime, default = now)
     *   source     (substring, anchored at start with %)
     *   q          (substring search in message)
     *   severity   (max severity, 0..7; defaults to 7 = all)
     *   program    (substring, anchored at start with %)
     *   sophos_subtype  (exact match, e.g. "Denied")
     *   limit      (1..1000, default 200)
     *   offset     (0..50000, default 0)
     */
    public function search(array $q): array
    {
        [$from, $to] = $this->dateRange($q);
        $limit  = $this->boundedInt($q['limit']  ?? null, 200, 1, 1000);
        $offset = $this->boundedInt($q['offset'] ?? null, 0,   0, 50000);

        $where  = ['received_at >= :from', 'received_at < :to'];
        $params = [':from' => $from, ':to' => $to];

        if (!empty($q['source'])) {
            $where[] = 'source LIKE :source';
            $params[':source'] = '%' . trim((string) $q['source']) . '%';
        }
        if (!empty($q['program'])) {
            $where[] = 'program LIKE :program';
            $params[':program'] = '%' . trim((string) $q['program']) . '%';
        }
        if (!empty($q['q'])) {
            $where[] = 'message LIKE :q';
            $params[':q'] = '%' . trim((string) $q['q']) . '%';
        }
        if (isset($q['severity']) && $q['severity'] !== '') {
            $where[] = 'severity <= :severity';
            $params[':severity'] = $this->boundedInt($q['severity'], 7, 0, 7);
        }
        if (!empty($q['sophos_subtype'])) {
            $where[] = 'sophos_log_subtype = :sub';
            $params[':sub'] = (string) $q['sophos_subtype'];
        }
        if (!empty($q['is_sophos'])) {
            // Restrict to messages parsed as Sophos firewall logs
            $where[] = 'sophos_log_subtype IS NOT NULL';
        }
        if (!empty($q['sophos_dst_ip'])) {
            $where[] = 'sophos_dst_ip = :dst_ip';
            $params[':dst_ip'] = (string) $q['sophos_dst_ip'];
        }
        if (!empty($q['sophos_src_ip'])) {
            $where[] = 'sophos_src_ip = :src_ip';
            $params[':src_ip'] = (string) $q['sophos_src_ip'];
        }

        $whereSql = implode(' AND ', $where);

        // Two queries: one for count (full), one for paginated data
        $started = microtime(true);

        $countSql = "SELECT COUNT(*) FROM syslog_messages WHERE $whereSql";
        $countSt  = $this->pdo->prepare($countSql);
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        $dataSql = "SELECT id, received_at, branch, source, source_ip, program,
                           facility, severity, message,
                           sophos_log_type, sophos_log_subtype, sophos_src_ip,
                           sophos_dst_ip, sophos_src_port, sophos_dst_port,
                           sophos_fw_rule_name
                    FROM syslog_messages
                    WHERE $whereSql
                    ORDER BY received_at DESC, id DESC
                    LIMIT :limit OFFSET :offset";
        $dataSt = $this->pdo->prepare($dataSql);
        foreach ($params as $k => $v) {
            $dataSt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $dataSt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $dataSt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataSt->execute();
        $rows = $dataSt->fetchAll();

        return [
            'ok'       => true,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'took_ms'  => (int) ((microtime(true) - $started) * 1000),
            'results'  => $rows,
        ];
    }

    /**
     * GET /api/logs/aggregate
     *
     * Accepted query params: same time/filter shape as /search, plus:
     *   field   (one of: source, program, severity, sophos_log_subtype,
     *           sophos_dst_ip)
     *   limit   (1..50, default 20) — returns top N buckets
     */
    public function aggregate(array $q): array
    {
        $allowed = ['source', 'program', 'severity', 'sophos_log_subtype',
                    'sophos_dst_ip', 'sophos_log_type', 'sophos_fw_rule_name'];
        $field = in_array($q['field'] ?? '', $allowed, true)
                    ? (string) $q['field']
                    : 'source';

        [$from, $to] = $this->dateRange($q);
        $limit = $this->boundedInt($q['limit'] ?? null, 20, 1, 50);

        $where  = ['received_at >= :from', 'received_at < :to'];
        $params = [':from' => $from, ':to' => $to];

        if (!empty($q['source'])) {
            $where[] = 'source LIKE :source';
            $params[':source'] = '%' . trim((string) $q['source']) . '%';
        }
        if (!empty($q['q'])) {
            $where[] = 'message LIKE :q';
            $params[':q'] = '%' . trim((string) $q['q']) . '%';
        }

        $whereSql = implode(' AND ', $where);
        $started = microtime(true);

        // $field is whitelisted above so safe to interpolate
        $sql = "SELECT $field AS bucket_key, COUNT(*) AS bucket_count
                FROM syslog_messages
                WHERE $whereSql AND $field IS NOT NULL AND $field <> ''
                GROUP BY $field
                ORDER BY bucket_count DESC
                LIMIT :limit";

        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();
        $buckets = $st->fetchAll();

        return [
            'ok'      => true,
            'field'   => $field,
            'took_ms' => (int) ((microtime(true) - $started) * 1000),
            'buckets' => array_map(fn($b) => [
                'key'   => $b['bucket_key'],
                'count' => (int) $b['bucket_count'],
            ], $buckets),
        ];
    }

    private function dateRange(array $q): array
    {
        $tz = new DateTimeZone('UTC');
        try {
            $from = !empty($q['from']) ? new DateTimeImmutable((string) $q['from']) : new DateTimeImmutable('-1 hour');
        } catch (Exception) {
            throw new InvalidArgumentException('invalid "from" datetime');
        }
        try {
            $to = !empty($q['to']) ? new DateTimeImmutable((string) $q['to']) : new DateTimeImmutable('now');
        } catch (Exception) {
            throw new InvalidArgumentException('invalid "to" datetime');
        }
        if ($from >= $to) throw new InvalidArgumentException('"from" must be before "to"');
        return [
            $from->setTimezone($tz)->format('Y-m-d H:i:s.v'),
            $to->setTimezone($tz)->format('Y-m-d H:i:s.v'),
        ];
    }

    private function boundedInt($v, int $default, int $min, int $max): int
    {
        if ($v === null || $v === '') return $default;
        $n = (int) $v;
        if ($n < $min) return $min;
        if ($n > $max) return $max;
        return $n;
    }
}
