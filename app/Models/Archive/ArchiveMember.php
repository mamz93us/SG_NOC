<?php

namespace App\Models\Archive;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person may do in one archive.
 *
 * Holding `use-archive-portal` gets someone through the front door; a row here
 * is what actually reaches documents. There is no "all archives" flag by
 * design — only a superuser sees everything — because these archives hold every
 * invoice, contract and HR file the company has scanned since 2013, and the
 * whole point of the split is that reaching one is a deliberate grant.
 */
class ArchiveMember extends Model
{
    /** The abilities a membership can carry, in the order the UI shows them. */
    public const ABILITIES = [
        'can_view' => 'View & search documents',
        'can_add' => 'Add documents (scan, upload, file)',
        'can_edit' => 'Edit index fields',
        'can_delete' => 'Delete documents',
        'can_manage' => 'Manage this archive (fields, members)',
    ];

    protected $fillable = [
        'archive_id',
        'user_id',
        'can_view',
        'can_add',
        'can_edit',
        'can_delete',
        'can_manage',
    ];

    protected $casts = [
        'can_view' => 'boolean',
        'can_add' => 'boolean',
        'can_edit' => 'boolean',
        'can_delete' => 'boolean',
        'can_manage' => 'boolean',
    ];

    public function archive(): BelongsTo
    {
        return $this->belongsTo(Archive::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<int,string> The abilities this row grants, as labels. */
    public function grantedLabels(): array
    {
        return array_values(array_filter(
            array_map(
                fn (string $key, string $label) => $this->{$key} ? $label : null,
                array_keys(self::ABILITIES),
                array_values(self::ABILITIES)
            )
        ));
    }
}
