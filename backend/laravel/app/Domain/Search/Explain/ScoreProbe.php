<?php

declare(strict_types=1);

namespace App\Domain\Search\Explain;

use App\Domain\Search\Providers\PgFallbackProvider;
use App\Domain\Search\SearchRequest;
use App\Models\Product;
use ReflectionClass;
use ReflectionMethod;

/**
 * مسبارٌ يُشغّل دالّة الترتيب الحقيقيّة — ولا يُعيد كتابتها.
 *
 * ─── لماذا الانعكاس، ولماذا هو الخيار الأقلّ كذبًا ───
 *
 * `PgFallbackProvider::score()` خاصّة (`Providers/PgFallbackProvider.php:212`)،
 * وهي **الدالّة الوحيدة** التي تُنتج درجة كلّ منتج في المسار الحيّ. أمامنا
 * ثلاثة طرق لتفسير درجةٍ ما:
 *
 *   ١ — نُعيد كتابة شروطها هنا. مرفوض: نسختان من منطقٍ واحد تتباعدان في أوّل
 *       تعديلٍ يمسّ إحداهما، والمفسِّر حينها يروي قصّةً عن ترتيبٍ لم يحدث.
 *       ومفسِّرٌ يكذب أسوأ من لا مفسِّر: الأوّل يُعلّم نموذجًا خاطئًا عن المحرّك.
 *   ٢ — نغيّر `score()` لتردّ شرحًا مع الدرجة. مرفوض هنا لا مبدئيًّا: الملفّ
 *       يملكه مسارٌ آخر في هذه الجولة، ولمس ملفّ غيرك يكسر عمله.
 *   ٣ — نُنادي `score()` نفسها. وهذا ما يقع: البايت-كود المُنفَّذ هو بايت-كود
 *       الإنتاج حرفًا بحرف، فالتباعد مستحيلٌ بنيويًّا لا بالانضباط.
 *
 * وكذلك الأوزان: تُقرأ من ثوابت الصنف لا تُنسَخ. تغييرُ `W_TITLE_TOKEN` من
 * ٤٥٠ إلى غيرها يتبعه هذا المسبار في الطلب التالي بلا تعديل سطر.
 */
final class ScoreProbe
{
    /** أسماءٌ عربيّة للطبقات المعروفة. المجهول يُعرض باسمه الخام لا يُخترَع له وصف. */
    private const LABELS = [
        'W_SKU_EXACT' => 'رمز المنتج مطابقٌ للاستعلام كاملًا',
        'W_TITLE_EXACT' => 'العنوان مطابقٌ للاستعلام كاملًا',
        'W_SKU_PARTIAL' => 'رمز المنتج يحتوي الاستعلام',
        'W_TITLE_PREFIX' => 'العنوان يبدأ بالاستعلام',
        'W_TITLE_TOKEN' => 'كلمةٌ من الاستعلام داخل العنوان',
        'W_KEYWORD_TOKEN' => 'كلمةٌ من كلمات المنتج المفتاحيّة',
        'W_BODY_TOKEN' => 'كلمةٌ داخل العنوان الفرعي أو الوصف المختصر',
        'W_DESC_TOKEN' => 'كلمةٌ داخل الوصف الكامل',
        'W_FILE_TOKEN' => 'كلمةٌ داخل نصّ الملفّ المستخرَج',
    ];

    private readonly ReflectionMethod $score;

    /** @var list<array{name: string, value: int, label: string}> تنازليًّا */
    private readonly array $tiers;

    public function __construct(private readonly PgFallbackProvider $provider)
    {
        $reflection = new ReflectionClass(PgFallbackProvider::class);

        /*
         * بلا `setAccessible()`: مُهملةٌ منذ PHP 8.5 لأنّها بلا أثرٍ منذ 8.1 —
         * `getMethod()` تردّ انعكاسًا قابلًا للنداء ولو كانت الدالّة خاصّة.
         * إبقاؤها كان يملأ كلّ طلبٍ بسطر إهمالٍ في السجلّ.
         */
        $this->score = $reflection->getMethod('score');

        /*
         * الثوابت تُقرأ ويُصفّى منها ما ليس وزنًا (`CANDIDATE_CAP` مثلًا سقفُ
         * مرشَّحين لا طبقةُ ترتيب، ولو دخل لصار ١٠٠٠ طبقتين متطابقتين).
         * البادئة `W_` هي عرف الصنف نفسه، لا اختيارٌ من عندنا.
         */
        $weights = [];
        foreach ($reflection->getConstants() as $name => $value) {
            if (str_starts_with($name, 'W_') && is_int($value)) {
                $weights[] = [
                    'name' => $name,
                    'value' => $value,
                    'label' => self::LABELS[$name] ?? $name,
                ];
            }
        }

        usort($weights, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        $this->tiers = $weights;
    }

    /** @return list<array{name: string, value: int, label: string}> */
    public function tiers(): array
    {
        return $this->tiers;
    }

    /** الدرجة كما يحسبها الإنتاج — لا تقدير ولا تقريب. */
    public function score(Product $product, SearchRequest $request): int
    {
        return (int) $this->score->invoke($this->provider, $product, $request);
    }

    /**
     * الطبقة = أكبر وزنٍ لا يتجاوز الدرجة.
     *
     * القاعدة مشتقّةٌ من مجموعة الثوابت وحدها، فلا تعرف شيئًا عن مكافأة
     * التغطية ولا عن سقفها. وكان البديل أن نكتب «٤٥٠ إلى ٥٥٠ ⇒ طبقة العنوان»،
     * وهو رقمٌ ثانٍ يجب أن يُحدَّث مع كلّ تعديلٍ في المكافأة — أي بذرةُ تباعد.
     *
     * @return array{name: string, value: int, label: string}|null
     */
    public function tierFor(int $score): ?array
    {
        /* الدرجة = كلماتٌ × COVERAGE_STEP + الطبقة؛ الطبقة هي الباقي. */
        $score %= PgFallbackProvider::COVERAGE_STEP;

        foreach ($this->tiers as $tier) {
            if ($score >= $tier['value']) {
                return $tier;
            }
        }

        return null;
    }
}
