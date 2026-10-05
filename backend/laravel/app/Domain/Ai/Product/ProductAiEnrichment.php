<?php

declare(strict_types=1);

namespace App\Domain\Ai\Product;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiSuggestionStore;
use App\Domain\Ai\AiTask;
use App\Domain\Ai\Exceptions\AiOutputInvalid;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * إثراء منتجٍ واحد — تصنيفٌ وكلماتُ بحث، اقتراحًا لا كتابة.
 *
 * ─── لماذا لا يُكتب شيءٌ في المنتج ───
 *
 * «لا تستبدل ما كتبه التاجر تلقائيًّا» قاعدةٌ صريحة. وهذا الصنف يُنتج
 * مرشَّحاتٍ في `ai_suggestions` وحدها؛ والاعتماد فعلٌ منفصل بمشرفٍ مسمًّى.
 * فحتّى لو أخطأ النموذج، لا صفحة منتجٍ تتغيّر.
 *
 * ─── والمفردات من قاعدة البيانات ───
 *
 * المراحل والصفوف والمواد والفصول تُقرأ من جداولها وتُملى على النموذج وتُفرَض
 * عند التحقّق. فكلّ قيمةٍ تُقبل لها صفٌّ يقابلها — ولا اقتراحٌ يُعتمد ثمّ
 * يتعذّر تطبيقه لأنّ «الصف السابع» لا وجود له.
 *
 * ─── وبصمة المحتوى ───
 *
 * البصمة على النصّ المصدر وحده. فإعادة التشغيل على منتجٍ لم يتغيّر لا تستدلّ
 * أصلًا: المخزون يردّ، ولو عُطّل المخزون فالمستودع يرفض صفًّا مكرّرًا.
 */
