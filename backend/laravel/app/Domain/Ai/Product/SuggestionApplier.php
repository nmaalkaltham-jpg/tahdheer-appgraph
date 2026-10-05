<?php

declare(strict_types=1);

namespace App\Domain\Ai\Product;

use App\Domain\Ai\Models\AiSuggestion;
use App\Domain\Search\ArabicNormalizer;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * تطبيق اقتراحٍ معتمَد — الموضع الوحيد الذي يكتب فيه الذكاء في المتجر.
 *
 * ─── لماذا موضعٌ واحد ───
 *
 * كلّ مسارٍ يكتب بنفسه كان سينسخ معه: التحقّق من الاعتماد، وحلّ الأسماء إلى
 * مفاتيح، وحرمة ما كتبه التاجر. وأوّل نسخةٍ تنسى الأخيرة تمسح وصفًا كتبه
 * إنسان.
 *
 * ─── وما لا يُكتب أبدًا ───
 *
 * العنوان والوصف لا يُستبدلان هنا. ولا `product_translations` — ملفّها يحمل
 * منعًا صريحًا: «النصوص بيد محرّرٍ وحده، لا مترجمٍ آليّ ولا نموذج لغة».
 * والمكتوب هنا مفاتيح تصنيفٍ تُحلّ إلى صفوفٍ قائمة، ومرادفاتُ بحثٍ تدخل
 * الجدول القائم بحالةٍ تنتظر مراجعة.
 *
 * ─── والحقل الممتلئ لا يُطمس ───
 *
 * التصنيف يُكتب في الحقل الفارغ وحده. منتجٌ صنّفه التاجر يبقى تصنيفه، ويُعاد
 * الحقل في نتيجة التطبيق «تُرك» لا «كُتب» — فيرى المشرف ما جرى فعلًا.
 */
final class SuggestionApplier
{
    /** حدُّ `product_seo.synonyms` كما يقبله ProductSeoController. */
    private const KEYWORDS_MAX = 1000;

    /** الاسم في الاقتراح ⇐ (الجدول، عمود المنتج). */
    private const CLASSIFICATION_MAP = [
        'stage' => ['stages', 'stage_id'],
        'grade' => ['grades', 'grade_id'],
        'subject' => ['subjects', 'subject_id'],
        'semester' => ['semesters', 'semester_id'],
    ];

    /**
     * @return array<string, string> الحقل ⇐ written|kept|unresolved
     */
    public function apply(AiSuggestion $suggestion): array
    {
        if ($suggestion->status !== 'APPROVED') {
            // حارسٌ أخير: الاعتماد شرطٌ للكتابة، لا ترتيبٌ مأمول.
            throw new RuntimeException('لا يُطبَّق اقتراحٌ غير معتمَد.');
        }

        return match ($suggestion->field) {
            'classification' => $this->applyClassification($suggestion),
            'keywords' => $this->applyKeywords($suggestion),
            'seo' => $this->applySeo($suggestion),
            default => throw new RuntimeException("لا مُطبِّق للحقل «{$suggestion->field}»."),
        };
    }

    /** @return array<string, string> */
    private function applyClassification(AiSuggestion $suggestion): array
    {
        $product = Product::query()->find($suggestion->entity_id);

        if ($product === null) {
            throw new RuntimeException('المنتج لم يعد موجودًا.');
        }

        $value = (array) $suggestion->value;
        $result = [];
        $updates = [];

        foreach (self::CLASSIFICATION_MAP as $key => [$table, $column]) {
            $name = $value[$key] ?? null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            /*
             * الاسم يُحلّ إلى مفتاح. والمفردات أمليت على النموذج من هذه
             * الجداول نفسها، فالتعذّر هنا يعني أنّ صفًّا حُذف بين الاقتراح
             * والاعتماد — يُقال ولا يُكتب `null`.
             */
            $id = DB::table($table)->where('name_ar', trim($name))->value('id');

            if ($id === null) {
                $result[$key] = 'unresolved';

                continue;
            }

            if ($product->{$column} !== null) {
                $result[$key] = 'kept';

                continue;
            }

            $updates[$column] = $id;
            $result[$key] = 'written';
        }

        if ($updates !== []) {
            $product->forceFill($updates)->save();
        }

        return $result;
    }

