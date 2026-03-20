<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTemplateVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'template_id', 'version', 'definition', 'approval_chain', 'changed_by',
    ];

    protected $casts = [
        'definition'     => 'array',
        'approval_chain' => 'array',
        'created_at'     => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'template_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
