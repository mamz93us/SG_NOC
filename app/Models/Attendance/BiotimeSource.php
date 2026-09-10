<?php

namespace App\Models\Attendance;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * One ZKTeco BioTime SQL Server database the NOC reads punches from.
 *
 * The connection is built at runtime by Services\Attendance\BioTimeConnection
 * under the name `biotime_{id}`; nothing about it lives in config/database.php.
 *
 * `last_id` is the iclock_transaction.id watermark, not a punch time — an
 * offline device uploads old punches days later with new ids.
 */
class BiotimeSource extends Model
{
    protected $fillable = [
        'name',
        'host',
        'port',
        'database',
        'username',
        'password',
        'trust_server_certificate',
        'enabled',
        'default_branch_id',
        'import_from',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'port' => 'integer',
        'trust_server_certificate' => 'boolean',
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
