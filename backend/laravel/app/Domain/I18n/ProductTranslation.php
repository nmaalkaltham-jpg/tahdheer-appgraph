<?php

declare(strict_types=1);

namespace App\Domain\I18n;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ترجمة منتج إلى لغة غير الافتراضية.
 *
 * النصوص مكتوبة بيد محرّر فقط. لا يملأ هذا الجدول مترجمٌ آليّ ولا نموذج
 * لغوي: نصّ مخترَع يُنشر باسم المتجر ويُفهرَس ويُقرأ حقيقةً.
 *
 * @property int $product_id
 * @property string $locale_code
 * @property string|null $title
 * @property string|null $slug
 * @property string $status
 * @property bool $is_indexable
 */
class ProductTranslation extends Model
{
    protected $table = 'product_translations';

    protected $fillable = [
        'product_id', 'locale_code', 'title', 'subtitle', 'short_desc',
        'description', 'slug', 'status', 'is_indexable', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_indexable' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class, 'locale_code', 'code');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
