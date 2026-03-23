<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class HrApiKey extends Model
{
    protected $fillable = [
        'name', 'description', 'key_hash', 'key_prefix',
        'last_used_at', 'last_used_ip', 'is_active',
        'revoked_at', 'created_by',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Generate a new raw key string and return [rawKey, model].
     * The raw key is ONLY shown once — never stored in plain text.
     */
    public static function generate(string $name, ?string $description = null, ?int $userId = null): array
    {
        $raw    = 'hrk_' . Str::random(40);
        $prefix = substr($raw, 0, 8);

        $model = static::create([
            'name'        => $name,
            'description' => $description,
            'key_hash'    => bcrypt($raw),
            'key_prefix'  => $prefix,
            'is_active'   => true,
            'created_by'  => $userId,
        ]);

        return [$raw, $model];
    }

    /**
     * Find a matching active key from a raw token string.
     * Returns the HrApiKey model or null.
     */
    public static function findByRawKey(string $raw): ?static
    {
        $prefix = substr($raw, 0, 8);

        return static::where('key_prefix', $prefix)
            ->where('is_active', true)
            ->get()
            ->first(fn ($k) => \Hash::check($raw, $k->key_hash));
    }

    public function recordUsage(string $ip): void
    {
        $this->update([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
        ]);
    }

    public function revoke(): void
    {
        $this->update([
            'is_active'  => false,
            'revoked_at' => now(),
        ]);
    }
}
