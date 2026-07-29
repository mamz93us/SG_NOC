<?php

namespace App\Console\Commands;

use App\Models\SmtpRelayMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Tail the Postfix maillog and turn it into one row per relayed message in
 * `smtp_relay_messages` (+ attachments), for the /admin/smtp-relay audit page.
 *
 * A single message spans many log lines that share a Postfix queue id (client
 * connect, qmgr from/size, per-recipient smtp status, header_checks Subject +
 * attachment WARNings). We parse the new tail of the file each tick, group the
 * lines by queue id, and upsert — merging with any row already started on an
 * earlier tick, since a message's lines can straddle two runs.
 *
 * Resume is by persisted {inode, offset}, so we only read new bytes and cope
 * with log rotation/truncation. If the log path does not exist (dev/Windows),
 * the command no-ops cleanly.
 */
class IngestSmtpRelayLog extends Command
{
    protected $signature = 'smtp-relay:ingest-log
        {--file= : Override the maillog path (defaults to config smtp_relay.log_path)}
        {--prune : Instead of ingesting, delete messages older than the retention window}';

    protected $description = 'Parse the Postfix maillog into the SMTP relay audit table';

    /** Postfix queue id: default Ubuntu hex form, e.g. C8B7B42137. */
    private const QID = '[0-9A-F]{6,}';

    public function handle(): int
    {
        if ($this->option('prune')) {
            return $this->prune();
        }

        $path = $this->option('file') ?: (string) config('smtp_relay.log_path');
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            // Absent on dev boxes / not yet permissioned — nothing to do.
            $this->line("maillog not readable ($path) — skipping.");

            return self::SUCCESS;
        }

        $statePath = (string) config('smtp_relay.state_path');
        File::ensureDirectoryExists(dirname($statePath));

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            $this->error("Could not open $path");

