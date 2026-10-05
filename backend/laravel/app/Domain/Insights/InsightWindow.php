<?php

namespace App\Domain\Insights;

use Carbon\CarbonImmutable;

/**
 * مدى القراءة — يُحلّ مرّةً ويُمرَّر إلى كلّ مُجمِّع.
 *
 * ─── لماذا لا يحلّه كلّ مُجمِّع بنفسه ───
 *
 * تسعة مُجمِّعاتٍ تقرأ `from` و`to` من الطلب تعني تسع نسخٍ من قاعدة «نهاية
 * اليوم» و«المدى السابق مساوٍ في الطول». وأوّل نسخةٍ تنسى `endOfDay` تُسقط
 * يومًا كاملًا من لوحةٍ واحدة بينما بقيّة اللوحة صحيحة — وهو أسوأ عطلٍ ممكن:
 * متّسقٌ مع نفسه ومخالفٌ لجاره.
 *
 * ─── والمدى السابق جزءٌ من المدى لا حسابٌ منفصل ───
 *
 * المقارنة بمدًى أقصر أو أطول تجعل كلّ نموٍّ وهمًا. فطولُ السابق يُشتقّ من
 * طول الحاليّ هنا، لا في موضع الاستعمال.
 */
final readonly class InsightWindow
{
    /** أطول مدًى مقبول — مرآة `DashboardSummaryController`. */
    public const MAX_DAYS = 730;

    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $days,
        public CarbonImmutable $previousFrom,
        public CarbonImmutable $previousTo,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromRequest(array $data): self
    {
        $to = isset($data['to'])
            ? CarbonImmutable::parse((string) $data['to'])->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $from = isset($data['from'])
            ? CarbonImmutable::parse((string) $data['from'])->startOfDay()
            : $to->subDays(29)->startOfDay();

        // مقلوبٌ يُقوَّم ولا يُرفض: خطأُ ترتيبٍ في رابطٍ منسوخ لا يستحقّ ٤٢٢.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay()) + 1;

        if ($days > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1)->startOfDay();
            $days = self::MAX_DAYS;
        }

        $previousTo = $from->subDay()->endOfDay();

        return new self($from, $to, $days, $previousTo->subDays($days - 1)->startOfDay(), $previousTo);
    }

    /** الوسيطتان كما تُكتبان في روابط التحقيق — تاريخٌ لا طابعُ وقت. */
    public function queryString(): string
    {
        return 'from='.$this->from->toDateString().'&to='.$this->to->toDateString();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'days' => $this->days,
            'previousFrom' => $this->previousFrom->toIso8601String(),
            'previousTo' => $this->previousTo->toIso8601String(),
        ];
    }
}
