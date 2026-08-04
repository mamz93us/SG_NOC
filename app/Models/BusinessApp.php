<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A business system NOC does not own but does gate access to — Salesforce,
 * Oracle, and whatever gets added next.
 *
 * NOC never creates these accounts. It records that one is needed, adds the
 * employee to the matching Azure security group, and emails the people who
 * administer that system so they can create it.
 */
class BusinessApp extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'request_emails',
        'identity_group_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function identityGroup(): BelongsTo
    {
        return $this->belongsTo(IdentityGroup::class, 'identity_group_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(EmployeeAppAccount::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /**
     * Apps offered on the manager's onboarding form, in display order.
     */
    public static function selectable()
    {
        return static::active()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Recipients of the "please create this account" email, as a clean array.
     * Stored comma-separated so a team alias and an individual can both be set.
     *
     * @return array<int, string>
     */
    public function requestRecipients(): array
    {
        if (! $this->request_emails) {
            return [];
        }

        return collect(preg_split('/[,;\s]+/', $this->request_emails))
            ->map(fn ($e) => trim($e))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * An app can only actually be actioned if someone gets told about it.
     * Without recipients the request would vanish silently.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->requestRecipients());
    }
}
