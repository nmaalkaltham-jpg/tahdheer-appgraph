<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * قاطع الدائرة — يمنع نداء خدمةٍ نعلم أنّها ساقطة.
 *
 * ─── لماذا ───
 *
 * خدمةٌ ميّتة تُنادى خمسين مرّةً في الدقيقة تكلّف خمسين مهلةَ اتّصال، وكلّ
 * مهلةٍ تحجز عامل PHP. فيسقط المتجر بسبب ميزةٍ اختياريّة. القاطع يحوّل العطل
 * من «بطءٍ يلتهم العمّال» إلى «رفضٍ فوريّ» — وهو ما يُبقي الشراء والبحث
 * يعملان بينما الذكاء ساقط.
 *
 * ─── العدّاد في الذاكرة المؤقّتة لا في الصنف ───
 *
 * PHP لا يحتفظ بحالةٍ بين الطلبات. عدّادٌ في خاصّيّةٍ كان يُصفَّر مع كلّ طلب
 * فلا يُفتح القاطع أبدًا. والمفتاح مشترك بين كلّ العمّال فيُفتح لهم جميعًا.
 */
final class AiCircuitBreaker
{
    private const FAILURES_KEY = 'ai:breaker:failures';

    private const OPEN_KEY = 'ai:breaker:open';

    public function __construct(
        private readonly int $threshold,
        private readonly int $openSeconds,
    ) {}

    public function isOpen(): bool
    {
        return Cache::get(self::OPEN_KEY) !== null;
    }

    public function openSeconds(): int
    {
        return $this->openSeconds;
    }

    /**
     * نجاحٌ يمسح العدّاد كاملًا لا ينقصه واحدًا.
     *
     * النقصان يجعل خدمةً تتأرجح تبقى تحت العتبة إلى الأبد فلا يُفتح القاطع،
     * بينما نصف الطلبات تفشل. النجاح دليلُ تعافٍ، والتعافي يُصفّر.
     */
    public function recordSuccess(): void
    {
        Cache::forget(self::FAILURES_KEY);
        Cache::forget(self::OPEN_KEY);
    }

    public function recordFailure(): void
    {
        /*
         * المهلة تُجدَّد مع كلّ فشل: فشلٌ واحد كلّ ساعة ليس انهيارًا، ونافذةٌ
         * متحرّكة تمنع تراكم أخطاءٍ متفرّقة حتّى تفتح القاطع بلا سبب.
         */
        $failures = (int) Cache::get(self::FAILURES_KEY, 0) + 1;
        Cache::put(self::FAILURES_KEY, $failures, $this->openSeconds * 2);

        if ($failures >= $this->threshold) {
            Cache::put(self::OPEN_KEY, true, $this->openSeconds);
        }
    }

    public function failures(): int
    {
        return (int) Cache::get(self::FAILURES_KEY, 0);
    }
}
