<?php

declare(strict_types=1);

namespace App\Domain\Crawl\Models;

use Illuminate\Database\Eloquent\Model;

/** حافة موجَّهة بين صفحتين، بنصّ مرساتها وموضعها. */
class InternalLink extends Model
{
    protected $table = 'internal_links';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['nofollow' => 'boolean'];
    }
}
