<?php

declare(strict_types=1);

namespace App\Domain\Ai\Assistant;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiPolicy;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiTask;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Search\QueryPipeline;
use Illuminate\Support\Facades\DB;

/**
 * مساعد المتجر — يشرح حقائق الكتالوج ولا يصنعها.
 *
 * ─── الضمان البنيويّ ───
 *
 * المنتجات المُعادة تُبنى من نتائج البحث الحقيقيّة، **لا تُقرأ من نصّ
 * النموذج**. فمعرّفُ منتجٍ مخترع مستحيلٌ بالبنية لا ممنوعٌ بالتعليمات: النموذج
 * لا يملك قناةً يُخرج بها معرّفًا أصلًا. وهذا هو الفرق بين «طلبنا منه ألّا
 * يختلق» و«لا يستطيع».
 *
 * ─── والسعر لا يخرج من النموذج ───
 *
 * الأسعار تُقرأ من قاعدة البيانات وتُعاد في حقلها المستقلّ. والواجهة تعرضها
 * من ذلك الحقل لا من النصّ. فحتّى لو ذكر النموذج رقمًا في جملته، لا يصل
 * الزائر سعرًا غير سعر القاعدة.
 *
 * ─── وما يرفضه صراحةً ───
 *
 * حالة الطلب والاسترداد والتنزيل. لا لأنّ النموذج قد يخطئ فيها فحسب، بل
 * لأنّها بياناتٌ خاصّة ولا طبقة مصادقةِ عميلٍ في هذا المشروع: لا وسيطَ
 * مصادقةٍ للعملاء، والموجود يخصّ المشرفين وحدهم. فالسؤال يُحوَّل إلى الدعم،
 * ولا يُبنى سطحٌ خاصّ على مصادقةٍ غير موجودة.
 */
final class StoreAssistant
{
    /** كم منتجًا يدخل السياق — سبعة تكفي للجواب وتبقى داخل النافذة. */
    private const CONTEXT_SIZE = 7;

