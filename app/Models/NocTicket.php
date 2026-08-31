<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ticket submitted to the external ticketing system from the NOC's
 * Create Ticket page. Local audit copy only — the ticketing system owns the
 * ticket's real state (status, engineer, close date), which is why nothing
 * here is ever updated after the submit.
 */
class NocTicket extends Model
{
    protected $fillable = [
        'ticket_id',
        'title',
        'description',
        'category_id',
        'category_name',
        'subcategory_id',
        'subcategory_name',
        'type_id',
        'type_name',
        'priority_id',
        'priority_name',
        'channel_id',
        'requester_email',
        'requester_name',
        'requester_azure_id',
        'attachment_name',
        'attachment_size',
        'submitted_by_user_id',
        'submitted_by_name',
        'status',
        'http_status',
        'error',
        'response',
    ];

    protected $casts = [
        'response' => 'array',
        'ticket_id' => 'integer',
        'category_id' => 'integer',
        'subcategory_id' => 'integer',
        'type_id' => 'integer',
        'priority_id' => 'integer',
        'channel_id' => 'integer',
        'attachment_size' => 'integer',
        'http_status' => 'integer',
    ];

    public const STATUS_CREATED = 'created';

    public const STATUS_FAILED = 'failed';

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function isCreated(): bool
    {
        return $this->status === self::STATUS_CREATED;
    }

    public function statusBadgeClass(): string
    {
        return $this->isCreated() ? 'bg-success' : 'bg-danger';
    }

    /** Human size for the attachment column. */
    public function attachmentSizeLabel(): ?string
    {
        if (! $this->attachment_size) {
            return null;
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->attachment_size;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
