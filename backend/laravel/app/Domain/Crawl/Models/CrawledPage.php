<?php

declare(strict_types=1);

namespace App\Domain\Crawl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * عقدة في رسم الروابط.
 *
 * `depth = null` تعني «لم يُوصَل إليها بالروابط» — وهو تعريف اليتيم. صفرٌ
 * مكانها يقول «على بُعد نقرة من الرئيسية»، وهو عكس الحقيقة تمامًا.
 *
 * @property string $path
 * @property int|null $depth
 * @property int $inbound
 */
class CrawledPage extends Model
{
    protected $table = 'crawled_pages';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => 'integer', 'depth' => 'integer',
            'inbound' => 'integer', 'outbound' => 'integer',
            'crawled_at' => 'datetime',
        ];
    }
}
