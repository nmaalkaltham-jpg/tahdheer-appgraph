<?php

namespace App\Domain\Search\Models;

use Illuminate\Database\Eloquent\Model;

class SearchIndexState extends Model
{
    protected $table = 'search_index_state';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_built_at' => 'datetime'];
    }

    /** The single bookkeeping row for an engine, created on first use. */
    public static function forEngine(string $engine = 'meilisearch'): self
    {
        return self::query()->firstOrCreate(
            ['engine' => $engine],
            ['index_name' => 'products_v0', 'generation' => 0, 'status' => 'idle'],
        );
    }
}