final class ProductAiEnrichment
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiSuggestionStore $store,
    ) {}

    /**
     * @return array<string, string> الحقل ⇐ نتيجته: created|skipped|rejected
     */
    public function run(Product $product, ?int $actorId = null): array
    {
        $source = $this->sourceText($product);

        if (trim($source['title']) === '') {
            // منتجٌ بلا عنوان لا يُصنَّف: الاستدلال على الفراغ اختلاقٌ بالتعريف.
            return ['classification' => 'skipped', 'keywords' => 'skipped'];
        }

        $out = [
            'classification' => $this->classify($product, $source, $actorId),
            'keywords' => $this->keywords($product, $source, $actorId),
        ];

        /*
         * الظهور برايته هو: تصنيفٌ خاطئ يُصحَّح بضغطة، ونصُّ ظهورٍ رديء
         * يُفهرَس ويُقرأ باسم المتجر. فيُفصل ليُطفأ وحده.
         */
        if ((bool) config('ai.features.seo')) {
            $out['seo'] = $this->seo($product, $source, $actorId);
        }

        return $out;
    }

    /** @param array{title: string, description: string} $source */
    private function classify(Product $product, array $source, ?int $actorId): string
    {
        $request = new AiRequest(
            task: AiTask::Classify,
            promptId: 'product.classify',
            /*
             * العنوان والوصف محتوًى غير موثوق: كتبهما تاجر، وقد يحوي أحدهما
             * «تجاهل ما سبق». يدخلان الصندوق المعزول لا الموجّه.
             */
            untrusted: ['عنوان المنتج' => $source['title'], 'وصف المنتج' => $source['description']],
            variables: ['title' => $source['title'], 'description' => $source['description']],
            vocabularies: $this->vocabularies(),
            entityType: 'product',
            entityId: (int) $product->id,
            actorId: $actorId,
        );

        try {
            $response = $this->gateway->classify($request);
        } catch (AiOutputInvalid) {
            // مُسجَّلٌ في `ai_runs` بحالة REJECTED — ولا اقتراح يُكتب.
            return 'rejected';
        }

        $useful = $this->reconcile(array_filter(
            $response->data,
            static fn (string $key): bool => in_array($key, ['stage', 'grade', 'subject', 'semester', 'resource_type', 'topic'], true),
            ARRAY_FILTER_USE_KEY,
        ));

        return $this->store->put('product', (int) $product->id, 'classification', AiTask::Classify, $useful, $response, $request->inputHash()) === null
            ? 'skipped'
            : 'created';
    }

    /** @param array{title: string, description: string} $source */
    private function keywords(Product $product, array $source, ?int $actorId): string
    {
        $request = new AiRequest(
            task: AiTask::Extract,
            promptId: 'product.keywords',
            untrusted: ['عنوان المنتج' => $source['title'], 'وصف المنتج' => $source['description']],
            variables: ['title' => $source['title'], 'description' => $source['description']],
            entityType: 'product',
            entityId: (int) $product->id,
            actorId: $actorId,
        );

        try {
            $response = $this->gateway->extract($request);
        } catch (AiOutputInvalid) {
            return 'rejected';
        }

        $useful = array_filter(
            $response->data,
            static fn (string $key): bool => in_array($key, ['keywords', 'synonyms', 'english'], true),
            ARRAY_FILTER_USE_KEY,
        );

        return $this->store->put('product', (int) $product->id, 'keywords', AiTask::Extract, $useful, $response, $request->inputHash()) === null
            ? 'skipped'
            : 'created';
    }

    /**
     * مقترح عنوان الظهور ووصفه.
     *
     * ─── لماذا `suggest` لا `generate` ───
     *
     * المهمّة غير مُهيكَلة في التعداد، لكنّ موجّهها يحمل مخطَّط خرجٍ — فتمرّ
     * بالتحقّق مثل غيرها. والاسم يقول ما هي: اقتراحٌ يُعرض، لا نصٌّ يُنشر.
     *
     * ─── وما لا يُقترح ───
     *
     * لا `quick_answer` ولا `canonical_override`: الأوّل يظهر في نتائج البحث
     * بوصفه جواب المتجر، والثاني يغيّر أيّ صفحةٍ تُفهرَس أصلًا. كلاهما أثره
     * أوسع من أن يُقترح آليًّا في هذه المرحلة.
     *
     * @param  array{title: string, description: string}  $source
     */
    private function seo(Product $product, array $source, ?int $actorId): string
    {
        $request = new AiRequest(
            task: AiTask::Suggest,
            promptId: 'seo.suggest',
            untrusted: ['عنوان المنتج' => $source['title'], 'وصف المنتج' => $source['description']],
            variables: ['title' => $source['title'], 'description' => $source['description']],
            entityType: 'product',
            entityId: (int) $product->id,
            actorId: $actorId,
        );

        try {
            $response = $this->gateway->suggest($request);
        } catch (AiOutputInvalid) {
            return 'rejected';
        }

        /*
         * المهمّة نصّيّة، فالبوّابة لا تُهيكل خرجها. التحقّق هنا صريح: ما لم
         * يكن كائنًا بعنوانٍ ووصفٍ نصّيّين لا يُخزَّن — لا يُرقَّع ولا يُقصّ
         * من النصّ الحرّ.
         */
        $parsed = json_decode($response->text, true);

        if (! is_array($parsed)) {
            $start = strpos($response->text, '{');
            $end = strrpos($response->text, '}');
            $parsed = $start === false || $end === false
                ? null
                : json_decode(substr($response->text, $start, $end - $start + 1), true);
        }

        $title = is_array($parsed) ? ($parsed['title'] ?? null) : null;
        $desc = is_array($parsed) ? ($parsed['description'] ?? null) : null;

        $value = [];

        // الحدّان من قيود العمود وعرف نتائج البحث معًا — والقصّ لا الرفض.
        if (is_string($title) && trim($title) !== '') {
            $value['seo_title'] = mb_substr(trim($title), 0, 70);
        }
        if (is_string($desc) && trim($desc) !== '') {
            $value['seo_desc'] = mb_substr(trim($desc), 0, 180);
        }

        if ($value === []) {
            return 'rejected';
        }

        return $this->store->put('product', (int) $product->id, 'seo', AiTask::Suggest, $value, $response, $request->inputHash()) === null
            ? 'skipped'
            : 'created';
    }

    /**
     * تحقّقٌ تجاريّ بعد تحقّق المخطَّط — القاعدة تحسم التناقض.
     *
     * ─── التناقض الذي قيس ───
     *
     * سُئل النموذج عن «تحضير الرياضيات — الصف السادس الابتدائي» فردّ
     * `grade: "الصف السادس الابتدائي"` و`stage: "متوسط"`. كلّ قيمةٍ منهما
     * مشروعةٌ وحدها وتجتاز المخطَّط، والاثنتان معًا مستحيلتان.
     *
     * ─── ولماذا يُحسم بالصفّ لا بالمرحلة ───
     *
     * `grades.stage_id` مفتاحٌ خارجيّ: الصفّ يحدّد مرحلته حتمًا. فالمرحلة
     * تُشتقّ من الصفّ وتُطرح إجابة النموذج، لأنّ القاعدة تعرف والنموذج يخمّن.
     * وحين لا صفّ، تبقى مرحلته كما وردت — لا شيء يناقضها.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function reconcile(array $data): array
    {
        $grade = $data['grade'] ?? null;

        if (! is_string($grade) || trim($grade) === '') {
            return $data;
        }

        $stage = DB::table('grades')
            ->join('stages', 'stages.id', '=', 'grades.stage_id')
            ->where('grades.name_ar', trim($grade))
            ->value('stages.name_ar');

        if ($stage === null) {
            /*
             * صفٌّ لا يُحلّ رغم أنّه أُملي من الجدول: صفٌّ حُذف بين الإملاء
             * والقراءة. يُسقَط ولا يُخزَّن اقتراحٌ يتعذّر تطبيقه.
             */
            unset($data['grade']);

            return $data;
        }

        $data['stage'] = (string) $stage;

        return $data;
    }

    /**
     * النصّ المصدر — من النموذج القانونيّ إن وُجد، وإلّا من القديم.
     *
     * الترتيب يتبع `ProductSearchProjector`: المجموعة أوّلًا لأنّها المصدر
     * الذي يتحوّل إليه المشروع، والقديم يبقى مقروءًا فلا يفقد منتجٌ غير
     * مُرحَّل إثراءه.
     *
     * @return array{title: string, description: string}
     */
    private function sourceText(Product $product): array
    {
        $group = DB::table('product_groups')
            ->where('legacy_product_id', $product->id)
            ->first(['title', 'subtitle', 'short_desc']);

        if ($group !== null) {
            return [
                'title' => (string) $group->title,
                'description' => trim(((string) ($group->subtitle ?? '')).' '.((string) ($group->short_desc ?? ''))),
            ];
        }

        return [
            'title' => (string) $product->title,
            'description' => trim(((string) ($product->subtitle ?? '')).' '.((string) ($product->short_desc ?? ''))),
        ];
    }

    /**
     * المفردات المغلقة كما هي في الجداول الآن.
     *
     * @return array<string, list<string>>
     */
    private function vocabularies(): array
    {
        return [
            'stage' => DB::table('stages')->orderBy('position')->pluck('name_ar')->all(),
            'grade' => DB::table('grades')->orderBy('position')->pluck('name_ar')->all(),
            'subject' => DB::table('subjects')->orderBy('position')->pluck('name_ar')->all(),
            'semester' => DB::table('semesters')->orderBy('position')->pluck('name_ar')->all(),
        ];
    }
}
