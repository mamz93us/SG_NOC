<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminLinkCategory extends Model
{
    protected $fillable = ['name', 'icon', 'sort_order'];

    public function links(): HasMany
    {
        return $this->hasMany(AdminLink::class, 'category_id')->orderBy('sort_order');
    }
}