    /**
     * نصّ الظهور — في الفارغ وحده كالتصنيف.
     *
     * ─── لماذا لا يُستبدل نصٌّ قائم ───
     *
     * عنوان ظهورٍ كتبه إنسان قد يكون مصقولًا على مدى شهورٍ من قراءة نتائج
     * البحث. واستبدالُه باقتراح نموذجٍ بمليارَي وسيط خسارةٌ صافية حتّى لو كان
     * الاقتراح جيّدًا في ذاته — ولا سبيل إلى استرجاع القديم بعد الكتابة.
     *
     * والصفّ يُنشأ إن لم يكن: منتجٌ بلا صفّ ظهورٍ أصلًا لا نصَّ له يُطمس.
     *
     * @return array<string, string>
     */
    private function applySeo(AiSuggestion $suggestion): array
    {
        $product = Product::query()->find($suggestion->entity_id);

        if ($product === null) {
            throw new RuntimeException('المنتج لم يعد موجودًا.');
        }

        $value = (array) $suggestion->value;
        $seo = DB::table('product_seo')->where('product_id', $product->id)->first();
        $result = [];
        $updates = [];

        foreach (['seo_title', 'seo_desc'] as $column) {
            $text = $value[$column] ?? null;

            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $current = $seo === null ? null : ($seo->{$column} ?? null);

            if (is_string($current) && trim($current) !== '') {
                $result[$column] = 'kept';

                continue;
            }

            $updates[$column] = trim($text);
            $result[$column] = 'written';
        }

        if ($updates !== []) {
            DB::table('product_seo')->updateOrInsert(
                ['product_id' => $product->id],
                $updates + ['updated_at' => now(), 'created_at' => $seo === null ? now() : ($seo->created_at ?? now())],
            );
        }

        return $result;
    }

    /**
     * المرادفات تدخل الجدول القائم — لا جدولَ بحثٍ منافسًا.
     *
     * وبحالة `PENDING` لا `ACTIVE`: مرادفٌ خاطئ يغيّر نتائج البحث لكلّ زائر،
     * فمراجعتُه في شاشة المرادفات القائمة أرخص من اكتشافه من شكوى.
     *
     * @return array<string, string>
     */
    private function applyKeywords(AiSuggestion $suggestion): array
    {
        $product = Product::query()->find($suggestion->entity_id);

        if ($product === null) {
            throw new RuntimeException('المنتج لم يعد موجودًا.');
        }

        $value = (array) $suggestion->value;
        /*
         * `keywords` كان مُسقَطًا: يُخزَّن في الاقتراح ويُعرض للمشرف ثمّ لا
         * يُكتب عند الاعتماد، والنتيجة تقول «تُرك» — أي أنّ المشرف يعتمد
         * شيئًا ويقع شيءٌ أقلّ منه، ولا يُخبَر.
         */
        $terms = array_merge(
            (array) ($value['keywords'] ?? []),
            (array) ($value['synonyms'] ?? []),
            (array) ($value['english'] ?? []),
        );
        /*
         * كلماتُ المنتج نفسه (`product_seo.synonyms`) لا مرادفاتٌ عامّة.
         *
         * كانت تُكتب في `search_synonyms` معلّقةً ومصطلحُها عنوانُ المنتج كاملًا.
         * والتوسيع يطابق كلمةً كلمة، فلا يطابقها سؤالٌ أبدًا — ولو فعّلها المشرف
         * بعد اعتمادها: اعتمادان ولا أثر. أمّا هنا فالأثر على هذا المنتج وحده
         * (يفهرسه المحرّكان)، فلا يمسّ نتائج غيره — وهو ما كان «المعلّق» يحمي منه.
         */
        $existing = trim((string) DB::table('product_seo')->where('product_id', $product->id)->value('synonyms'));
        $known = $existing === '' ? [] : array_map(
            static fn (string $k): string => ArabicNormalizer::normalize($k),
            preg_split('/\s*[،,]\s*/u', $existing) ?: [],
        );

        $added = [];
        foreach (array_unique(array_filter($terms, 'is_string')) as $term) {
            $term = trim((string) $term);
            $key = ArabicNormalizer::normalize($term);
            if ($term === '' || mb_strlen($term) > 60 || in_array($key, $known, true)) {
                continue;
            }
            $candidate = implode('، ', array_filter([$existing, ...$added, $term]));
            /* حدُّ حقل المشرف نفسه (ProductSeoController: max 1000). */
            if (mb_strlen($candidate) > self::KEYWORDS_MAX) {
                break;
            }
            $added[] = $term;
            $known[] = $key;
        }

        $written = count($added);
        if ($written > 0) {
            DB::table('product_seo')->updateOrInsert(
                ['product_id' => $product->id],
                ['synonyms' => implode('، ', array_filter([$existing, ...$added])), 'updated_at' => now()]
                    + (DB::table('product_seo')->where('product_id', $product->id)->exists() ? [] : ['created_at' => now()]),
            );
        }

        return ['synonyms' => $written === 0 ? 'kept' : 'written', 'count' => (string) $written];
    }
}
