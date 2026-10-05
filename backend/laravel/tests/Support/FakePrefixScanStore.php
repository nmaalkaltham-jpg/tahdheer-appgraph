<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;

/**
 * متجرُ ذاكرةٍ يدعم `connection()->scan()` — ويردّ المفاتيح **ببادئتَين**.
 *
 * سائق المصفوفة في `phpunit.xml` لا `connection()` له، فالفرع الذي يمسح
 * بالبادئة لم يكن يُشغَّل في الحزمة قطّ. وهذا المتجر يُشغّله، ويحاكي بالضبط
 * ما يفعله Redis في الإنتاج: `scan` يردّ المفتاح كاملًا — بادئةَ العميل
 * وبادئةَ الذاكرة معًا — بينما `forget` تنتظر المفتاح المنطقيّ وحده. فمن
 * حذف ما يردّه المسح مباشرةً ضاعف البادئة وحذف لا شيء.
 *
 * وهو في ملفٍّ مستقلّ لا صنفًا مجهولًا داخل اختبار: الفحص يمرّ على
 * ملفّات الاختبار بأسماء الدوالّ، و`put()`/`forget()`/`flush()` تُربك ذلك حين
 * تُعرَّف داخل صنف حالة اختبار.
 */
final class FakePrefixScanStore implements Store
{
    /** اسم السائق كما يُسجَّل في `Cache::extend`. */
    public const DRIVER = 'fake-scan';

    /** @var array<string, mixed> المفاتيح المنطقيّة بلا بادئة. */
    private array $items = [];

    /**
     * البادئتان اللتان يردّهما `scan` ملتصقتَين بكلّ مفتاح.
     *
     * ترتيبهما كترتيب البناء في الإنتاج: بادئة عميل Redis ثمّ بادئة الذاكرة.
     */
    public function __construct(
        private readonly string $redisPrefix = 'rp_',
        private readonly string $cachePrefix = 'tst_',
    ) {}

    /** الوصلة المزيَّفة: `scan` وحدها — وهي كلّ ما تناديه الخدمة. */
    public function connection(): object
    {
        return new class($this->items, $this->redisPrefix.$this->cachePrefix)
        {
            /** @param  array<string, mixed>  $items */
            public function __construct(private readonly array $items, private readonly string $prefix) {}

            /**
             * يردّ `[cursor, keys]` كـpredis، والمفاتيح كاملةً بالبادئتين.
             *
             * @param  array{match?: string, count?: int}  $options
             * @return array{0: int, 1: list<string>}
             */
            public function scan(mixed &$cursor, array $options = []): array
            {
                $pattern = (string) ($options['match'] ?? '*');
                $matched = [];

                foreach (array_keys($this->items) as $key) {
                    $full = $this->prefix.$key;

                    if (fnmatch($pattern, $full)) {
                        $matched[] = $full;
                    }
                }

                $cursor = 0;

                return [0, $matched];
            }
        };
    }

    public function get($key)
    {
        return $this->items[(string) $key] ?? null;
    }

    public function many(array $keys)
    {
        $found = [];

        foreach ($keys as $key) {
            $found[$key] = $this->get($key);
        }

        return $found;
    }

    public function put($key, $value, $seconds)
    {
        // لا انتهاء صلاحيّةٍ هنا: الاختبار يقيس المسح لا الزمن.
        $this->items[(string) $key] = $value;

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        return $this->items[(string) $key] = (int) ($this->items[(string) $key] ?? 0) + $value;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    public function touch($key, $seconds)
    {
        return array_key_exists((string) $key, $this->items);
    }

    public function forget($key)
    {
        if (! array_key_exists((string) $key, $this->items)) {
            return false;
        }

        unset($this->items[(string) $key]);

        return true;
    }

    public function flush()
    {
        $this->items = [];

        return true;
    }

    /** الخدمة تقرأ `config('cache.prefix')` لا هذه — والمفاتيح هنا منطقيّة بلا بادئة. */
    public function getPrefix()
    {
        return '';
    }
}
