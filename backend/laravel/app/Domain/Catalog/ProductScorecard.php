<?php

namespace App\Domain\Catalog;

/**
 * بطاقة جودة منتج (§٦٠ §١٥٧).
 *
 * §١٥٧ صريحة: «لا تُخفِ المعلومات الناقصة خلف درجةٍ وسطيّة واحدة». والسبب
 * أنّ المتوسّط يكذب في الاتّجاهين: منتجٌ كامل المحتوى بلا إعدادِ تسليمٍ
 * يبدو «٨٥٪ جاهز» وهو غير قابلٍ للبيع أصلًا، ومنتجٌ سليم التسليم ضعيف
 * المحتوى يبدو المشكلة نفسها. الأبعاد تبقى منفصلة، ولكلٍّ منها نواقصُه
 * مسمّاةً لا معدودةً.
 */
final readonly class ProductScorecard
{
    /**
     * @param  array<string, int>  $scores  البُعد ⇦ النسبة (٠–١٠٠)
     * @param  array<string, list<string>>  $missing  البُعد ⇦ ما ينقصه بالاسم
     */
    public function __construct(
        public int $productGroupId,
        public array $scores,
        public array $missing,
    ) {}

    /** أبعادٌ تمنع النشر حين تكون ناقصة (§٦٢). */
    public const PUBLICATION_BLOCKING = ['IDENTITY', 'FULFILLMENT'];

    public function score(string $dimension): int
    {
        return $this->scores[$dimension] ?? 0;
    }

    /** @return list<string> */
    public function missingIn(string $dimension): array
    {
        return $this->missing[$dimension] ?? [];
    }

    /**
     * هل يجوز نشره؟ (§٦٢)
     *
     * البوّابة تسأل عن الأبعاد الحاجبة وحدها. منتجٌ بلا أسئلةٍ شائعة يُنشر
     * ناقصًا؛ ومنتجٌ بلا استراتيجيّة تسليمٍ لا يُنشر أصلًا لأنّه لا يُسلَّم.
     */
    public function canPublish(): bool
    {
        foreach (self::PUBLICATION_BLOCKING as $dimension) {
            if ($this->missingIn($dimension) !== []) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> أسباب المنع بالاسم، لا عددًا. */
    public function publicationBlockers(): array
    {
        $blockers = [];

        foreach (self::PUBLICATION_BLOCKING as $dimension) {
            foreach ($this->missingIn($dimension) as $item) {
                $blockers[] = "{$dimension}: {$item}";
            }
        }

        return $blockers;
    }
}
