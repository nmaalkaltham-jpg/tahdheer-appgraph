<?php

declare(strict_types=1);

namespace App\Domain\Crawl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** جولة زحف: ملخّصها وأرقامها المحسوبة بالعدّ لا بالتقدير. */
class CrawlRun extends Model
{
    protected $table = 'crawl_runs';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pages' => 'integer', 'links' => 'integer', 'broken' => 'integer',
            'orphans' => 'integer', 'max_depth' => 'integer',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    public function pagesRel(): HasMany
    {
        return $this->hasMany(CrawledPage::class, 'run_id');
    }
}
