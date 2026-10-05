<?php

declare(strict_types=1);

namespace App\Domain\Finance;

/**
 * فرقٌ ماليّ واحد — بكلّ ما يلزم للتصرّف فيه.
 *
 * ─── لماذا هذا الشكل ───
 *
 * «فرق مبلغ على الطلب ١٢» جملةٌ تُقرأ ولا يُفعل بها شيء. والمشغّل يحتاج
 * أربعة أشياء ليتصرّف: كم كان يُتوقَّع، وكم هو فعلًا، وماذا يقول المزوّد،
 * وما الفعل المتاح. وبلا آخرها يُفتح تذكرةٌ إلى مهندس.
 *
 * ─── `providerVerdict` ───
 *
 * `UNKNOWN` ليست نقصًا في الجواب بل جوابٌ بذاته: تعني أنّ ما عند المزوّد
 * غير معروف لنا، فالفرق قد يكون في دفترنا وقد يكون في فهمنا. وادّعاءُ
 * حكمٍ لم يصدر عن المزوّد هو ما يجعل المشغّل يتّهم البنك بلا دليل.
 */
final readonly class Finding
{
    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $affectedEntities
     */
    public function __construct(
        public string $code,
        public string $severity,
        public array $expected,
        public array $actual,
        public string $providerVerdict,
        public array $evidence,
        public array $affectedEntities,
        public string $repairAction,
        public string $dedupeKey,
        public string $title,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'title' => $this->title,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'providerVerdict' => $this->providerVerdict,
            'evidence' => $this->evidence,
            'affectedEntities' => $this->affectedEntities,
            'repairAction' => $this->repairAction,
            'dedupeKey' => $this->dedupeKey,
        ];
    }
}
