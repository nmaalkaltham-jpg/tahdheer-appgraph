<?php

declare(strict_types=1);

namespace App\Domain\I18n;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * تدقيق hreflang على مستوى المتجر — إثباتٌ لكل منتج، لا تأكيدٌ عامّ.
 *
 * alternatesFor يشتقّ كل اللغات من بوّابة واحدة، فالتبادلية تصحّ بالبناء.
 * هذا التدقيق لا يفترض ذلك: يُعيد تشغيل البوّابة لكل منتج منشور ويُثبت
 * الثوابت الأربعة صفًّا صفًّا، ويُسمّي المخالف بدل أن يطمئن بلا دليل.
 *
 * قراءة خالصة: لا يكتب شيئًا ولا يُصلح شيئًا — يُبلّغ فقط.
 */
final class HreflangAuditor
{
    /** سقف المخالفين المُعادين لكل فحص — القائمة دليل لا أرشيف. */
    private const OFFENDER_CAP = 50;

    public function __construct(private readonly LanguageEligibility $eligibility) {}

    /**
     * @return array{scannedProducts: int, checkedAt: string, checks: list<array<string, mixed>>}
     */
    public function run(): array
    {
        $checkedAt = now()->toIso8601String();
        $scanned = 0;
        $offenders = [
            'eligibility_gate' => [],
            'self_reference' => [],
            'x_default' => [],
            'reciprocity' => [],
            'canonical_conflict' => [],
        ];

        // سجلّ اللغات يُقرأ مرّة واحدة للجولة كلّها، لا مرّة لكل منتج.
        $locales = Locale::ordered();

        Product::query()->published()->with(['translations', 'seo'])
            ->chunkById(100, function (Collection $products) use ($locales, &$scanned, &$offenders): void {
                foreach ($products as $product) {
                    $scanned++;
                    $this->auditProduct($product, $locales, $offenders);
                }
            });

        $labels = [
            'eligibility_gate' => 'بوابة الأهلية: كل بديل مُعلَن تجتاز لغته شروطها الآن، ولا لغة مستوفية غير مُعلَنة',
            'self_reference' => 'الإشارة الذاتية: صفحة تُعلن بدائل تُدرج رابطها الافتراضي بينها',
            'x_default' => 'المرجع الافتراضي: رابط افتراضي واحد بالضبط يصلح x-default',
            'reciprocity' => 'التبادلية: لا بديل مُعلَن بلا نظير — مجموعة واحدة لكل الصفحات المتناظرة',
            'canonical_conflict' => 'تعارض canonical: لا صفحة تُعلن بدائل لغوية وcanonical يشير بعيدًا عنها',
        ];

        return [
            'scannedProducts' => $scanned,
            'checkedAt' => $checkedAt,
            'checks' => array_map(static fn (string $key): array => [
                'key' => $key,
                'label' => $labels[$key],
                'pass' => $offenders[$key] === [],
                'offenders' => $offenders[$key],
                'checkedAt' => $checkedAt,
            ], array_keys($offenders)),
        ];
    }

    /**
     * @param  Collection<int, Locale>  $locales
     * @param  array<string, list<array<string, mixed>>>  $offenders
     */
    private function auditProduct(Product $product, Collection $locales, array &$offenders): void
    {
        $alternates = $this->eligibility->alternatesFor($product);

        // كم لغة تجتاز بوّابتها وتُعلَن فعلًا — إعادة تشغيل للبوّابة لا نسخ لنتيجتها.
        $emitted = 0;
        foreach ($locales as $locale) {
            if ($this->eligibility->forLocale($product, $locale)['hreflangEmitted']) {
                $emitted++;
            }
        }

        if ($alternates === []) {
            /*
             * لا إعلان لهذا المنتج. المخالفة الوحيدة الممكنة: لغةٌ تدّعي
             * بوّابتها أنها مُعلَنة بينما القائمة فارغة.
             */
            if ($emitted !== 0) {
                $this->flag($offenders, 'eligibility_gate', $product,
                    'لغات تجتاز البوّابة بلا قائمة بدائل مُعلَنة.');
            }

            return;
        }

        if (count($alternates) !== $emitted) {
            $this->flag($offenders, 'eligibility_gate', $product, sprintf(
                'المُعلَن %d والمجتاز للبوّابة %d — يجب أن يتطابقا.',
                count($alternates), $emitted,
            ));
        }

        $defaults = array_values(array_filter($alternates, static fn (array $a): bool => $a['isDefault']));

        if ($defaults === []) {
            $this->flag($offenders, 'self_reference', $product,
                'تُعلن بدائل دون رابطها باللغة الافتراضية.');
        }

        if (count($defaults) !== 1) {
            $this->flag($offenders, 'x_default', $product, sprintf(
                'عدد الروابط الافتراضية %d — المطلوب واحد بالضبط.', count($defaults),
            ));
        }

        // بديل بلا نظير لا يُعلَن أصلًا (قاعدة «أقل من اثنين ⇒ لا شيء»).
        if (count($alternates) < 2) {
            $this->flag($offenders, 'reciprocity', $product,
                'بديل وحيد مُعلَن بلا نظير يقابله.');
        }

        $override = $product->seo->canonical_override ?? null;
        if (is_string($override) && trim($override) !== '') {
            $this->flag($offenders, 'canonical_conflict', $product,
                'canonical يشير إلى '.trim($override).' بينما الصفحة تُعلن بدائل لغوية لنفسها.');
        }
    }

    /** @param array<string, list<array<string, mixed>>> $offenders */
    private function flag(array &$offenders, string $check, Product $product, string $detail): void
    {
        if (count($offenders[$check]) >= self::OFFENDER_CAP) {
            return;
        }

        $offenders[$check][] = [
            'productId' => $product->id,
            'slug' => $product->slug,
            'title' => $product->title,
            'detail' => $detail,
        ];
    }
}
