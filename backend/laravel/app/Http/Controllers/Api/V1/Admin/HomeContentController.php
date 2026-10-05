<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Content\HomeContent;
use App\Domain\Content\HomeContentWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * تحرير محتوى الصفحة الرئيسة من اللوحة.
 *
 * ─── العطل الذي وُلد منه ───
 *
 * `HomeContent` كان **يُقرأ ولا يُكتب**: لا مسار إداريّ واحد يمسّ `home` في
 * `routes/api-admin.php`، ولا شاشة في اللوحة تحرّر الهيرو. فكان محتوى
 * الصفحة الرئيسة — عنوانها ونصّها وأزرارها وشرائح ثقتها — يُغيَّر بتحرير
 * البذّار وإعادة البذر، أي بنشرةٍ من مطوّر لا بقرارٍ من مالك.
 *
 * ─── وشكل التحقّق ───
 *
 * طبقتان كما في `ThemeController`: قاعدةٌ خارجيّة تحرس وجود `payload` وكونه
 * مصفوفة — بدونها يتحوّل خطأ عميلٍ إلى محوٍ صامت بردّ ٢٠٠ — ثمّ
 * `HomeContentWriter` يحرس المحتوى من سجلّ الكتل نفسه الذي يقرأ منه المتجر.
 *
 * ولا قواعد Laravel بالنقاط لكلّ حقل: تلك نسخةٌ ثانية من السجلّ تنحرف عند
 * أوّل حقلٍ يُضاف — يُقبل في الكتابة ولا يُقرأ، أو يُعرض في اللوحة ولا يُحفظ.
 *
 * ─── والردّ يحمل ما صار مقروءًا ───
 *
 * لا ما أُرسل. الكاتب يُسقط الفارغ وما ليس نصًّا قبل الحفظ، فالمحرّر يرى
 * فورًا ما سيراه الزائر — لا تأكيدَ حفظٍ لحقلٍ سقط بصمت.
 */
final class HomeContentController
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    /** كلّ الكتل القابلة للتحرير بقيمها الحالية — سطحُ التحرير كلّه في نداءٍ واحد. */
    public function index(): JsonResponse
    {
        $blocks = [];
        foreach (array_keys(HomeContent::blocks()) as $key) {
            $blocks[$key] = HomeContent::payload($key);
        }

        return response()->json([
            'data' => [
                'schema' => HomeContent::blocks(),
                'blocks' => $blocks,
                /* مفردات الأقسام — تبنّي منها اللوحةُ مُبدِّلَ الترتيب بلا نسخةٍ محلّيّة. */
                'sections' => HomeContent::SECTIONS,
            ],
        ]);
    }

    public function update(Request $request, string $block): JsonResponse
    {
        $key = 'home.'.$block;
        if (! array_key_exists($key, HomeContent::blocks())) {
            /*
             * ٤٠٤ لا ٤٢٢: المفتاح جزءٌ من المسار لا حقلٌ في الجسم، ومفتاحٌ
             * غير معروف موردٌ غير موجود. ودلالة المصدر تُحفظ حرفيًّا.
             */
            return response()->json(['message' => 'كتلةُ محتوًى غير معروفة'], 404);
        }

        /*
         * `present` لا `required`.
         *
         * `required` يرفض المصفوفة الفارغة في Laravel — فإفراغُ ترتيب الأقسام
         * (وهو قرارٌ مشروع: «أخلِ الصفحة») كان مستحيلًا عبر الواجهة، ويردّ
         * ٤٢٢ بلا سبب مفهوم. و`present` يحفظ الغرض الأصليّ — أن يكون المفتاح
         * موجودًا فلا يمحو طلبٌ مشوّه كتلةً بالخطأ — ويقبل الفراغ قيمةً.
         */
        $request->validate([
            'payload' => 'present|array',
        ]);

        $before = HomeContent::payload($key);
        /* القاعدة أعلاه تضمن أنّها مصفوفة؛ والتأكيد هنا لمُحلّل الأنواع. */
        $payload = $request->input('payload');

        try {
            $after = HomeContentWriter::save($key, is_array($payload) ? $payload : []);
        } catch (InvalidArgumentException $refusal) {
            /*
             * رفضُ الكاتب خطأُ عميلٍ لا عطلُ خادم — ٤٢٢ لا ٥٠٠.
             *
             * وهو يقع في حالةٍ واحدة اليوم: هيروان مُفعَّلان معًا. ورسالته
             * تُعاد حرفيًّا لأنّها تشرح السبب البنيويّ، والواجهة تعرض نصّ
             * الخادم كما هو فلا تُطوى في «تعذّر الحفظ».
             */
            return response()->json([
                'message' => $refusal->getMessage(),
                'errors' => ['payload' => [$refusal->getMessage()]],
            ], 422);
        }

        /*
         * يُسجَّل حتّى حين لا تتغيّر البايتات: سجلّ التدقيق يجيب «من لمس
         * محتوى المتجر ومتى»، وحفظٌ بلا أثرٍ سؤالٌ مشروع أيضًا.
         */
        $this->audit->log(
            actor: $this->actor(),
            action: 'home_content.update',
            entityType: 'home_content',
            entityId: $key,
            before: $before,
            after: $after,
            request: $request,
        );

        return response()->json(['data' => ['key' => $key, 'value' => $after]]);
    }

    /**
     * الفاعل من الحاوية لا من `$request->user()`.
     *
     * `AdminAuthorize` يربط `AdminUser` في الحاوية، وحارسُ الجلسة الإداريّة
     * ليس حارس `auth()` الافتراضيّ — فـ`$request->user()` يُعيد null هنا
     * ويُسجَّل الأثر **بلا فاعل**. سجلّ تدقيقٍ لا يقول من فعل أسوأ من غيابه:
     * يبدو كاملًا وهو أجوف. (أثبته الاختبار قبل هذا السطر.)
     */
    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
