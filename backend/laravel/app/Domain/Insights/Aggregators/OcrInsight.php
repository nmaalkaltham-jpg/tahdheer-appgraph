<?php

namespace App\Domain\Insights\Aggregators;

use App\Domain\Insights\InsightAggregator;
use App\Domain\Insights\InsightWindow;
use App\Domain\Insights\Metric;
use Illuminate\Support\Facades\DB;

/**
 * استخراج محتوى الأصول — يُقاس من الجدول المُهاجَر.
 *
 * ─── الفخّ الذي تجنّبه هذا الملفّ ───
 *
 * في قاعدة التطوير جدولٌ اسمه `asset_extractions` فيه ستّةٌ وعشرون صفًّا،
 * أربعةٌ وعشرون منها `ocr_unavailable` واثنان `ok`. ويُغري ذلك ببناء «تغطية
 * الاستخراج ٧٫٧٪» وعرضها إنذارًا.
 *
 * ولا هجرةَ تُنشئ هذا الجدول. هو راسبُ شيفرةٍ حُذفت، مسجَّلٌ في
 * `scripts/known-schema-residue.json`، ولا سطرَ في التطبيق كلّه يقرؤه أو
 * يكتبه. فالنسبة كانت ستُحسب من صفوفٍ لا تُنتَج، وتختفي اللوحة كلّها في أوّل
 * نشرٍ نظيف لأنّ الجدول لن يوجد.
 *
 * ─── فما يُعرض ───
 *
 * ما يُقاس حقًّا: عدد الأصول المرفوعة، وهي في جدولٍ مُهاجَر. وما عداه يُعلَن
 * غير مهيّأ باسمه. وقدرة الاستخراج نفسها معلنةٌ مؤجَّلةً في
 * `docs/capabilities.json` تحت `analytics.intelligence`، وشاشتها مغلقةٌ
 * ببوّابةٍ لا ترسل طلبًا — فهذا النطاق يوافق ما أعلنه المشروع عن نفسه.
 */
