<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One Grandstream firmware image in the NOC's library.
 *
 * The bytes live on the `firmware` disk. Every image is kept under
 * library/{id}/{filename}; publishing copies the active one to the disk ROOT,
 * because that flat root is what nginx serves and what the phone's configured
 * firmware path points at. Grandstream phones request a fixed filename
 * (grp2601fw.bin) and compare the version baked into the .bin header against
 * their own, so a flat directory serves the whole fleet and publishing is
 * idempotent — no per-phone targeting.
 */
class PhoneFirmware extends Model
{
    use HasFactory;

    // "firmware" is uncountable, so Eloquent would infer `phone_firmware`.
    // Pin it: the migration and the raw DB::table() progress writes in
    // firmware:fetch-remote both target phone_firmwares.
    protected $table = 'phone_firmwares';

    public const DISK = 'firmware';

    /** Where un-published images are parked on the disk. */
    public const LIBRARY_DIR = 'library';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FETCHING = 'fetching';

    public const STATUS_STORED = 'stored';

    public const STATUS_FAILED = 'failed';

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_URL = 'url';

    protected $fillable = [
        'model',
        'version',
        'filename',
        'covers',
        'path',
        'size',
        'sha256',
        'is_active',
        'source',
        'source_url',
        'status',
        'error',
        'download_total_bytes',
        'download_received_bytes',
        'notes',
        'uploaded_by',
        'published_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'is_active' => 'boolean',
        'download_total_bytes' => 'integer',
        'download_received_bytes' => 'integer',
        'published_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('status', self::STATUS_STORED);
    }

    public function scopeStored(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_STORED);
    }

    // ─── State helpers ────────────────────────────────────────────

    public function isStored(): bool
    {
        return $this->status === self::STATUS_STORED;
    }

    /** Path the file occupies while it is only in the library (not published). */
    public function libraryPath(): string
    {
        return self::LIBRARY_DIR.'/'.$this->id.'/'.$this->filename;
    }

    /** The URL a phone would fetch once this image is published. */
    public function publishedUrl(): string
    {
        return rtrim(Storage::disk(self::DISK)->url(''), '/').'/'.$this->filename;
    }

    /**
     * Model tokens this image covers, upper-cased. Falls back to the primary
     * model when `covers` was left blank.
     */
    public function coveredModels(): array
    {
        $raw = trim((string) ($this->covers ?: $this->model));

        return array_values(array_filter(array_map(
            fn ($t) => strtoupper(trim($t)),
            preg_split('/[,\s]+/', $raw) ?: []
        )));
    }

    /**
     * Does this image apply to the given phone model string? Tokens match
     * exactly, or as a prefix when they end in `*` (GRP2601* → GRP2601W).
     */
    public function coversModel(?string $phoneModel): bool
    {
        $phoneModel = strtoupper(trim((string) $phoneModel));
        if ($phoneModel === '') {
            return false;
        }

        foreach ($this->coveredModels() as $token) {
            if (str_ends_with($token, '*')) {
                if (str_starts_with($phoneModel, rtrim($token, '*'))) {
                    return true;
                }
            } elseif ($token === $phoneModel) {
                return true;
            }
        }

        return false;
    }

    public function humanSize(): string
    {
        return DownloadFile::formatBytes($this->size);
    }
}