            return self::FAILURE;
        }

        $inode = @fstat($fh)['ino'] ?? 0;
        $size = filesize($path);
        $state = $this->loadState($statePath);

        $offset = 0;
        if (($state['inode'] ?? null) === $inode && ($state['offset'] ?? 0) <= $size) {
            $offset = (int) $state['offset'];
        }
        fseek($fh, $offset);

        $cap = (int) config('smtp_relay.max_bytes_per_run');
        $events = [];   // "qid|Y-m-d" => accumulated fields
        $read = 0;
        $lineStart = $offset;

        while (! feof($fh)) {
            $lineStart = ftell($fh);
            $line = fgets($fh);
            if ($line === false) {
                break;
            }
            // Partial final line (no newline yet): rewind and stop so next tick
            // re-reads it whole.
            if (! str_ends_with($line, "\n")) {
                fseek($fh, $lineStart);
                break;
            }
            $read += strlen($line);
            $this->parseLine(rtrim($line, "\r\n"), $events);
            if ($read >= $cap) {
                break;   // stay bounded; resume from here next tick
            }
        }

        $newOffset = ftell($fh);
        fclose($fh);

        $touched = $this->flush($events);

        $this->saveState($statePath, ['inode' => $inode, 'offset' => $newOffset, 'saved_at' => now()->toIso8601String()]);
        $this->info("Ingested ".number_format($read)." bytes; {$touched} message(s) updated.");

        return self::SUCCESS;
    }

    // ── Parsing ─────────────────────────────────────────────────────────────

    private function parseLine(string $line, array &$events): void
    {
        // <ts...> <host> postfix/<prog>[pid]: <rest>
        if (! preg_match('#^(.*?)\s+\S+\s+postfix/(\w+)(?:\[\d+\])?:\s+(.*)$#', $line, $m)) {
            return;
        }
        [$ts, $prog, $rest] = [$m[1], $m[2], $m[3]];
        $when = $this->parseTs($ts);
        $date = $when->format('Y-m-d');

        switch ($prog) {
            case 'smtpd':
                if (preg_match('/^('.self::QID.'): client=([^\[\s]+)\[([^\]]+)\]/', $rest, $c)) {
                    $e = &$this->ev($events, $c[1], $date, $when);
                    $e['client_host'] = $c[2];
                    $e['client_ip'] = $c[3];
                    $e['queued_at'] ??= $when;

                    return;
                }
                // Blocked at RCPT — never queued. Record as its own "rejected" row.
                if (preg_match('/^NOQUEUE: reject: [A-Z]+ from ([^\[]+)\[([^\]]+)\]: (.*?); from=<([^>]*)> to=<([^>]*)>/', $rest, $r)) {
                    $key = 'reject-'.substr(sha1($line), 0, 16);
                    $e = &$this->ev($events, $key, $date, $when, $key);
                    $e['client_host'] = trim($r[1]);
                    $e['client_ip'] = $r[2];
                    $e['error'] = $r[3];
                    $e['mail_from'] = $r[4];
                    $this->addRecipient($e, $r[5]);
                    $e['queued_at'] ??= $when;
                    $this->raiseStatus($e, SmtpRelayMessage::STATUS_REJECTED);
                }

                return;

            case 'qmgr':
                if (preg_match('/^('.self::QID.'): from=<([^>]*)>, size=(\d+), nrcpt=(\d+)/', $rest, $q)) {
                    $e = &$this->ev($events, $q[1], $date, $when);
                    $e['mail_from'] = $q[2];
                    $e['size_bytes'] = (int) $q[3];
                    $e['nrcpt'] = (int) $q[4];
                    $e['queued_at'] ??= $when;
                }

                return;

            case 'cleanup':
                if (preg_match('/^('.self::QID.'): warning: header Subject:\s*(.*) from \S+\[[^\]]+\]/', $rest, $s)) {
                    $e = &$this->ev($events, $s[1], $date, $when);
                    $e['subject'] = trim($s[2]);

                    return;
                }
                if (preg_match('/^('.self::QID.'): warning: header (?:Content-Type|Content-Disposition):.*?(?:file)?name=\s*"?([^";]+)"?.* from \S+\[[^\]]+\]/i', $rest, $a)) {
                    $e = &$this->ev($events, $a[1], $date, $when);
                    $fn = trim($a[2]);
                    if ($fn !== '' && ! in_array($fn, $e['attachments'], true)) {
                        $e['attachments'][] = $fn;
                    }
                }

                return;

            case 'smtp':
            case 'error':
            case 'local':
                if (preg_match('/^('.self::QID.'): to=<([^>]*)>,.*?status=(\w+) \((.*)\)\s*$/', $rest, $t)) {
                    $e = &$this->ev($events, $t[1], $date, $when);
                    $this->addRecipient($e, $t[2]);
                    $status = $t[3];   // sent | bounced | deferred
                    $resp = $t[4];
                    $e['last_event_at'] = $when;
                    if ($status === SmtpRelayMessage::STATUS_SENT) {
                        $e['ses_response'] = $resp;
                        if (preg_match('/250(?:\s+[\d.]+)?\s+Ok\s+(\S+)/i', $resp, $id)) {
                            $e['ses_message_id'] ??= rtrim($id[1], ')');
                        }
                    } else {
                        $e['error'] = $resp;
                    }
                    $this->raiseStatus($e, $status);
                }

                return;
        }
    }

    /** Get/init the accumulator for a queue id + date by reference. */
    private function &ev(array &$events, string $qid, string $date, CarbonImmutable $when, ?string $forceKey = null): array
    {
        $key = ($forceKey ?? $qid).'|'.$date;
        if (! isset($events[$key])) {
            $events[$key] = [
                'queue_id' => substr($forceKey ?? $qid, 0, 24),
                'log_date' => $date,
                'queued_at' => null,
                'client_host' => null,
                'client_ip' => null,
                'mail_from' => null,
                'recipients' => [],
                'nrcpt' => 0,
                'subject' => null,
                'size_bytes' => null,
                'attachments' => [],
                'status' => SmtpRelayMessage::STATUS_QUEUED,
                'ses_message_id' => null,
                'ses_response' => null,
                'error' => null,
                'last_event_at' => $when,
            ];
        }
        $events[$key]['last_event_at'] = $when;

        return $events[$key];
    }

    private function addRecipient(array &$e, string $addr): void
    {
        $addr = trim($addr);
        if ($addr !== '' && ! in_array($addr, $e['recipients'], true)) {
            $e['recipients'][] = $addr;
        }
    }

    private function raiseStatus(array &$e, string $status): void
    {
        if ($this->severity($status) >= $this->severity($e['status'])) {
            $e['status'] = $status;
        }
    }

    private function severity(string $status): int
    {
        return match ($status) {
            SmtpRelayMessage::STATUS_REJECTED, SmtpRelayMessage::STATUS_BOUNCED => 4,
            SmtpRelayMessage::STATUS_DEFERRED => 3,
            SmtpRelayMessage::STATUS_SENT => 2,
            default => 1, // queued
        };
    }

    private function parseTs(string $ts): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(trim($ts));
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }

    // ── Persistence ─────────────────────────────────────────────────────────

    /** Upsert each accumulated message, merging with any existing row. */
    private function flush(array $events): int
    {
        $count = 0;
        foreach ($events as $e) {
            $msg = SmtpRelayMessage::firstOrNew([
                'queue_id' => $e['queue_id'],
                'log_date' => $e['log_date'],
            ]);

            // Merge recipients (union of existing + new).
            $existingRcpts = $msg->recipients ? array_filter(array_map('trim', explode(',', $msg->recipients))) : [];
            $rcpts = array_values(array_unique(array_merge($existingRcpts, $e['recipients'])));

            $msg->queued_at = $msg->queued_at ?: $e['queued_at'];
            $msg->client_host = $e['client_host'] ?: $msg->client_host;
            $msg->client_ip = $e['client_ip'] ?: $msg->client_ip;
            $msg->mail_from = $e['mail_from'] ?: $msg->mail_from;
            $msg->recipients = $rcpts ? implode(', ', $rcpts) : $msg->recipients;
            $msg->nrcpt = max((int) $msg->nrcpt, (int) $e['nrcpt'], count($rcpts));
            $msg->subject = $e['subject'] !== null ? $e['subject'] : $msg->subject;
            $msg->size_bytes = $e['size_bytes'] ?? $msg->size_bytes;
            $msg->ses_message_id = $msg->ses_message_id ?: $e['ses_message_id'];
            if ($e['ses_response'] !== null) {
                $msg->ses_response = $e['ses_response'];
            }
            if ($e['error'] !== null) {
                $msg->error = $e['error'];
            }
            // Status: keep the more severe of stored vs new.
            if ($this->severity($e['status']) >= $this->severity($msg->status ?: SmtpRelayMessage::STATUS_QUEUED)) {
                $msg->status = $e['status'];
            }
            $msg->last_event_at = $e['last_event_at'];
            $msg->save();

            // Attachments (filenames) — create any not already recorded.
            if ($e['attachments']) {
                $known = $msg->attachments()->pluck('filename')->all();
                foreach ($e['attachments'] as $fn) {
                    if (! in_array($fn, $known, true)) {
                        $msg->attachments()->create(['filename' => $fn]);
                    }
                }
                $msg->attachments_count = $msg->attachments()->count();
                $msg->saveQuietly();
            }

            $count++;
        }

        return $count;
    }

    private function prune(): int
    {
        $days = config('smtp_relay.retention_days');
        if (! is_numeric($days)) {
            $this->line('retention_days not set — keeping all history.');

            return self::SUCCESS;
        }
        $cutoff = CarbonImmutable::now()->subDays((int) $days)->toDateString();
        $deleted = SmtpRelayMessage::where('log_date', '<', $cutoff)->delete();
        $this->info("Pruned {$deleted} relay message(s) older than {$days} days.");

        return self::SUCCESS;
    }

    // ── Resume state ─────────────────────────────────────────────────────────

    private function loadState(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    private function saveState(string $path, array $state): void
    {
        @file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT));
    }
}
