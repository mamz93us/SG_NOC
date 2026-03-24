<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'job_title',
        'phone',
        'email',
        'branch_id',
        'source',
        'gdms_synced_at',
    ];

    protected $casts = [
        'gdms_synced_at' => 'datetime',
    ];


    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
