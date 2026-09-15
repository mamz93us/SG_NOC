<?php

namespace App\Models\Archive;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * Who opened which document, and when.
 *
 * Its own table rather than a line in activity_logs, for the same reason
 * CredentialAccessLog is: it is written on every single view and download and
 * read back as a report. It is excluded from the automatic auditing (see
 * config/audit.php) — auditing an access log would double every row.
 */
class ArchiveAccessLog extends Model
{
    public const ACTION_VIEW = 'view';

    public const ACTION_DOWNLOAD = 'download';

    public const ACTION_ASK = 'ask';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'archive_id',
        'archive_document_id',
        'archive_file_id',
        'action',
        'ip',
        'user_agent',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ArchiveDocument::class, 'archive_document_id');
    }

    /**
     * Record one access. Never allowed to break the thing it is recording: a
     * failure here must not stop someone opening an invoice, so it is logged
     * and swallowed.
     */
    public static function record(
        ?int $userId,
        ArchiveDocument $document,
        string $action,
        ?ArchiveFile $file = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        try {
            static::create([
                'user_id' => $userId,
                'archive_id' => $document->archive_id,
                'archive_document_id' => $document->getKey(),
                'archive_file_id' => $file?->getKey(),
                'action' => $action,
                'ip' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[archive] access log failed: '.$e->getMessage());
        }
    }
}
