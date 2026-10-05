<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\I18n\LanguageEligibility;
use App\Domain\I18n\Locale;
use App\Domain\I18n\ProductTranslation;
use App\Domain\Outbox\OutboxWriter;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ترجمات المنتج وأهليّته اللغوية.
 *
 * الحفظ في جدول، لا في حالة صفحة: تبويب اللغة كان يفقد ما كُتب فيه مع كل
 * إعادة تحميل، وهو أسوأ من عدم وجوده — يوهم المحرّر أنه أنجز عملًا.
 *
 * النشر خطوة مستقلّة عن الحفظ، ويرفض حين لا تتحقّق المتطلّبات. الرفض يعدّد
 * الناقص بدل أن يقول «تعذّر».
 */
final class ProductTranslationController
{
    public function __construct(
        private readonly LanguageEligibility $eligibility,
        private readonly AdminAuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** حالة كل اللغات لهذا المنتج. */
    public function eligibility(int $productId): JsonResponse
    {
        $product = Product::query()->with('translations')->findOrFail($productId);

        return response()->json(['data' => $this->eligibility->forProduct($product)]);
    }

    public function index(int $productId): JsonResponse
    {
        $product = Product::query()->with('translations')->findOrFail($productId);

        return response()->json([
            'data' => Locale::ordered()->map(function (Locale $locale) use ($product): array {
                $t = $locale->is_default ? null : $product->translations->firstWhere('locale_code', $locale->code);

                return [
                    'locale' => $locale->code,
                    'isDefault' => $locale->is_default,
                    // الافتراضية تعرض أعمدة المنتج نفسها: مصدر واحد للنصّ العربي.
                    'content' => $locale->is_default
                        ? $this->sourceContent($product)
                        : $this->translationContent($t),
                    'status' => $locale->is_default ? $product->status : ($t->status ?? 'draft'),
                    'isIndexable' => $locale->is_default ? true : (bool) ($t->is_indexable ?? true),
                    'editable' => ! $locale->is_default,
                ];
            })->all(),
        ]);
    }

    public function update(Request $request, int $productId, string $localeCode): JsonResponse
    {
        $product = Product::query()->with('translations')->findOrFail($productId);
        $locale = Locale::query()->findOrFail($localeCode);

        if ($locale->is_default) {
            /*
             * المحتوى العربي يُحرَّر من محرّر المنتج لا من تبويب الترجمة.
             * فتح بابين لنفس النصّ يعني أن آخر من كتب يمحو الآخر صامتًا.
             */
            return response()->json([
                'message' => 'اللغة الافتراضية تُحرَّر من بيانات المنتج نفسها، لا من الترجمات.',
                'code' => 'DEFAULT_LOCALE_NOT_TRANSLATABLE',
            ], 422);
        }

        $translationId = $product->translations->firstWhere('locale_code', $locale->code)?->id;

        $validated = $request->validate([
            'title' => 'sometimes|nullable|string|max:255',
            'subtitle' => 'sometimes|nullable|string|max:255',
            'shortDesc' => 'sometimes|nullable|string|max:1000',
            'description' => 'sometimes|nullable|string|max:50000',
            'slug' => [
                'sometimes', 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('product_translations', 'slug')
                    ->where('locale_code', $locale->code)
                    ->ignore($translationId),
            ],
            'isIndexable' => 'sometimes|boolean',
        ], [
            'slug.regex' => 'الرابط يقبل حروفًا لاتينية صغيرة وأرقامًا وشرطات فقط.',
            'slug.unique' => 'هذا الرابط مستخدم في هذه اللغة لمنتج آخر.',
        ]);

        $translation = ProductTranslation::query()->firstOrNew([
            'product_id' => $product->id,
            'locale_code' => $locale->code,
        ]);
        $before = $translation->exists
            ? $translation->only(['title', 'subtitle', 'short_desc', 'description', 'slug', 'is_indexable'])
            : null;

        foreach ([
            'title' => 'title', 'subtitle' => 'subtitle', 'shortDesc' => 'short_desc',
            'description' => 'description', 'slug' => 'slug',
        ] as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $value = $validated[$input];
                $translation->{$column} = is_string($value) && trim($value) === '' ? null : $value;
            }
        }
        if (array_key_exists('isIndexable', $validated)) {
            $translation->is_indexable = (bool) $validated['isIndexable'];
        }

        $translation->product_id = $product->id;
        $translation->locale_code = $locale->code;
        $translation->save();

        $this->audit->log(
            $this->actor(), 'product.translation.updated', 'product', $product->id,
            $before, $translation->only(['title', 'slug', 'is_indexable']) + ['locale' => $locale->code],
            null, $request,
        );

        return response()->json([
            'data' => [
                'content' => $this->translationContent($translation),
                'eligibility' => $this->eligibility->forLocale($product->refresh()->load('translations'), $locale),
            ],
        ]);
    }

