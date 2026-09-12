<?php

namespace App\Models\Attendance;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * One ZKTeco SQL Server database the NOC reads punches from — a BioTime
 * attendance database (iclock_transaction), a ZKBio access-control one
 * (acc_transaction), or a legacy ZKTime one (CHECKINOUT + USERINFO).
 * Services\Attendance\Readers knows how to read each.
 *
 * The connection is built at runtime by Services\Attendance\BioTimeConnection
 * under the name `biotime_{id}`; nothing about it lives in config/database.php.
 *
 * Watermark: `last_id` for BioTime (its ids increase; an offline device uploads
 * old punches days later with NEW ids, so never read by time), and
 * (`last_time`, `last_ref`) for the two that cannot be read by id — access
 * control, whose ids are unordered hex, and the legacy table, which has no id.
 */
class BiotimeSource extends Model
{
    public const TYPE_ICLOCK = 'iclock_transaction';

    public const TYPE_ACCESS = 'acc_transaction';

    public const TYPE_CHECKINOUT = 'checkinout';

    public const TYPES = [
        self::TYPE_ICLOCK => 'BioTime attendance (iclock_transaction)',
        self::TYPE_ACCESS => 'ZKBio access control (acc_transaction)',
        self::TYPE_CHECKINOUT => 'ZKTeco legacy (CHECKINOUT + USERINFO)',
    ];

    /**
     * USERINFO's identity columns. Which one carries the number HR knows
     * differs by installation, so it is chosen per source — and whitelisted
     * here, which is what makes it safe to name in SQL.
     */
    public const CODE_COLUMNS = [
        'BADGENUMBER' => 'BADGENUMBER — the enrolment number on the device',
        'SSN' => 'SSN — a second id field, often the HR number',
        'CardNo' => 'CardNo — the RFID card number',
    ];

    protected $fillable = [
        'name',
        'source_type',
        'time_column',
        'code_prefix',
        'code_column',
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
        'lookback_days',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'port' => 'integer',
        'trust_server_certificate' => 'boolean',
        'stores_utc' => 'boolean',
        'enabled' => 'boolean',
        'default_branch_id' => 'integer',
        'import_from' => 'date',
        'lookback_days' => 'integer',
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

    public function isLegacy(): bool
    {
        return $this->source_type === self::TYPE_CHECKINOUT;
    }

    /** Read by (time, ref) rather than by a numeric id — everything but BioTime. */
    public function usesTimeKeyset(): bool
    {
        return $this->isAccessControl() || $this->isLegacy();
    }

    /**
     * Prepended to the device code before it is looked up as an Oracle EMP_NO.
     * Cairo's badge 512 is Oracle 55512; without it the bare 512 would match a
     * Saudi employee who really holds that number.
     */
    public function codePrefix(): string
    {
        return trim((string) $this->code_prefix);
    }

    /** Whitelisted, so it is safe to name in SQL. */
    public function codeColumn(): string
    {
        return array_key_exists((string) $this->code_column, self::CODE_COLUMNS)
            ? (string) $this->code_column
            : 'BADGENUMBER';
    }

    /** Once punches are stored, the database and table cannot change: their ids would mix. */
    public function hasPunches(): bool
    {
        return (int) $this->last_id > 0 || $this->last_time !== null;
    }

    public function watermarkLabel(): string
    {
        if ($this->usesTimeKeyset()) {
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
