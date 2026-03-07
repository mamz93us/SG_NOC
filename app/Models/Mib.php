<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mib extends Model
{
    protected $fillable = [
        'name',
        'description',
        'file_path',
    ];
}
