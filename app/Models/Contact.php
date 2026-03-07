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
    'job_title',  // Add this
    'phone',
    'email',
    'branch_id',
];


    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
