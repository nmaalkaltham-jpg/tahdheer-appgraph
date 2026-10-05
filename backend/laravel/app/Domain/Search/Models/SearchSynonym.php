<?php

namespace App\Domain\Search\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SearchSynonym extends Model
{
    protected $guarded = [];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', 'active');
    }
}
