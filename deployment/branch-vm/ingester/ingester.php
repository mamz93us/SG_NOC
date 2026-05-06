<?php
/**
 * SG_NOC Branch VM — Syslog ingester.
 *
 * Tails /var/spool/sg-noc-ingest/queue.jsonl, parses each JSON line that
 * rsyslog wrote, extracts structured Sophos KV fields if applicable, and
 * INSERTs into syslog_messages using a parameterized prepared statement
 * (so embedded quotes/backslashes/newlines don't break SQL like ommysql
 * did before).
 *
 * Run by sg-noc-ingester.service. Resume position is persisted to
 * /var/lib/sg-noc-ingest/position.json so a crash or restart picks up
 * where we left off — no message loss, no double-write.
 */

declare(strict_types=1);

const QUEUE_FILE   = '/var/spool/sg-noc-ingest/queue.jsonl';
const STATE_FILE   = '/var/lib/sg-noc-ingest/position.json';
const ENV_FILE     = '/etc/sg-noc-branch.env';
const BATCH_SIZE   = 200;        // rows per multi-row INSERT
const POLL_MS      = 100;        // sleep between reads when at EOF
const MAX_LINE_LEN = 65536;      // hard cap to avoid runaway memory

// ─── Bootstrap ────────────────────────────────────────────────────────────

$env = parse_env_file(ENV_FILE);
$pdo = pdo_connect($env);

// Track where we left off in the queue file. If the file rotated/truncated
// (size shrunk below saved position), restart from beginning.
[$savedInode, $savedOffset] = load_state();

if (!file_exists(QUEUE_FILE)) {
    fwrite(STDERR, "Queue file " . QUEUE_FILE . " not present yet, waiting...\n");
    while (!file_exists(QUEUE_FILE)) {
        usleep(POLL_MS * 1000);
    }
}

$fh = fopen(QUEUE_FILE, 'rb');
if ($fh === false) {
    fwrite(STDERR, "Cannot open " . QUEUE_FILE . "\n");
    exit(1);
}

$inode = fstat_inode($fh);
$size  = filesize(QUEUE_FILE);

if ($inode === $savedInode && $savedOffset <= $size) {
    fseek($fh, $savedOffset);
    fwrite(STDERR, "Resuming at offset $savedOffset (inode $inode)\n");
} else {
    fwrite(STDERR, "New/rotated file (inode $inode), starting from beginning\n");
}

// Prepared insert — re-bound for every batch
$insert = $pdo->prepare(<<<SQL
    INSERT INTO syslog_messages (
        received_at, device_time, branch, source, source_ip, program,
        facility, severity, message,
        sophos_log_type, sophos_log_subtype, sophos_log_component,
        sophos_src_ip, sophos_dst_ip, sophos_src_port, sophos_dst_port,
        sophos_protocol, sophos_fw_rule_name, sophos_user_name, sophos_application
    ) VALUES (
        :received_at, :device_time, :branch, :source, :source_ip, :program,
        :facility, :severity, :message,
        :sophos_log_type, :sophos_log_subtype, :sophos_log_component,
        :sophos_src_ip, :sophos_dst_ip, :sophos_src_port, :sophos_dst_port,
        :sophos_protocol, :sophos_fw_rule_name, :sophos_user_name, :sophos_application
    )
SQL);

// Graceful shutdown on SIGTERM/SIGINT — finish the current batch, save state.
$shouldStop = false;
$signalHandler = function () use (&$shouldStop) { $shouldStop = true; };
pcntl_async_signals(true);
pcntl_signal(SIGTERM, $signalHandler);
pcntl_signal(SIGINT,  $signalHandler);

// ─── Main loop ────────────────────────────────────────────────────────────

$buffer = '';
$batch  = [];
$lastFlushAt = microtime(true);

