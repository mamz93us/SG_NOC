<?php

namespace App\Models\Archive;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address or a folder a scanner sends to.
 *
 * Configured once on the copier, then used for years by people who will never
 * open this portal. That shapes two decisions:
 *
 * **The token is hashed.** It appears in an address printed on a copier's screen,
 * so it is a credential in a place anybody can read — stored as a hash, shown
 * once when created, and never recoverable. Losing it costs a new address, which
 * is the right trade against it being readable in the audit log.
 *
 * **It only ever grants WRITE.** Someone who learns a token can put a document
 * into that inbox. They cannot read one, list one, or file anything — filing is a
 * signed-in action gated by `can_add`. That is what makes an unauthenticated
 * address acceptable at all, and it is why nothing here should ever be extended
 * to return content.
 */
class ArchiveScanEndpoint extends Model implements \App\Services\Backup\ProvisionsSftpgoUser
{
    public const TYPE_EMAIL = 'email';

    public const TYPE_FOLDER = 'folder';

    public const TYPES = [
        self::TYPE_EMAIL => 'Scan to e-mail',
        self::TYPE_FOLDER => 'Scan to folder (SFTP)',
    ];

    /** Long enough that guessing one is not worth trying. */
    private const TOKEN_BYTES = 12;

    protected $fillable = [
        'type',
        'user_id',
        'archive_id',
        'label',
        'token_hash',
        'token_hint',
        'sftpgo_username',
        'home_dir',
        'enabled',
        'created_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'received_count' => 'integer',
        'last_received_at' => 'datetime',
    ];

    /** See ArchiveSource::$attributes for the null-vs-default bug this prevents. */
    protected $attributes = [
        'type' => self::TYPE_EMAIL,
        'enabled' => true,
        'received_count' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Tokens ──────────────────────────────────────────────────

    /**
     * A new token: the clear value for showing once, and its hash for storing.
     *
     * Lower-case and unambiguous, because somebody types this into a copier's
     * touchscreen keyboard. Base32-ish rather than hex so it stays short.
     *
     * @return array{0:string, 1:string} clear token, hash
     */
    public static function newToken(): array
    {
        $clear = strtr(
            base64_encode(random_bytes(self::TOKEN_BYTES)),
            ['+' => '', '/' => '', '=' => '', 'l' => 'm', 'I' => 'x', 'O' => 'y', '0' => '4', '1' => '5']
        );
        $clear = strtolower(substr($clear, 0, 16));

        return [$clear, self::hash($clear)];
    }

    /**
     * Hashed with SHA-256 rather than bcrypt, deliberately.
     *
     * This is looked up, not verified against a guess: an arriving scan carries a
     * token and the ingest has to find its row, which a per-row salted hash makes
     * a table scan. The token is 96 bits of randomness, so there is nothing for a
     * slow hash to protect — it exists to stop a dictionary attack on a weak
     * secret, and this secret cannot be weak.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', trim(strtolower($token)));
    }

    /** The endpoint this token belongs to, if it is live. */
    public static function findByToken(string $token, ?string $type = null): ?self
    {
        return static::query()
            ->where('token_hash', self::hash($token))
            ->where('enabled', true)
            ->when($type, fn (Builder $query) => $query->where('type', $type))
            ->first();
    }

    // ─── Addresses and folders ───────────────────────────────────

    /**
     * The address a copier sends to.
     *
     * `u-` for a person, `a-` for an archive, so the ingest knows where something
     * goes from the recipient alone — the sender is rewritten by the relay and
     * says nothing.
     */
    public function address(string $token): string
    {
        $domain = (string) config('archive_portal.scan_mail_domain');

        return $this->prefix().'-'.$token.'@'.$domain;
    }

    /** The masked form for the list, once the token is no longer knowable. */
    public function maskedAddress(): string
    {
        $domain = (string) config('archive_portal.scan_mail_domain');
        $hint = $this->token_hint ?: '…';

        return $this->prefix().'-'.$hint.'…@'.$domain;
    }

    private function prefix(): string
    {
        return $this->archive_id ? 'a' : 'u';
    }

    public function destinationLabel(): string
    {
        if ($this->archive_id) {
            return $this->archive?->displayName() ?? 'an archive';
        }

        return $this->user?->name ?? 'a person';
    }

    // ─── State ───────────────────────────────────────────────────

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('enabled'), true);
    }

    public function isEmail(): bool
    {
        return $this->type === self::TYPE_EMAIL;
    }

    public function isFolder(): bool
    {
        return $this->type === self::TYPE_FOLDER;
    }

    /**
     * Stamp an arrival.
     *
     * forceFill rather than an audited update: this fires on every scan, and a
     * copier sending fifty a day would bury the real edits in the audit log.
     */
    public function recordArrival(): void
    {
        $this->forceFill([
            'last_received_at' => now(),
            'received_count' => (int) $this->received_count + 1,
            'last_error' => null,
        ])->save();
    }

    public function recordError(string $error): void
    {
        $this->forceFill(['last_error' => mb_substr($error, 0, 1000)])->save();
    }

    // ─── ProvisionsSftpgoUser ─────────────────────────────────────
    //
    // A `folder` endpoint is an SFTPGo login, provisioned by the same client that
    // provisions the device backup accounts (Services\Backup\SftpgoApiService).
    // The interface is what lets one client serve both without importing either.

    public function sftpgoUsername(): string
    {
        return (string) $this->sftpgo_username;
    }

    public function homeDir(): string
    {
        if ($this->home_dir) {
            return $this->home_dir;
        }

        $root = rtrim((string) config('archive_portal.scan_folder_root'), '/');

        return $root.'/'.$this->sftpgo_username;
    }

    /**
     * Unlimited, deliberately.
     *
     * A quota on a scanner destination fails in the worst possible way: the
     * copier reports a successful scan, SFTPGo refuses the write, and the paper
     * has already gone back in the tray. The sweep deletes each file as soon as
     * it reaches Azure, so the folder does not grow in normal operation — and if
     * the sweep stops, a full disk is the alarm, not a silently dropped invoice.
     */
    public function quotaBytes(): int
    {
        return 0;
    }

    /**
     * SFTP and FTPS both, because the copiers differ.
     *
     * The Ricoh units that can do neither use scan-to-email instead; these are
     * for the machines that can, and which of the two a given model does is not
     * something to make somebody configure twice.
     */
    public function allowedProtocols(): array
    {
        return ['SFTP', 'FTP'];
    }

    public function isEnabledForSftpgo(): bool
    {
        return (bool) $this->enabled;
    }
}
