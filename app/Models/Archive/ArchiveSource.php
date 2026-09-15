<?php

namespace App\Models\Archive;

use App\Services\Archive\ArcMate\ArcMatePaths;
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
    ];

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
