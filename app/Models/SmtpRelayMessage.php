<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message relayed through the NOC Postfix smarthost (Ricoh scan-to-email ->
 * Amazon SES). Populated by the `smtp-relay:ingest-log` command from the Postfix
 * maillog; surfaced on /admin/smtp-relay.
 *
 * `status` reflects what happened at send time:
 *   sent     - SES accepted it (250 Ok) — see ses_message_id
 *   bounced  - SES/Postfix rejected it synchronously — see error
 *   deferred - transient failure, still retrying in the queue
 *   rejected - blocked at RCPT (e.g. relay access denied), never queued
 *   queued   - accepted from the printer, outcome not yet logged
 * Note: `sent` means AWS ACCEPTED it; a later async hard-bounce is not visible
 * here (that needs SES event notifications).
 */
class SmtpRelayMessage extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENT,
        self::STATUS_BOUNCED,
        self::STATUS_DEFERRED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'queue_id',
        'log_date',
        'queued_at',
        'client_ip',
        'client_host',
        'mail_from',
        'recipients',
        'nrcpt',
        'subject',
        'size_bytes',
        'attachments_count',
        'attachments_bytes',
        'status',
        'ses_message_id',
        'ses_response',
        'error',
        'last_event_at',
    ];

    protected $casts = [
        'log_date' => 'date',
        'queued_at' => 'datetime',
        'last_event_at' => 'datetime',
        'nrcpt' => 'integer',
        'size_bytes' => 'integer',
        'attachments_count' => 'integer',
        'attachments_bytes' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function attachments(): HasMany
    {
        return $this->hasMany(SmtpRelayAttachment::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    /** Anything that did not reach AWS as accepted. */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_BOUNCED,
            self::STATUS_DEFERRED,
            self::STATUS_REJECTED,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function humanSize(): string
    {
        return self::formatBytes($this->size_bytes);
    }

    public static function formatBytes(?int $bytes): string
    {
        if (! $bytes) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return sprintf($i === 0 ? '%d %s' : '%.1f %s', $size, $units[$i]);
    }
}
