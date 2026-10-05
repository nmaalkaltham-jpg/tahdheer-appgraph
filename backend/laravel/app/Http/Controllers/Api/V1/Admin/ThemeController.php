<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Theme\FontCatalog;
use App\Domain\Theme\ThemeCss;
use App\Domain\Theme\ThemeDocument;
use App\Domain\Theme\ThemeRepository;
use App\Domain\Theme\TokenCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * تحرير الثيم من اللوحة.
 *
 * طبقتان لا واحدة:
 *   · Validator يحرس الشكل الخارجي (وجود `document` وكونه كائنًا). بدونه
 *     يتحوّل خطأ عميل إلى محو كامل للتصميم بردّ 200.
 *   · ThemeDocument::normalize تحرس المحتوى: شجرة بعمق ثلاثة (دور ⇒ خاصية ⇒
 *     جهاز) مع تجاوزات جزئية، وقواعد Laravel بالنقاط تصير هنا مئات السطور
 *     تكرّر ما تعرفه الوثيقة عن نفسها. تقبل الناقص وتردّ الفاسد إلى افتراضه،
 *     فلا يصل نصّ حرّ إلى CSS.
 *
 * الردّ يحمل الوثيقة و CSS المُترجَم معًا، فتعرض اللوحة معاينة حيّة من نفس
 * المُترجِم الذي يخدم المتجر — لا محاكاة ثانية في جافاسكربت تنحرف عنه.
 */
