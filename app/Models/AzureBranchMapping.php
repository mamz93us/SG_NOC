<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AzureBranchMapping extends Model
{
    protected $fillable = ['keyword', 'branch_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