while (!$shouldStop) {
    // Read whatever's available; up to 64 KB per call
    $chunk = fread($fh, 64 * 1024);

    if ($chunk === false) {
        fwrite(STDERR, "fread error, sleeping\n");
        usleep(POLL_MS * 1000);
        continue;
    }

    if ($chunk === '') {
        // At EOF — flush any pending batch, then check for file rotation
        if ($batch) {
            flush_batch($pdo, $insert, $batch);
            save_state(fstat_inode($fh), ftell($fh));
            $batch = [];
            $lastFlushAt = microtime(true);
        }

        // Did rsyslog rotate the file (different inode) or truncate?
        clearstatcache(true, QUEUE_FILE);
        $newSize = filesize(QUEUE_FILE);
        $newInode = (file_exists(QUEUE_FILE)) ? @stat(QUEUE_FILE)['ino'] : 0;
        if ($newInode !== $inode || $newSize < ftell($fh)) {
            fwrite(STDERR, "File rotated (was inode $inode, now $newInode); reopening\n");
            fclose($fh);
            $fh = fopen(QUEUE_FILE, 'rb');
            if ($fh === false) {
                usleep(POLL_MS * 1000); continue;
            }
            $inode = $newInode;
        }

        usleep(POLL_MS * 1000);
        continue;
    }

    $buffer .= $chunk;

    // Process all complete lines (up to the last \n)
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line   = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);

        if ($line === '' || strlen($line) > MAX_LINE_LEN) continue;

        $row = parse_line($line);
        if ($row !== null) {
            $batch[] = $row;
        }

        if (count($batch) >= BATCH_SIZE) {
            flush_batch($pdo, $insert, $batch);
            save_state(fstat_inode($fh), ftell($fh) - strlen($buffer));
            $batch = [];
            $lastFlushAt = microtime(true);
        }
    }

    // Flush at least every 1s even on partial batches, so search results
    // appear fast in the NOC UI.
    if ($batch && (microtime(true) - $lastFlushAt) > 1.0) {
        flush_batch($pdo, $insert, $batch);
        save_state(fstat_inode($fh), ftell($fh) - strlen($buffer));
        $batch = [];
        $lastFlushAt = microtime(true);
    }
}

// Final flush on shutdown
if ($batch) {
    flush_batch($pdo, $insert, $batch);
}
save_state(fstat_inode($fh), ftell($fh) - strlen($buffer));
fwrite(STDERR, "Stopped cleanly.\n");

// ─── Helpers ──────────────────────────────────────────────────────────────

function parse_env_file(string $path): array {
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#') continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v, "\"' \t");
        $out[trim($k)] = $v;
    }
    return $out;
}

function pdo_connect(array $env): PDO {
    $dsn = "mysql:host=127.0.0.1;dbname={$env['DB_NAME']};charset=utf8mb4";
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE             => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES    => false,
        PDO::MYSQL_ATTR_INIT_COMMAND  => "SET time_zone='+00:00'",
    ]);
    return $pdo;
}

function fstat_inode($fh): int {
    $s = fstat($fh);
    return $s ? (int) $s['ino'] : 0;
}

function load_state(): array {
    if (!file_exists(STATE_FILE)) return [0, 0];
    $j = @json_decode((string) file_get_contents(STATE_FILE), true);
    return [(int) ($j['inode'] ?? 0), (int) ($j['offset'] ?? 0)];
}

function save_state(int $inode, int $offset): void {
    @file_put_contents(STATE_FILE, json_encode([
        'inode'   => $inode,
        'offset'  => $offset,
        'saved_at' => gmdate('c'),
    ]), LOCK_EX);
}

/**
 * Parse one JSON line from rsyslog into the column shape we INSERT.
 * Returns null on parse failure (silently dropped — bad data shouldn't
 * crash the ingester).
 */
