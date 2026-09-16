<?php

namespace App\Models\Archive;

use App\Services\Archive\ArcMate\ArcMatePaths;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * The ArcMate server the NOC reads from: one read-only SQL login and one
 * read-only file share.
 *
 * Deliberately the same shape as App\Models\Attendance\BiotimeSource, which
 * solves the same problem for ZKTeco: several SQL Server databases whose
 * credentials belong in a table rather than in .env or config/database.php. The
 * connection is built at runtime by Services\Archive\ArcMate\ArcMateConnection.
 *
 * The password here is NEVER ArcMate's own `sa` account. ArcMate keeps that in
 * every project.config on a share half the company can read; this is a separate
 * login with db_datareader and nothing else.
 */
class ArchiveSource extends Model
{
    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'password',
        'trust_server_certificate',
        'mount_path',
        'arcmate_path_prefix',
        'enabled',
        'transfer_enabled',
        'transfer_window_start',
        'transfer_window_end',
        'transfer_weekend_all_day',
        'transfer_anytime',
        'transfer_speed_mbps',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'port' => 'integer',
        'trust_server_certificate' => 'boolean',
        'enabled' => 'boolean',
        'last_test_at' => 'datetime',
        'last_test_ok' => 'boolean',
        'last_sync_at' => 'datetime',
        'last_sync_rows' => 'integer',
        'consecutive_failures' => 'integer',
        'transfer_enabled' => 'boolean',
        'transfer_weekend_all_day' => 'boolean',
        'transfer_anytime' => 'boolean',
        'transfer_speed_mbps' => 'integer',
    ];

    /**
     * Defaults for a NEW row, in PHP and not only in the database.
     *
     * A database default is applied by the INSERT. The model you are holding
     * after create() still has null for anything you did not pass, and that is
     * a genuinely nasty way to fail: ArchiveSource::create() without
     * `transfer_enabled` handed back a model whose transfer_enabled was null,
     * `! null` is true, and the transfer worker decided it was switched off —
     * while the very same row, reloaded, said it was on. Intermittent, and
     * invisible until something reloads.
     */
    protected $attributes = [
        'enabled' => true,
        'trust_server_certificate' => true,
        'transfer_enabled' => true,
        'transfer_window_start' => '19:00',
        'transfer_window_end' => '07:00',
        'transfer_weekend_all_day' => true,
        'transfer_anytime' => false,
    ];

    /**
     * Whether the transfer worker may copy files right now.
     *
     * The share being copied FROM is the server people are still scanning into
     * all day, so the default is nights and the Saudi weekend. Saturating it at
     * 11am would be felt by exactly the people the portal exists to help.
     *
     * The window wraps midnight (19:00 → 07:00), which is the normal case and
     * the one a naive `start <= now <= end` comparison gets wrong every time.
     */
    public function transferAllowedNow(?CarbonInterface $now = null): bool
    {
        // `?? true` matches the column default. A model that was never reloaded,
        // or was selected with only some columns, carries null rather than the
        // default — see the note on $attributes above.
        if (! ($this->transfer_enabled ?? true)) {
            return false;
        }

        if ($this->transfer_anytime) {
            return true;
        }

        $now ??= CarbonImmutable::now();

        // Friday and Saturday are the weekend here, not Saturday and Sunday.
        if ($this->transfer_weekend_all_day && in_array($now->dayOfWeek, [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY], true)) {
            return true;
        }

        $start = trim((string) $this->transfer_window_start) ?: '19:00';
        $end = trim((string) $this->transfer_window_end) ?: '07:00';
        $current = $now->format('H:i');

        return $start <= $end
            ? ($current >= $start && $current < $end)
            : ($current >= $start || $current < $end);
    }

    /** Bytes per second the worker should not exceed, or null for no cap. */
    public function transferBytesPerSecond(): ?int
    {
        $mbps = (int) $this->transfer_speed_mbps;

        return $mbps > 0 ? $mbps * 1024 * 1024 : null;
    }

    public function archives(): HasMany
    {
        return $this->hasMany(Archive::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Connection name for one of this server's databases.
     *
     * Per database, not per server: ArcMate gives every project its own
     * database, and a connection is bound to one.
     */
    public function connectionName(string $database): string
    {
        return 'arcmate_'.$this->id.'_'.preg_replace('/[^A-Za-z0-9_]/', '', $database);
    }

    /** Where the read-only share is mounted on this host. */
    public function mountPath(): string
    {
        $path = trim((string) $this->mount_path);

        return rtrim($path !== '' ? $path : (string) config('archive_portal.mount_path', '/mnt/arcmate'), '/');
    }

    /**
     * Absolute path of one ArcMate file under the mount, or null when it is not
     * on the share.
     *
     * The work is in ArcMatePaths, which knows the two things the database does
     * NOT record: that tblMedia is empty (so there is no stored root — the
     * folder layout is the only record), and that whether a file sits under
     * `Images` or `EDocs` follows from its type rather than from any column.
     */
    public function resolveFile(Archive $archive, ?string $arcFileName): ?string
    {
        return ArcMatePaths::resolve($this->mountPath(), $archive->arcmate_folder, $arcFileName);
    }

    /** Where a file would be if it were there — recorded when nothing matched. */
    public function guessFilePath(Archive $archive, ?string $arcFileName): ?string
    {
        return ArcMatePaths::bestGuess($this->mountPath(), $archive->arcmate_folder, $arcFileName);
    }

    /** The folder of one mirrored project on the share. */
    public function projectPath(Archive $archive): ?string
    {
        $folder = trim((string) $archive->arcmate_folder, '/\\');

        return $folder === '' ? null : $this->mountPath().'/'.$folder;
    }

    // ─── SQL login password — encrypted at rest ───────────────────

    public function setPasswordAttribute(?string $value): void
    {
        $this->attributes['password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getPasswordAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->host) !== ''
            && trim((string) $this->username) !== ''
            && (string) $this->password !== '';
    }
}
