<?php

declare(strict_types=1);

namespace App\Domain\I18n;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * لغة متجر.
 *
 * `is_active` و`is_routable` حقيقتان مختلفتان ولا تُدمجان: الأولى قرار
 * تحريري (نريد محتوى بهذه اللغة)، والثانية واقع تقني (الواجهة تخدم مسارها).
 * دمجهما يجعل تفعيلَ لغةٍ في اللوحة يُعلن للعالم صفحاتٍ غير موجودة.
 *
 * @property string $code
 * @property string $name_ar
 * @property string $hreflang
 * @property string $direction
 * @property string|null $url_prefix
 * @property bool $is_default
 * @property bool $is_active
 * @property bool $is_routable
 */
class Locale extends Model
{
    protected $table = 'locales';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code', 'name_ar', 'name_en', 'hreflang', 'direction',
        'url_prefix', 'is_default', 'is_active', 'is_routable', 'position',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'is_routable' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return Collection<int, self> */
    public static function ordered(): Collection
    {
        return static::query()->orderBy('position')->orderBy('code')->get();
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    /**
     * مسار الصفحة بهذه اللغة.
     *
     * الافتراضية بلا بادئة: إضافة `/ar` تنقل كل رابط قائم وتكسر كل ارتباط
     * خارجي وكل صفّ في تقارير البحث.
     */
    public function path(string $path): string
    {
        $prefix = $this->url_prefix;

        return $prefix === null || $prefix === '' ? $path : '/'.trim($prefix, '/').$path;
    }
}