    /**
     * كلماتٌ تدلّ على سؤالٍ خاصّ أو معامليّ.
     *
     * الترشيح بالكلمة فظّ، لكنّه يقع **قبل** النموذج فلا يتكلّف استدلالًا،
     * ويُخطئ في الجانب الآمن: سؤالٌ عامّ يُحوَّل إلى الدعم أهونُ من جوابٍ عن
     * طلبٍ لم يُتحقَّق من صاحبه.
     */
    private const PRIVATE_HINTS = [
        'طلبي', 'طلبيتي', 'رقم الطلب', 'استرداد', 'استرجاع', 'فلوسي', 'مبلغي',
        'حسابي', 'فاتورتي', 'تنزيلي', 'رابط التحميل', 'لم يصلني', 'ما وصلني',
    ];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiPolicy $policy,
        private readonly QueryPipeline $search,
    ) {}

    /**
     * @return array{
     *   answer: string,
     *   products: list<array<string, mixed>>,
     *   grounded: bool,
     *   refusal: ?string,
     * }
     *
     * @throws AiUnavailable
     */
    public function ask(string $question): array
    {
        $this->policy->assertFeature('customer_assistant');

        $question = trim(mb_substr($question, 0, 500));

        if ($question === '') {
            return $this->refuse('اكتب سؤالك أوّلًا.');
        }

        foreach (self::PRIVATE_HINTS as $hint) {
            if (mb_stripos($question, $hint) !== false) {
                return $this->refuse(
                    'أسئلة الطلبات والاستردادات والتنزيلات تحتاج التحقّق من هويّتك، وهذا المساعد لا يطّلع '
                    .'على حسابك. تواصل مع الدعم ومعك رقم طلبك.',
                );
            }
        }

        /*
         * الاسترجاع بالبحث القائم لا بفهرسٍ ثانٍ: المنتجات التي يراها الزائر
         * في البحث هي نفسها التي يراها المساعد، فلا يذكر منتجًا لا يجده.
         */
        $found = $this->search->run($question);
        $products = $this->contextProducts($found['result']->items);

        if ($products === []) {
            /*
             * لا سياق يعني لا جواب. استدلالٌ بلا حقائق هو بالضبط ما يُنتج
             * منتجاتٍ مخترعة، فيُوفَّر الاستدلال ويُقال الصدق.
             */
            return $this->refuse('لم أجد في المتجر ما يطابق سؤالك. جرّب كلماتٍ أخرى أو تصفّح الأقسام.');
        }

        $response = $this->gateway->answerWithContext(new AiRequest(
            task: AiTask::AnswerWithContext,
            promptId: 'assistant.answer',
            /*
             * ─── الموثوق أرقامٌ بناها الخادم، لا نصٌّ كتبه تاجر ───
             *
             * كُشف في مراجعةٍ خصميّة: كان `context` يحمل عناوين المنتجات
             * كاملةً، وهي تُدمج في رسالة النظام تحت «حقائق موثوقة… هذه وحدها
             * ما تبني عليه جوابك». فكان كلّ من يملك تحرير منتجٍ يكتب تعليماتٍ
             * تصل النموذج بأعلى درجات الثقة — وهو انقلابٌ تامّ لطبقات الثقة.
             *
             * فصار الموثوق ما يحسبه الخادم وحده: المعرّف والسعر والتصنيف من
             * الجداول. والعناوين — وهي نصٌّ بشريّ — تنزل إلى الصندوق المعزول
             * مع سؤال الزائر، فتُقرأ بيانًا لا أمرًا.
             */
            context: ['أرقام المنتجات المتاحة' => array_map(
                static fn (array $p): array => [
                    'id' => $p['id'],
                    'priceMinor' => $p['priceMinor'],
                    'stage' => $p['stage'],
                    'grade' => $p['grade'],
                    'subject' => $p['subject'],
                ],
                $products,
            )],
            untrusted: [
                'سؤال الزائر' => $question,
                'عناوين المنتجات (نصّ بشريّ)' => implode("\n", array_map(
                    static fn (array $p): string => '#'.$p['id'].' — '.$p['title'],
                    $products,
                )),
            ],
            variables: ['question' => $question],
            entityType: 'assistant',
            actorId: null,
            timeout: (float) config('ai.engine.interactive_timeout'),
        ));

        return [
            'answer' => trim($response->text),
            /*
             * تُعاد من الاسترجاع لا من النصّ — الضمان البنيويّ ضدّ معرّفٍ
             * مخترع.
             */
            'products' => $products,
            'grounded' => true,
            'refusal' => null,
        ];
    }

    /**
     * حقائق المنتجات من قاعدة البيانات — لا من النموذج ولا من الفهرس.
     *
     * الفهرس قد يتقادم بين إعادة بناءٍ وأخرى، والسعر أسوأ ما يتقادم. يُقرأ من
     * الجدول لحظة السؤال.
     *
     * @param  iterable<mixed>  $hits
     * @return list<array<string, mixed>>
     */
    private function contextProducts(iterable $hits): array
    {
        $ids = [];

        foreach ($hits as $hit) {
            $id = is_array($hit) ? ($hit['id'] ?? null) : ($hit->id ?? null);

            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }

            if (count($ids) >= self::CONTEXT_SIZE) {
                break;
            }
        }

        if ($ids === []) {
            return [];
        }

        return DB::table('products')
            ->leftJoin('product_prices', 'product_prices.product_id', '=', 'products.id')
            ->leftJoin('stages', 'stages.id', '=', 'products.stage_id')
            ->leftJoin('grades', 'grades.id', '=', 'products.grade_id')
            ->leftJoin('subjects', 'subjects.id', '=', 'products.subject_id')
            ->whereIn('products.id', $ids)
            // المنشور وحده: مسوّدةٌ يذكرها المساعد تعني منتجًا لا يستطيع شراءه.
            ->where('products.status', 'published')
            ->select([
                'products.id',
                'products.title',
                'products.slug',
                'product_prices.regular_minor',
                'product_prices.sale_minor',
                'stages.name_ar as stage',
                'grades.name_ar as grade',
                'subjects.name_ar as subject',
            ])
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'title' => (string) $row->title,
                'slug' => (string) $row->slug,
                'stage' => $row->stage,
                'grade' => $row->grade,
                'subject' => $row->subject,
                /*
                 * السعر بالهللة و`null` حين لا سعر — لا صفرٌ يُقرأ «مجّانيّ».
                 * والواجهة تعرضه من هنا لا من نصّ النموذج.
                 */
                'priceMinor' => $row->sale_minor ?? $row->regular_minor,
            ])
            ->values()
            ->all();
    }

    /** @return array{answer: string, products: list<array<string, mixed>>, grounded: bool, refusal: string} */
    private function refuse(string $why): array
    {
        return ['answer' => $why, 'products' => [], 'grounded' => false, 'refusal' => $why];
    }
}