final readonly class OcrInsight implements InsightAggregator
{
    /*
     * ما لا يُنتجه خطُّ الاستخراج بعد — سببٌ لكلّ مقياسٍ على حدة.
     *
     * كان هنا ثابتٌ واحد يقول «لا خطّ استخراجٍ في المشروع: لا جدول مُهاجَر
     * ولا خدمة ولا مهمّة ولا أمر»، ويُقدَّم لسبعة مقاييس. وقد صار كلُّ جزءٍ
     * منه كاذبًا: الجدول مُهاجَرٌ الآن، والخدمة والمهمّة والأمر قائمة،
     * و`content:extract` يُنتج صفوفًا فعليّة. ورسالةٌ تنفي وجود ما تراه
     * الشاشةُ بجوارها أسوأ من غياب المقياس.
     */
    private const NO_KEYWORDS = 'لا استخراج كلماتٍ مفتاحيّة في الخطّ بعد: المستخرِجات '
        .'تُخرج نصًّا خامًا ووحداتٍ، ولا مرحلةَ تُرشّح منه مصطلحات. حين تُبنى '
        .'تُقاس من نفس الجدول.';

    private const NO_QUEUE_METRIC = 'عمق الطابور يُقرأ من وسيط الطابور لا من جدول الاستخراج، '
        .'ولا قارئَ له في هذا النطاق بعد.';

    public function metrics(InsightWindow $window): array
    {
        /*
         * تُقرأ من الجدول المُهاجَر لا من ذاكرةٍ: الاستخراج يكتب صفًّا لكلّ
         * محاولة — ناجحةً كانت أو فاشلة — فالتغطية والإخفاق كلاهما مقيس.
         */
        $assets = DB::table('assets')->count();

        /*
         * الحالة هي **آخر** محاولة لكلّ أصل، لا «أيّ محاولةٍ نجحت يومًا».
         *
         * الجدول يحفظ صفًّا لكلّ (بصمة، مستخرِج، إصدار) — وهو ما يجعل إعادة
         * المعالجة المضبوطة ممكنة (البند ٤٣). وأثرُه الجانبيّ أنّ نجاحًا
         * قديمًا يبقى إلى الأبد بجوار إخفاقٍ جديد للأصل نفسه.
         *
         * وقد وقع فعلًا: الأصلان ١ و٢ يحملان `plain_text 1.0.0 ok` من شيفرةٍ
         * حُذفت، و`plain_text 2026.09.23 failed` من الخطّ العامل. فعدٌّ يسأل
         * «هل نجح مرّة؟» يُعلن تغطيةً ٢٦ من ٢٧ بينما المستخرِج الحاليّ يعجز
         * عن قراءة أصلين — رقمٌ يطمئن وهو كاذب.
         *
         * فتُقرأ آخر محاولةٍ زمنيًّا لكلّ أصل. ولا تُحذف الصفوف القديمة: هي
         * تاريخُ المعالجة، وحذفُها يُفقد القدرة على معرفة متى انكسر شيء.
         * والحلّ في السؤال لا في إتلاف الدليل.
         */
        $latest = DB::table('asset_extractions as e')
            ->select('e.asset_id', 'e.status', 'e.duration_ms')
            ->whereRaw('e.id = (select max(i.id) from asset_extractions i where i.asset_id = e.asset_id)');

        $rows = $latest->get();
        $attempted = $rows->count();
        $extracted = $rows->where('status', 'ok')->count();
        $failed = $rows->where('status', 'failed')->count();
        $durations = $rows->where('status', 'ok')->pluck('duration_ms')->filter(fn ($d) => $d !== null);
        $latency = $durations->isEmpty() ? null : (int) round($durations->avg());

        return [
            Metric::measured(
                'assets',
                'أصول مرفوعة',
                'count',
                DB::table('assets')->count(),
                'كلّ صفٍّ في جدول الأصول، بلا قيد مدًى. هذا وحده ما يُقاس في هذا النطاق.',
                href: '/media',
            ),
            Metric::measured(
                'scanned',
                'أصول استُخرج محتواها',
                'count',
                $extracted,
                'أصولٌ **آخرُ** محاولةٍ لها حالتها ok — لا «نجحت مرّةً يومًا»، فنجاحُ إصدارٍ قديم لا يحجب إخفاق الحاليّ.',
            ),
            Metric::ratio(
                'coverage',
                'تغطية الاستخراج',
                $extracted,
                $assets,
                'أصولٌ نجح استخراجها ÷ كلّ الأصول.',
                'لا أصولَ مرفوعة، فالتغطية بلا مقام.',
            ),
            Metric::measured(
                'pending',
                'بانتظار الاستخراج',
                'count',
                max(0, $assets - $attempted),
                'أصولٌ لا صفَّ استخراجٍ لها بعد — الفرق بين كلّ الأصول وما جُرّب منها.',
            ),
            Metric::measured(
                'failed',
                'استخراجات فاشلة',
                'count',
                $failed,
                'أصولٌ آخرُ محاولةٍ لها حالتها failed. لكلٍّ `error_code` يقول سبب الإخفاق بلا تخمين.',
                alert: $failed > 0,
            ),
            Metric::notConfigured('keywords', 'كلمات مفتاحيّة مستخرَجة', self::NO_KEYWORDS),
            Metric::notConfigured('queueDepth', 'عمق طابور الاستخراج', self::NO_QUEUE_METRIC),
            $latency === null
                ? Metric::noData('latency', 'زمن الاستخراج', 'لا صفَّ استخراجٍ ناجحٍ يحمل مدّة.')
                : Metric::measured(
                    'latency',
                    'زمن الاستخراج',
                    'ms',
                    $latency,
                    'متوسّط `duration_ms` لآخر محاولةٍ ناجحة لكلّ أصل.',
                ),
        ];
    }

    public function lists(InsightWindow $window): array
    {
        return [];
    }
}
