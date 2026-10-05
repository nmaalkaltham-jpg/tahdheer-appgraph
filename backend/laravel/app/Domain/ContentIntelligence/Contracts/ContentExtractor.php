<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Contracts;

use App\Domain\ContentIntelligence\ExtractionOutcome;

/**
 * محوّل استخراجٍ واحد لصيغةٍ واحدة (البند ٥: التوسّع بمحوّلٍ لا بـ`switch`).
 *
 * لماذا محوّل لا `match ($mime)` واحدة كبيرة: الصيغة الجديدة يجب أن تكون
 * ملفًّا يُضاف وسطرًا في السجلّ، لا جراحةً في دالّةٍ تكبر كلّما اتّسع
 * الكتالوج. والأهمّ: كلّ محوّلٍ يُختبَر وحده ببايتاته، و`switch` عملاقة
 * تُختبَر كلّها أو لا تُختبَر.
 *
 * العقد:
 *  · لا شبكة ولا قرص ولا قاعدة بيانات داخل `extract` — بايتاتٌ داخلة ونتيجةٌ
 *    خارجة. القراءة مسؤوليّة الخدمة، وهي التي تعرف التخزين.
 *  · لا استثناء يخرج من `extract`: العطل يُعاد `ExtractionOutcome::failed`
 *    برمزٍ يُقرأ. استثناءٌ يخرج يُسقط دفعةً كاملة من أجل ملفٍّ واحد.
 *  · لا نجاحٌ مُلفَّق: ما لم يُقرأ يُعلَن `capabilityGap` باسمه.
 */
interface ContentExtractor
{
    /** اسمٌ ثابت يُخزَّن في `asset_extractions.extractor`. */
    public function name(): string;

    /**
     * نسخة المستخرِج — رفعُها يعني «أعِد معالجة كلّ شيء بهذا المستخرِج».
     *
     * تُخزَّن في المفتاح الفريد، فالصفوف القديمة تبقى بجوار الجديدة.
     */
    public function version(): string;

    /** هل يتولّى هذا النوع؟ النوع كما في `assets.mime` بلا مُعاملات. */
    public function supports(string $mime): bool;

    /**
     * لا يرمي أبدًا — العطل نتيجةٌ لا استثناء.
     *
     * النوع المعلَن يُمرَّر مع البايتات: محوّلٌ واحد قد يتولّى عائلةَ أنواع،
     * والعجزُ المسجَّل يجب أن يسمّي النوع الذي عجز عنه لا «غير مدعوم».
     */
    public function extract(string $bytes, string $mime): ExtractionOutcome;
}
