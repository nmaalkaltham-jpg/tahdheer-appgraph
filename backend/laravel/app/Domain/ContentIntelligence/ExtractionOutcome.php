<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence;

/**
 * نتيجة مستخرِجٍ واحد على بايتاتٍ واحدة — قيمةٌ محضة بلا قاعدة بيانات.
 *
 * المستخرِج لا يكتب صفًّا ولا يعرف جدولًا: يعيد ما وجد، والخدمة وحدها تقرّر
 * أين يُحفَظ وهل يُحفَظ. وهكذا يُختبَر كلّ مستخرِجٍ ببايتاتٍ في الذاكرة بلا
 * قاعدةٍ ولا تخزين (البند ١٤٩: اختبارٌ حتميّ).
 */
final readonly class ExtractionOutcome
{
    /**
     * @param  string|null  $text  النصّ كما ورد في المصدر — بلا تطبيعٍ عربيّ.
     *                             التطبيع واحدٌ في المشروع كلّه
     *                             (`app/Domain/Search/ArabicNormalizer.php:16`)
     *                             ويُطبَّق وقت الفهرسة على النصّ والاستعلام
     *                             معًا. تطبيعُه هنا يُتلف النصّ القانونيّ
     *                             المخزَّن ولا يُرجَّع.
     * @param  int|null  $units  عدد الوحدات النصّيّة المقيسة، أو `null` إن لم تُقَس.
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public ExtractionStatus $status,
        public ?string $text,
        public ?int $units,
        public array $meta,
        public ?string $errorCode,
    ) {}

    /** @param array<string, mixed> $meta */
    public static function ok(string $text, int $units, array $meta = []): self
    {
        return new self(ExtractionStatus::Ok, $text, $units, $meta, null);
    }

    /**
     * نصٌّ ناقص — و`capability` تسمّي الجزء الذي لم يُقرأ.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function partial(string $text, int $units, array $meta = []): self
    {
        return new self(ExtractionStatus::Partial, $text, $units, $meta, null);
    }

    /**
     * عجزٌ معلَن — لا نصّ ولا ادّعاء نصّ.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function capabilityGap(string $errorCode, array $meta = []): self
    {
        return new self(ExtractionStatus::CapabilityGap, null, null, $meta, $errorCode);
    }

    /** @param array<string, mixed> $meta */
    public static function failed(string $errorCode, array $meta = []): self
    {
        return new self(ExtractionStatus::Failed, null, null, $meta, $errorCode);
    }

    /** @param array<string, mixed> $extra */
    public function withMeta(array $extra): self
    {
        return new self($this->status, $this->text, $this->units, [...$this->meta, ...$extra], $this->errorCode);
    }
}
