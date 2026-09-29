<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture inlined in an Oracle announcement, kept as bytes.
 *
 * Written only by {@see \App\Services\OraclePortal\AnnouncementSync}, served
 * only by {@see \App\Http\Controllers\Home\HomeAnnouncementController::image()},
 * which checks the viewer may see the notice first.
 *
 * Out of the automatic audit (config/audit.php): a row is half a megabyte of
 * PNG, and the sync logs its run by hand.
 */
class AnnouncementImage extends Model
{
    protected $fillable = ['announcement_id', 'position', 'mime', 'sha1', 'size', 'bytes'];

    /**
     * Never serialised. The home page caches its announcements, relations and
     * all, in the database cache; the bytes must not ride along.
     */
    protected $hidden = ['bytes'];

    protected $casts = [
        'announcement_id' => 'integer',
        'position' => 'integer',
        'size' => 'integer',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
