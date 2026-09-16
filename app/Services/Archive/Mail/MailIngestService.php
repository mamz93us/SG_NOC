<?php

namespace App\Services\Archive\Mail;

use App\Models\Archive\ArchiveInboxItem;
use App\Models\Archive\ArchiveScanEndpoint;
use App\Models\User;
use App\Services\Archive\InboxService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Scans that arrive by e-mail, turned into inbox items.
 *
 * The copiers this exists for — the Ricoh MP C3001/C3003 units — cannot do SFTP
 * or FTPS at all, so mail is the only way they can send anything. They are
 * configured once with an address and then used for years by people who will
 * never open this portal.
 *
 * **Routing is by the recipient, and only ever by the recipient.** The NOC's
 * Postfix rewrites every sender to one SES-verified identity
 * (deployment/smtp-relay/sender_canonical.regexp is literally `/.+/ →
 * scanner@samirgroup.com`), so the From address of an arriving scan identifies
 * nothing whatsoever. The token in `u-<token>@` or `a-<token>@` is the whole
 * identity of a destination — which is also why Postfix accepts this domain only
 * from `mynetworks`, and why the domain has no public MX.
 *
 * A spool file is never left where it was. Ingested or refused, it moves out of
 * `new/` — a file that stays is a file re-read every minute for ever.
 */
class MailIngestService
{
    /** Where a message goes once it has been dealt with, for a few days. */
    private const DONE = 'done';

    /** Where a message goes when nothing could be made of it. */
    private const FAILED = 'failed';

    public function __construct(private ?InboxService $inbox = null)
    {
        $this->inbox ??= new InboxService;
    }

    /**
     * Read everything waiting in the spool.
     *
     * @param  callable|null  $shouldStop  true when the time budget is spent
     * @return array{messages:int, scans:int, ignored:int, failed:int, reason:?string}
     */
    public function run(int $maxMessages = 50, ?callable $shouldStop = null): array
    {
        $shouldStop ??= static fn (): bool => false;
        $stats = ['messages' => 0, 'scans' => 0, 'ignored' => 0, 'failed' => 0, 'reason' => null];

        $spool = $this->spool();

        if ($spool === null) {
            // Not set up on this host (every dev box, and the NOC before
            // scan-mail.sh has run). Nothing to do, and not an error.
            $stats['reason'] = 'No mail spool on this host.';

            return $stats;
        }

        foreach ($this->waiting($spool, $maxMessages) as $path) {
            if ($shouldStop()) {
                $stats['reason'] = 'Time budget spent; the rest is read next run.';
                break;
            }

            $stats['messages']++;

            $result = $this->message($path);

            $stats['scans'] += $result['scans'];
            $stats['ignored'] += $result['ignored'];
            $stats['failed'] += $result['failed'];
        }

        return $stats;
    }

    /**
     * One message.
     *
     * @return array{scans:int, ignored:int, failed:int}
     */
    private function message(string $path): array
    {
        $stats = ['scans' => 0, 'ignored' => 0, 'failed' => 0];

        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            $this->move($path, self::FAILED);
            $stats['failed']++;

            return $stats;
        }

        // A mail far bigger than any real scan is a misconfigured copier (600 dpi
        // colour of a 40-page contract), and reading it into memory to find that
        // out is the wrong order of operations.
        $limit = max(1, (int) config('archive_portal.max_scan_mb')) * 1024 * 1024;

        if (strlen($raw) > $limit) {
            Log::warning('[archive] scan mail too large, refused: '.basename($path).' ('.strlen($raw).' bytes)');
            $this->move($path, self::FAILED);
            $stats['failed']++;

            return $stats;
        }

        $message = MailMessage::parse($raw);
        $endpoints = $this->endpointsFor($message);

        if ($endpoints === []) {
            // Addressed to nothing this portal knows. Kept rather than deleted:
            // a copier sending to a deleted or mistyped address is a support
            // question, and the message is the evidence.
            Log::info('[archive] scan mail for no known destination: '.implode(', ', $message->recipients()));
            $this->move($path, self::FAILED);
            $stats['ignored']++;

            return $stats;
        }

        $scans = $message->scans(InboxService::ACCEPTED);

        if ($scans === []) {
            foreach ($endpoints as $endpoint) {
                $endpoint->recordError('A message arrived with nothing scannable attached.');
            }

            $this->move($path, self::FAILED);
            $stats['ignored']++;

            return $stats;
        }

        foreach ($endpoints as $endpoint) {
            foreach ($scans as $scan) {
                if ($this->receive($endpoint, $scan, $message)) {
                    $stats['scans']++;
                } else {
                    $stats['failed']++;
                }
            }

            $endpoint->recordArrival();
        }

        $this->move($path, self::DONE);