    /** نشر الترجمة — يرفض ما دام متطلّب حاجب واحد لم يتحقّق. */
    public function publish(Request $request, int $productId, string $localeCode): JsonResponse
    {
        $product = Product::query()->with('translations')->findOrFail($productId);
        $locale = Locale::query()->findOrFail($localeCode);

        if ($locale->is_default) {
            return response()->json([
                'message' => 'نشر اللغة الافتراضية يتمّ بنشر المنتج نفسه.',
                'code' => 'DEFAULT_LOCALE_NOT_TRANSLATABLE',
            ], 422);
        }

        $state = $this->eligibility->forLocale($product, $locale);

        if (! $state['canPublish']) {
            $unmet = array_values(array_filter(
                $state['requirements'],
                static fn (array $r): bool => ! $r['satisfied'] && $r['blocking'],
            ));

            return response()->json([
                'message' => 'لا يمكن نشر هذه اللغة قبل استيفاء: '
                    .implode('، ', array_column($unmet, 'label')).'.',
                'code' => 'NOT_ELIGIBLE',
                // السبب يُعاد كاملًا: الواجهة تعرض القائمة لا رسالة عامّة.
                'requirements' => $unmet,
            ], 422);
        }

        $translation = $product->translations->firstWhere('locale_code', $locale->code);

        DB::transaction(function () use ($translation, $product, $locale): void {
            $translation->forceFill([
                'status' => 'published',
                'published_at' => $translation->published_at ?? now(),
            ])->save();

            /*
             * لا مستهلك لهذا الحدث — والتعليق السابق هنا كان يعد بخلاف ذلك.
             *
             * كان مكتوبًا «الفهرس والخرائط تُبنى من الأحداث القائمة، لا من
             * مسار ثانٍ»، وهو غير صحيح: لا حدثَ قائمًا يُكتب عند نشر ترجمة،
             * و`SearchOutboxSubscriber::EVENTS` يستهلك ثلاثةً فقط
             * (`product.published` · `product.updated` · `product.withdrawn`)
             * وليس `product.translation.published` منها.
             *
             * فالصفّ يُكتب ويُعلَّم «معالَجًا» ولا يقرؤه أحد — يُسجَّل في
             * `docs/audits/events-consumers-2026-09-20.md` ضمن الفجوات الحقيقيّة.
             * أثرُه: الفهرس لا يعلم باللغة الجديدة حتّى أوّل تحديثٍ للمنتج
             * يكتب `product.updated`.
             *
             * ويبقى الصفّ مكتوبًا عمدًا: توصيلُ مستهلكٍ قرارُ مالك النطاق،
             * وحذفُ الحدث يُضيّع الأثر الذي سيُبنى عليه المستهلك.
             */
            $this->outbox->write(
                aggregateType: 'product', aggregateId: (string) $product->id,
                eventType: 'product.translation.published',
                payload: [
                    'product_id' => $product->id,
                    'locale' => $locale->code,
                    'slug' => $translation->slug,
                ],
                idempotencyKey: "product:{$product->id}:translation:{$locale->code}",
            );
        });

        $this->audit->log(
            $this->actor(), 'product.translation.published', 'product', $product->id,
            null, ['locale' => $locale->code], null, $request,
        );

        return response()->json([
            'data' => $this->eligibility->forLocale($product->refresh()->load('translations'), $locale),
        ]);
    }

    /** @return array<string, mixed> */
    private function sourceContent(Product $product): array
    {
        return [
            'title' => $product->title,
            'subtitle' => $product->subtitle,
            'shortDesc' => $product->short_desc,
            'description' => $product->description,
            'slug' => $product->slug,
        ];
    }

    /** @return array<string, mixed> */
    private function translationContent(?ProductTranslation $t): array
    {
        return [
            'title' => $t->title ?? null,
            'subtitle' => $t->subtitle ?? null,
            'shortDesc' => $t->short_desc ?? null,
            'description' => $t->description ?? null,
            'slug' => $t->slug ?? null,
        ];
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
