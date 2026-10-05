<?php

declare(strict_types=1);

namespace App\Domain\Sitemaps\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * قياسُ ليلةٍ واحدة لنقاء خريطة الموقع.
 *
 * يُكتب من `sitemap:audit` ويُقرأ من `GET /admin/seo/sitemap/audit`. ولا
 * يُكتب من الشاشة: التدقيق قياسٌ يجريه المجدوِل، والشاشة تقرؤه.
 */
class SitemapAudit extends Model
{
    protected $table = 'sitemap_audits';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
            'clean' => 'boolean',
            'offered' => 'integer',
            'kept' => 'integer',
            'expelled' => 'integer',
            /*
             * `decimal:2` لا `float`: النسبة تُعرض ويُقارَن بها، والعوم يجعل
             * ٩٩٫٩٩ تساوي أحيانًا ٩٩٫٩٩٠٠٠٠٠٠٠٠٠٠١ فيبدو قياسان متطابقان
             * مختلفين.
             */
            'coverage_pct' => 'decimal:2',
            'missing_count' => 'integer',
            'report' => 'array',
        ];
    }
}
