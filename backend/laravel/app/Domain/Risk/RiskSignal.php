<?php

namespace App\Domain\Risk;

/**
 * إشارةُ خطرٍ واحدة (§١٠٥).
 *
 * الإشارة ليست حكمًا. تحمل رمزًا يُقرأ ووزنًا وما قيس فيها، ويبقى القرار
 * للمحرّك بعد جمعها. وحفظُها كاملةً هو ما يجعل «لماذا مُنع هذا الزبون؟»
 * سؤالًا له جواب بعد شهر.
 */
final readonly class RiskSignal
{
    /** @param array<string, mixed> $measured ما قيس فعلًا — لا تفسيرًا له. */
    public function __construct(
        public string $code,
        public int $weight,
        public string $description,
        public array $measured = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'weight' => $this->weight,
            'description' => $this->description,
            'measured' => $this->measured,
        ];
    }
}
