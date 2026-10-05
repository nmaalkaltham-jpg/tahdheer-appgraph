<?php

namespace App\Domain\Insights;

/**
 * مؤشّرٌ واحد — قيمةٌ مقيسة، أو إعلانُ أنّ لا مصدر لها.
 *
 * ─── لماذا نوعٌ لا مصفوفةٌ حرّة ───
 *
 * قاعدة الصدق في هذا المشروع تقول: ما لا يُقاس يُعلَن ولا يُرَدّ صفرًا. وهي
 * قاعدةٌ تُكسر بالسهو لا بالقصد — كاتبُ مُجمِّعٍ جديد يكتب `'value' => 0`
 * لأنّه أسرع من كتابة السبب، فيصير «قِسنا فلم نجد» و«لم نقس» رقمًا واحدًا.
 *
 * هذا النوع يجعل الكسر مستحيلًا لا مذمومًا: لا سبيل إلى بناء مؤشّرٍ إلّا
 * بأحد ثلاثة مصانع، و`notConfigured` و`noData` لا يقبلان قيمةً أصلًا بينما
 * `measured` لا يقبل سببًا. فالحالة والحمولة لا تفترقان.
 *
 * ─── الفرق بين الحالتين الغائبتين ───
 *
 * `NOT_CONFIGURED` أداةُ القياس غير مبنيّة: لا عمود ولا كاتب ولا جدول. علاجه
 * هندسيّ — يُبنى القياس.
 *
 * `NO_DATA` الأداة مبنيّة وقاست فلم تجد صفًّا واحدًا. علاجه تشغيليّ أو لا
 * علاج له — قد يكون الجواب الصحيح ببساطة «لم يحدث بعد».
 *
 * خلطهما يُضيع التشخيص: «لا نقرات بحث» تعني أنّ الفهرسة ضعيفة إن كان القياس
 * يعمل، وتعني أنّ لا أحد يسجّل النقرات إن لم يكن.
 */
final readonly class Metric
{
    /**
     * @param  'MEASURED'|'NOT_CONFIGURED'|'NO_DATA'  $state
     * @param  'money'|'count'|'percent'|'duration'|'text'  $format
     */
    private function __construct(
        public string $key,
        public string $label,
        public string $state,
        public string $format,
        public int|float|string|null $value,
        public ?string $formula,
        public ?string $reason,
        public ?string $href,
        public int|float|null $previous,
        public bool $alert,
    ) {}

    /**
     * قيمةٌ خرجت من استعلامٍ حقيقيّ.
     *
     * `formula` ليست توثيقًا: رقمٌ بلا تعريفٍ يُقارَن بما لا يشبهه، و«معدّل
     * الاسترداد» بلا مقامٍ معلن يُقرأ على وجهين.
     */
    public static function measured(
        string $key,
        string $label,
        string $format,
        int|float|string $value,
        string $formula,
        ?string $href = null,
        int|float|null $previous = null,
        bool $alert = false,
    ): self {
        return new self($key, $label, 'MEASURED', $format, $value, $formula, null, $href, $previous, $alert);
    }

    /** أداة القياس غير مبنيّة — والسبب يقول ما ينقص بالضبط. */
    public static function notConfigured(string $key, string $label, string $reason): self
    {
        return new self($key, $label, 'NOT_CONFIGURED', 'text', null, null, $reason, null, null, false);
    }

    /** الأداة قاست ولم تجد صفًّا. */
    public static function noData(string $key, string $label, string $reason, ?string $href = null): self
    {
        return new self($key, $label, 'NO_DATA', 'text', null, null, $reason, $href, null, false);
    }

    /**
     * نسبةٌ بمقامٍ مُتحقَّقٍ منه.
     *
     * القسمة على صفرٍ غير معرَّفة، و«٠٪» و«١٠٠٪» كلاهما اختراعٌ حينها. المقام
     * الصفريّ يُعلَن `NO_DATA` بمقامه مذكورًا، فيعرف القارئ أنّ النقص في
     * المقام لا في البسط.
     */
    public static function ratio(
        string $key,
        string $label,
        int $numerator,
        int $denominator,
        string $formula,
        string $emptyReason,
        ?string $href = null,
        bool $alert = false,
    ): self {
        if ($denominator === 0) {
            return self::noData($key, $label, $emptyReason, $href);
        }

        return self::measured(
            $key,
            $label,
            'percent',
            round(($numerator / $denominator) * 100, 1),
            $formula,
            $href,
            alert: $alert,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'key' => $this->key,
            'label' => $this->label,
            'state' => $this->state,
        ];

        if ($this->state === 'MEASURED') {
            $out['format'] = $this->format;
            $out['value'] = $this->value;
            $out['formula'] = $this->formula;

            if ($this->previous !== null) {
                $out['previous'] = $this->previous;
            }
            if ($this->alert) {
                $out['alert'] = true;
            }
        } else {
            $out['reason'] = $this->reason;
        }

        if ($this->href !== null) {
            $out['href'] = $this->href;
        }

        return $out;
    }
}
