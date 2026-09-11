<?php

namespace App\Models\Attendance;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * One ZKTeco SQL Server database the NOC reads punches from — either a BioTime
 * attendance database (iclock_transaction) or a ZKBio access-control one
 * (acc_transaction). Services\Attendance\Readers knows how to read each.
 *
 * The connection is built at runtime by Services\Attendance\BioTimeConnection
 * under the name `biotime_{id}`; nothing about it lives in config/database.php.
 *
 * Watermark: `last_id` for BioTime (its ids increase; an offline device uploads
 * old punches days later with NEW ids, so never read by time), and
 * (`last_time`, `last_ref`) for access control, whose ids are unordered hex.
 */
class BiotimeSource extends Model
{
    public const TYPE_ICLOCK = 'iclock_transaction';

    public const TYPE_ACCESS = 'acc_transaction';

    public const TYPES = [
        self::TYPE_ICLOCK => 'BioTime attendance (iclock_transaction)',
        self::TYPE_ACCESS => 'ZKBio access control (acc_transaction)',
    ];

    protected $fillable = [
        'name',
        'source_type',
        'time_column',
        'host',
        'port',
        'database',
        'username',
        'password',
        'trust_server_certificate',
        'stores_utc',
        'timezone',
        'enabled',
        'default_branch_id',
        'import_from',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'port' => 'integer',
        'trust_server_certificate' => 'boolean',
        'stores_utc' => 'boolean',
        'enabled' => 'boolean',
        'default_branch_id' => 'integer',
        'import_from' => 'date',
        'last_id' => 'integer',
        'last_sync_at' => 'datetime',
        'last_sync_rows' => 'integer',
        'consecutive_failures' => 'integer',
        'last_test_at' => 'datetime',
    ];

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function areas(): HasMany
    {
        return $this->hasMany(BiotimeArea::class);
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(BiotimeTerminal::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(BiotimeEmployee::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function connectionName(): string
    {
        return 'biotime_'.$this->id;
    }

    public function isAccessControl(): bool
    {
        return $this->source_type === self::TYPE_ACCESS;
    }

    /** Once punches are stored, the database and table cannot change: their ids would mix. */
    public function hasPunches(): bool
    {
        return (int) $this->last_id > 0 || $this->last_time !== null;
    }

    public function watermarkLabel(): string
    {
        if ($this->isAccessControl()) {
            return $this->last_time ? substr((string) $this->last_time, 0, 19) : '—';
        }

        return number_format((int) $this->last_id);
    }

    /** @return list<string> */
    public static function timezoneChoices(): array
    {
        return array_values(array_unique([
            config('app.timezone'),
            'Africa/Cairo',
            'Asia/Riyadh',
            'Asia/Dubai',
            'Asia/Qatar',
            'Asia/Kuwait',
            'Asia/Bahrain',
            'Asia/Muscat',
            'Asia/Amman',
        ]));
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
}