function parse_line(string $line): ?array {
    $j = json_decode($line, true);
    if (!is_array($j)) return null;

    $msg = (string) ($j['message'] ?? '');
    $row = [
        'received_at' => normalize_ts($j['received_at'] ?? null),
        'device_time' => normalize_ts($j['device_time'] ?? null),
        'branch'      => substr((string) ($j['branch'] ?? ''), 0, 8),
        'source'      => substr((string) ($j['source'] ?? ''), 0, 64),
        'source_ip'   => substr((string) ($j['source_ip'] ?? ''), 0, 45),
        'program'     => substr((string) ($j['program'] ?? ''), 0, 128),
        'facility'    => (int) ($j['facility'] ?? 1),
        'severity'    => (int) ($j['severity'] ?? 6),
        'message'     => $msg,
        // Sophos defaults — overwritten if the parser below extracts them
        'sophos_log_type'      => null,
        'sophos_log_subtype'   => null,
        'sophos_log_component' => null,
        'sophos_src_ip'        => null,
        'sophos_dst_ip'        => null,
        'sophos_src_port'      => null,
        'sophos_dst_port'      => null,
        'sophos_protocol'      => null,
        'sophos_fw_rule_name'  => null,
        'sophos_user_name'     => null,
        'sophos_application'   => null,
    ];

    // Sophos KV detection: log_id="..." is a strong tell.
    if (str_contains($msg, 'log_id="')) {
        $kv = parse_kv($msg);
        $row['sophos_log_type']      = substr($kv['log_type']      ?? '', 0, 32) ?: null;
        $row['sophos_log_subtype']   = substr($kv['log_subtype']   ?? '', 0, 32) ?: null;
        $row['sophos_log_component'] = substr($kv['log_component'] ?? '', 0, 64) ?: null;
        $row['sophos_src_ip']        = substr($kv['src_ip']        ?? '', 0, 45) ?: null;
        $row['sophos_dst_ip']        = substr($kv['dst_ip']        ?? '', 0, 45) ?: null;
        $row['sophos_src_port']      = isset($kv['src_port']) && ctype_digit((string) $kv['src_port']) ? (int) $kv['src_port'] : null;
        $row['sophos_dst_port']      = isset($kv['dst_port']) && ctype_digit((string) $kv['dst_port']) ? (int) $kv['dst_port'] : null;
        $row['sophos_protocol']      = substr($kv['protocol']      ?? '', 0, 8)  ?: null;
        $row['sophos_fw_rule_name']  = substr($kv['fw_rule_name']  ?? '', 0, 64) ?: null;
        $row['sophos_user_name']     = substr($kv['user_name']     ?? '', 0, 64) ?: null;
        $row['sophos_application']   = substr($kv['application']   ?? '', 0, 64) ?: null;
    }

    return $row;
}

/**
 * Extract Sophos-style key="value" / key=value pairs from a string.
 * Handles quoted values (which may contain spaces) and bare values.
 */
function parse_kv(string $s): array {
    $out = [];
    if (preg_match_all('/(\w+)="([^"]*)"|(\w+)=(\S+)/', $s, $m, PREG_SET_ORDER) > 0) {
        foreach ($m as $pair) {
            $k = $pair[1] !== '' ? $pair[1] : $pair[3];
            $v = $pair[2] !== '' ? $pair[2] : ($pair[4] ?? '');
            if ($k !== '') $out[$k] = $v;
        }
    }
    return $out;
}

function normalize_ts(?string $ts): ?string {
    if (!$ts) return gmdate('Y-m-d H:i:s.v');
    try {
        $dt = new DateTimeImmutable($ts);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    } catch (Exception) {
        return gmdate('Y-m-d H:i:s.v');
    }
}

/**
 * Insert one batch of rows. Uses a single multi-row INSERT for speed
 * (avoids per-row round-trips). Falls back to row-by-row on failure
 * so one bad row doesn't drop the entire batch.
 */
function flush_batch(PDO $pdo, PDOStatement $stmt, array $batch): void {
    if (!$batch) return;

    $pdo->beginTransaction();
    try {
        foreach ($batch as $row) {
            $stmt->execute($row);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // Retry one-by-one to find the offender, log it, drop only that row
        foreach ($batch as $row) {
            try {
                $stmt->execute($row);
            } catch (Throwable $rowErr) {
                fwrite(STDERR, "drop bad row: " . $rowErr->getMessage() .
                               " :: " . substr(json_encode($row), 0, 300) . "\n");
            }
        }
    }
}
