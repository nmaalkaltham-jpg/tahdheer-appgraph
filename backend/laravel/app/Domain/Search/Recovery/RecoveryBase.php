<?php

namespace App\Domain\Search\Recovery;

/**
 * نقطةُ انطلاقٍ للتخفيف: نصٌّ متبقٍّ ومرشِّحاتٌ مُستخرَجة.
 *
 * الاستخراج يقع في `QueryPipeline` وحده — هذا الصنف ينقل نتيجته إلى محرّك
 * التعافي بلا أن يعرف المحرّكُ شيئًا عن المفردات ولا عن قاعدة البيانات.
 * ولذلك يُبنى منه اثنان: واحدٌ من نصّ الزائر، وآخرُ من نصٍّ مُصحَّح إملائيًّا،
 * فيسري سلّمُ التخفيف نفسه على الحالتين بلا فرعٍ ثانٍ.
 */
final class RecoveryBase
{
    /** @param array<string, string|null> $filters */
    public function __construct(
        public readonly string $residual,
        public readonly array $filters,
    ) {}

    /** @return list<string> */
    public function tokens(): array
    {
        $tokens = preg_split('/\s+/u', trim($this->residual)) ?: [];

        return array_values(array_filter(
            $tokens,
            static fn (string $t): bool => mb_strlen($t) >= 2,
        ));
    }
}