        return $stats;
    }

    /**
     * Store one attachment as an inbox item.
     *
     * @param  array{name:string, mime:string, bytes:string}  $scan
     */
    private function receive(ArchiveScanEndpoint $endpoint, array $scan, MailMessage $message): bool
    {
        $extension = strtolower(pathinfo($scan['name'], PATHINFO_EXTENSION)) ?: 'pdf';
        $sha256 = hash('sha256', $scan['bytes']);

        // The same scan arriving twice is ordinary — a copier retrying, a message
        // delivered to two addresses on one endpoint — and is recognised rather
        // than refused, exactly as an upload is.
        $existing = ArchiveInboxItem::query()
            ->where('sha256', $sha256)
            ->where('status', ArchiveInboxItem::STATUS_WAITING)
            ->where(function ($query) use ($endpoint) {
                $query->where('user_id', $endpoint->user_id)
                    ->orWhere('archive_id', $endpoint->archive_id);
            })
            ->exists();

        if ($existing) {
            return true;
        }

        $path = 'inbox/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$extension;

        try {
            // Straight to Azure, like every other way in — nothing touches a local
            // disk, so the scheduler-vs-www-data permission trap cannot apply.
            Storage::disk(\App\Models\Archive\ArchiveFile::DISK_AZURE)->put($path, $scan['bytes']);
        } catch (\Throwable $e) {
            Log::error('[archive] could not store scan mail attachment: '.$e->getMessage());
            $endpoint->recordError('A scan could not be stored: '.$e->getMessage());

            return false;
        }

        ArchiveInboxItem::create([
            // Exactly one of these is set on an endpoint, so an item lands either
            // in a person's inbox or in an archive's shared one.
            'user_id' => $endpoint->user_id,
            'archive_id' => $endpoint->archive_id,
            'source' => ArchiveInboxItem::SOURCE_EMAIL,
            // What the copier called itself, for tracing a misfiled scan back to
            // a machine. Never used to decide anything.
            'source_detail' => mb_substr($endpoint->label.' · '.$message->subject(), 0, 255),
            'path' => $path,
            'original_name' => $scan['name'],
            'mime' => $scan['mime'],
            'size' => strlen($scan['bytes']),
            'sha256' => $sha256,
            'ai_status' => ArchiveInboxItem::AI_QUEUED,
            'received_at' => now(),
            'meta' => [
                'from' => $message->from(),
                'subject' => $message->subject(),
                'endpoint' => $endpoint->getKey(),
            ],
        ]);

        return true;
    }

    /**
     * The destinations a message was addressed to.
     *
     * @return array<int,ArchiveScanEndpoint>
     */
    private function endpointsFor(MailMessage $message): array
    {
        $domain = strtolower((string) config('archive_portal.scan_mail_domain'));
        $found = [];

        foreach ($message->recipients() as $address) {
            [$local, $host] = array_pad(explode('@', $address, 2), 2, '');

            // Only our own scan domain. A copier that also mails a person should
            // not have that person's address treated as a token.
            if ($host !== $domain) {
                continue;
            }

            // u-<token> or a-<token>. The prefix says what KIND of destination it
            // is; the token alone identifies which, so a mismatched prefix simply
            // finds nothing rather than reaching the wrong inbox.
            if (! preg_match('/^([ua])-([a-z0-9]{6,32})$/', $local, $m)) {
                continue;
            }

            $endpoint = ArchiveScanEndpoint::findByToken($m[2], ArchiveScanEndpoint::TYPE_EMAIL);

            if (! $endpoint) {
                continue;
            }

            $expected = $endpoint->archive_id ? 'a' : 'u';

            if ($m[1] !== $expected) {
                continue;
            }

            // A destination whose owner has been deleted would file documents as
            // nobody; skipped rather than guessed at.
            if (! $endpoint->archive_id && ! User::whereKey($endpoint->user_id)->exists()) {
                continue;
            }

            $found[$endpoint->getKey()] = $endpoint;
        }

        return array_values($found);
    }

    // ─── The spool ───────────────────────────────────────────────

    /** The spool directory, or null when this host has none. */
    private function spool(): ?string
    {
        $path = rtrim((string) config('archive_portal.mail_spool'), '/');

        return $path !== '' && @is_dir($path) ? $path : null;
    }

    /**
     * Messages waiting, oldest first.
     *
     * @return array<int,string>
     */
    private function waiting(string $spool, int $max): array
    {
        $files = array_values(array_filter(
            glob($spool.'/*') ?: [],
            fn (string $path) => @is_file($path) && ! str_starts_with(basename($path), '.'),
        ));

        usort($files, fn (string $a, string $b) => (@filemtime($a) ?: 0) <=> (@filemtime($b) ?: 0));

        return array_slice($files, 0, max(1, $max));
    }

    /**
     * Move a message out of `new/`.
     *
     * Always, whatever happened to it: a file left in place is read again on the
     * next run, for ever. Kept rather than deleted so a copier that stopped
     * working leaves evidence — `done/` and `failed/` are pruned on age by the
     * deployment script's own cron, not by this app.
     */
    private function move(string $path, string $into): void
    {
        $target = dirname(dirname($path)).'/'.$into;

        if (! @is_dir($target) && ! @mkdir($target, 0750, true) && ! @is_dir($target)) {
            // Nowhere to put it: delete rather than re-read it every minute.
            @unlink($path);

            return;
        }

        if (! @rename($path, $target.'/'.basename($path))) {
            @unlink($path);
        }
    }
}
