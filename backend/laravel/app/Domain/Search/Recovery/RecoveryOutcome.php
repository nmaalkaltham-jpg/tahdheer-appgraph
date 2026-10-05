<?php

namespace App\Domain\Search\Recovery;

use App\Domain\Search\SearchResult;

/**
 * حصيلةُ التعافي — وهي **تفسيرٌ** لا نتيجةٌ وحدها.
 *
 * `relaxed=true` وحده كان يخفي الفارق بين «أسقطنا كلمةً لا يعرفها الكتالوج»
 * و«أسقطنا الصفَّ والمرحلة فصارت الإجابة عن سؤالٍ أوسع». والفرق بينهما هو
 * كلُّ الفرق عند الزائر: الأولى تصحيحٌ، والثانية اعترافٌ بأن ما طلبه ليس
 * موجودًا. فكلّ حصيلةٍ تحمل نمطَها وما أُسقط وتغطيتَها ودرجةَ ثقتها.
 */
final class RecoveryOutcome
{
    public const NONE = 'NONE';

    public const TYPO = 'TYPO';

    public const PARTIAL = 'PARTIAL';

    public const UNKNOWN_TOKEN = 'UNKNOWN_TOKEN';

    public const OVER_SPECIFIED = 'OVER_SPECIFIED';

    public const MIXED = 'MIXED';

    /**
     * @param  list<string>  $removed  مفاتيح القيود المُسقَطة
     * @param  list<string>  $retained  مفاتيح القيود الباقية
     * @param  list<string>  $candidates  الاستعلامات المرشَّحة التي جُرِّبت
     * @param  list<string>  $legacyRelaxed  صيغة `meta.relaxed` القديمة — عقدٌ حيٌّ مع المتجر
     * @param  array<string, string|null>  $filtersUsed  المرشِّحات كما طُبِّقت فعلًا بعد الإسقاط
     */
    public function __construct(
        public readonly string $mode,
        public readonly ?SearchResult $result,
        public readonly array $removed,
        public readonly array $retained,
        public readonly array $candidates,
        public readonly float $coverage,
        public readonly float $confidence,
        public readonly int $depth,
        public readonly int $searches,
        public readonly string $reason,
        public readonly array $legacyRelaxed = [],
        public readonly string $queryUsed = '',
        public readonly array $filtersUsed = [],
    ) {}

    public static function none(int $searches, string $reason): self
    {
        return new self(self::NONE, null, [], [], [], 0.0, 0.0, 0, $searches, $reason);
    }

    public function recovered(): bool
    {
        return $this->mode !== self::NONE && $this->result !== null;
    }

    /** التشخيص كما يُسجَّل ويُعرض — بلا كائناتٍ ولا نتائج. */
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'removed' => $this->removed,
            'retained' => $this->retained,
            'candidates' => $this->candidates,
            'coverage' => round($this->coverage, 4),
            'confidence' => round($this->confidence, 4),
            'depth' => $this->depth,
            'searches' => $this->searches,
            'reason' => $this->reason,
        ];
    }
}