final class ThemeController
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        /*
         * التحقّق قبل التطبيع، لا بدلًا منه.
         *
         * ThemeDocument::normalize متسامحة عمدًا — تقبل الناقص وتردّ الفاسد
         * إلى افتراضه — وهذا صحيح لقراءة وثيقة قديمة، وخطر قاتل عند الكتابة:
         * عميل ينسى غلاف `document` يرسل null، فتُعيد normalize الافتراضات
         * كاملة، فيُمحى تصميم المتجر كلّه ويردّ الخادم 200 كأنه حفظ ناجح.
         * أثبتُّه بالتنفيذ: ثيم محفوظ بـ cairo/22 صار tajawal/16 بعد
         * طلب واحد خاطئ.
         *
         * الحذف عملية صريحة لها فعلها الخاص (DELETE)، ولا يجوز أن يكون
         * أثرًا جانبيًّا لطلب حفظ مشوّه.
         */
        $request->validate(
            [
                'document' => 'required|array',
                // اختياري (C27): طلبات استوديو التيبوغرافيا القائمة تبقى
                // صالحة بلا تغيير — من يرسله يحصل على كشف تعارض حقيقي.
                'baseEtag' => 'sometimes|nullable|string|max:16',
            ],
            [
                'document.required' => 'وثيقة الثيم مطلوبة. لإعادة الافتراضات استخدم DELETE لا حفظًا فارغًا.',
                'document.array' => 'وثيقة الثيم يجب أن تكون كائنًا.',
            ],
        );

        // تعارض كتابتين على وثيقة واحدة: من قرأ بصمة ثم تغيّرت تحته لا
        // يسحق عمل زميله بصمت — 409 بالحالة الجارية ليقرّر على بيّنة.
        $baseEtag = $request->input('baseEtag');
        if (is_string($baseEtag) && $baseEtag !== '') {
            $current = ThemeRepository::compiled();
            if ($current['etag'] !== $baseEtag) {
                return response()->json([
                    'message' => 'عدّل مشرف آخر الثيم بينما كنت تحرّره. راجع الحالة الجارية قبل التطبيق.',
                    'code' => 'THEME_CONFLICT',
                    'data' => $this->payload($current),
                ], 409);
            }
        }

        $compiled = ThemeRepository::save($request->input('document'), $this->actor());

        $this->audit->log(
            $this->actor(), 'theme.updated', 'theme', ThemeDocument::SETTING_KEY,
            null, ['etag' => $compiled['etag']], null, $request,
        );

        return response()->json(['data' => $this->payload($compiled)]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $compiled = ThemeRepository::reset();

        $this->audit->log(
            $this->actor(), 'theme.reset', 'theme', ThemeDocument::SETTING_KEY,
            null, ['etag' => $compiled['etag']], null, $request,
        );

        return response()->json(['data' => $this->payload($compiled)]);
    }

    /** آخر لقطات الثيم (C27) — تُغذّي «استرجاع» في لوحة الرموز. */
    public function versions(): JsonResponse
    {
        return response()->json(['data' => ThemeRepository::versions(30)]);
    }

    /**
     * استرجاع لقطة: وثيقتها تمرّ بـ ThemeRepository::save نفسه — التطبيع
     * يشفي المخطّطات القديمة، والاسترجاع نفسه يُلتقط لقطةً فيمكن التراجع عنه.
     */
    public function restore(Request $request): JsonResponse
    {
        $validated = $request->validate(['id' => 'required|integer|min:1']);

        $document = ThemeRepository::versionDocument((int) $validated['id']);
        if ($document === null) {
            return response()->json([
                'message' => 'اللقطة غير موجودة — ربما قُلِّمت.',
                'errors' => ['id' => ['لقطة غير موجودة.']],
            ], 422);
        }

        $compiled = ThemeRepository::save($document, $this->actor(), 'restore', 'استرجاع من #'.$validated['id']);

        $this->audit->log(
            $this->actor(), 'theme.restored', 'theme', ThemeDocument::SETTING_KEY,
            null, ['fromVersionId' => (int) $validated['id'], 'etag' => $compiled['etag']], null, $request,
        );

        return response()->json(['data' => $this->payload($compiled)]);
    }

    /**
     * يترجم مسوّدة بلا حفظها — مصدر المعاينة الحيّة في اللوحة.
     *
     * لماذا رحلة إلى الخادم بدل الترجمة في المتصفّح: مُترجِم ثانٍ مكتوب
     * بجافاسكربت ينحرف عن الأوّل بمرور الوقت، فتُظهر المعاينة شيئًا ويُصيَّر
     * المتجر شيئًا آخر — وهي أسوأ أنواع الكذب في أداة تصميم. هنا المعاينة
     * والإنتاج يخرجان من ThemeCss نفسه، فما تراه هو ما سيُنشر حرفيًّا.
     */
    public function preview(Request $request): JsonResponse
    {
        // نفس الحماية: معاينة لوثيقة فارغة تعرض الافتراضات وكأنها مسوّدة
        // المشرف، فيظنّ أن تعديلاته ضاعت.
        $request->validate(['document' => 'required|array'], [
            'document.required' => 'وثيقة الثيم مطلوبة للمعاينة.',
            'document.array' => 'وثيقة الثيم يجب أن تكون كائنًا.',
        ]);

        $document = ThemeDocument::normalize($request->input('document'));

        return response()->json([
            'data' => [
                'document' => $document,
                'css' => ThemeCss::compile($document),
                'fontsUrl' => FontCatalog::googleFontsUrl(ThemeDocument::fontUsage($document)),
            ],
        ]);
    }

    /**
     * @param  array{document: array<string, mixed>, css: string, fontsUrl: string|null, etag: string}|null  $compiled
     * @return array<string, mixed>
     */
    private function payload(?array $compiled = null): array
    {
        $compiled ??= ThemeRepository::compiled();

        return [
            'document' => $compiled['document'],
            'css' => $compiled['css'],
            'fontsUrl' => $compiled['fontsUrl'],
            'etag' => $compiled['etag'],
            // المخطّط يُرسَل مع البيانات فتبني اللوحة ضوابطها منه، ولا تُكتب
            // قائمة الأوزان أو الأدوار مرّة ثانية في الواجهة فتنحرف عن الخادم.
            // الافتراضات تُرسَل كاملة: «إعادة للموروث» تعيد القيمة التي يولّدها
            // الخادم بالضبط، لا تقديرًا لها في الواجهة.
            'defaults' => ThemeDocument::defaults(),
            'schema' => [
                'roles' => array_map(
                    static fn (string $role): array => [
                        'id' => $role,
                        'label' => ThemeDocument::ROLE_LABELS[$role],
                        'heading' => str_starts_with($role, 'h') && strlen($role) === 2,
                    ],
                    ThemeDocument::ROLES,
                ),
                'fonts' => FontCatalog::describe(),
                'devices' => ThemeDocument::DEVICES,
                'aligns' => ThemeDocument::ALIGNS,
                'transforms' => ThemeDocument::TRANSFORMS,
                'decorations' => ThemeDocument::DECORATIONS,
                'styles' => ThemeDocument::STYLES,
                // كتالوج الرموز المُغلق (C27): اللوحة تبني محرّراتها منه —
                // قائمة واحدة تعيش في الخادم لا نسخة ثانية في الواجهة.
                'tokens' => TokenCatalog::describe(),
            ],
        ];
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
