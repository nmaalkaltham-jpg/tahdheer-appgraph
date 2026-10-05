<?php

namespace App\Domain\Sitemaps;

use Illuminate\Database\Eloquent\Model;

/**
 * سجل lastmod الصادق — بصمة محتوى لكل رابط مع تاريخ آخر تغيّر فعلي.
 *
 * One row per URL. `lastmod_at` moves ONLY when `content_hash` changes.
 */
class SitemapUrlState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['lastmod_at' => 'datetime'];
    }
}
