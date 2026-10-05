<?php

declare(strict_types=1);

namespace App\Domain\Ai\Search;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiPolicy;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * فهم استعلامٍ عجز عنه البحث الحتميّ — مرشِّحاتٌ لا نتائج.
 *
 * ─── متى يعمل، ولماذا هنا بالذات ───
 *
 * بعد أن يُجري المحرّك الحتميّ بحثه، ويخفّف مرشِّحاته، ويعود بصفر. أي في
 * واحدٍ من كلّ تسعين بحثًا تقريبًا في هذه القاعدة (‏١١ من ١٠١٠). ولا يُوضع في
 * مسار كلّ بحث: «لا نموذج لغةٍ متزامنًا في كلّ طلب بحثٍ ما لم يُقس ويُثبت
 * أمانه» قاعدةٌ صريحة، وثانيةٌ تُضاف إلى كلّ بحثٍ ناجح خسارةٌ محضة.
 *
 * ─── وما يردّه ليس نتائج ───
 *
 * يردّ **مرشِّحاتٍ مُهيكَلة** تُمرَّر إلى المحرّك القائم ليبحث بها. فالنتائج
 * تخرج من Meilisearch كما تخرج دائمًا، ولا منتجَ يُختلق ولا معرّفَ يُخترع:
 * النموذج لا يرى الكتالوج أصلًا، وأقصى ما يفعله أن يقترح «مادّة: علوم».
 *
 * ─── والمفردات من الجداول ───
 *
 * كما في إثراء المنتجات: القوائم تُملى وتُفرَض من الجدول نفسه، فكلّ مرشِّحٍ
 * يعود له صفٌّ يقابله. ومرشِّحٌ لا يقابله صفّ لا يُمرَّر — بحثٌ بمرشِّحٍ
 * مخترعٍ يعود بصفرٍ ثانٍ ويبدو عطلًا.
 *
 * ─── والفشل صامتٌ للزائر ───
 *
 * كلّ عطلٍ هنا يُبتلع ويُسجَّل ويعود `null`. الزائر يرى صفحة «لا نتائج»
 * المعتادة بمقترحاتها، ولا يرى خطأً ولا ينتظر. الذكاء تحسينٌ لا تبعيّة.
 */
final class AiQueryUnderstanding
{
    /** المرشِّحات التي يفهمها `SearchRequest` — وما عداها يُسقَط. */
    private const PASSABLE = ['subject', 'grade', 'stage', 'semester'];

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AiPolicy $policy,
    ) {}

    /**
     * @return array{filters: array<string, string>, corrected: ?string}|null
     */
    public function understand(string $rawQuery): ?array
    {
        if (trim($rawQuery) === '' || ! $this->policy->isFeatureOn('search_assist')) {
            return null;
        }

        try {
            $response = $this->gateway->extract(new AiRequest(
                task: AiTask::Extract,
                promptId: 'search.understand',
                /*
                 * استعلام الزائر محتوًى غير موثوق بامتياز: هو المدخل الوحيد
                 * الذي يكتبه غريبٌ مباشرةً. يدخل الصندوق المعزول.
                 */
                untrusted: ['استعلام الزائر' => $rawQuery],
                variables: ['query' => $rawQuery],
                vocabularies: $this->vocabularies(),
                entityType: 'search',
                // بلا فاعل: حصّةٌ واحدة مشتركة لكلّ حركة الزوّار، وهو المقصود.
                actorId: null,
                timeout: (float) config('ai.engine.interactive_timeout'),
            ));
        } catch (Throwable $e) {
            /*
             * يشمل `AiUnavailable` و`AiOutputInvalid` معًا: كلاهما يعني «لا
             * فهم»، والزائر ينتظر. لا رميةَ تصعد إلى صفحة البحث.
             */
            Log::info('ai.search.understand_skipped', ['reason' => $e->getMessage()]);

            return null;
        }

        $filters = $this->toSlugs($response->data);

        $corrected = $response->data['corrected_query'] ?? null;

        if ($filters === [] && (! is_string($corrected) || trim($corrected) === '')) {
            return null;
        }

        Log::info('ai.search.understood', [
            'filters' => array_keys($filters),
            'corrected' => is_string($corrected) && $corrected !== '',
        ]);

        return [
            'filters' => $filters,
            'corrected' => is_string($corrected) && trim($corrected) !== '' ? trim($corrected) : null,
        ];
    }

    /**
     * الاسم العربيّ ⇐ الحرف اللاتينيّ الذي يفهمه المحرّك.
     *
     * ─── لماذا هذه الخطوة ليست تفصيلًا ───
     *
     * `EntityExtractor` يبني مرشِّحاته من `slug` لا من `name_ar`، وكذلك يقرؤها
     * `MeilisearchProvider`. والنموذج يردّ الاسم العربيّ لأنّه ما أُملي عليه.
     * فتمرير «متوسط» حيث يُنتظر حرفٌ لاتينيّ يُنتج مرشِّحًا لا يطابق شيئًا:
     * بحثٌ ثانٍ بصفر نتائج يبدو عطلًا في المحرّك لا خطأً في الترجمة.
     *
     * والاسم الذي لا يقابله صفّ يُسقَط بهدوء. مرشِّحٌ مخترعٌ أسوأ من لا مرشِّح.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function toSlugs(array $data): array
    {
        $tables = ['stage' => 'stages', 'grade' => 'grades', 'subject' => 'subjects', 'semester' => 'semesters'];
        $filters = [];

        foreach (self::PASSABLE as $key) {
            $name = $data[$key] ?? null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $slug = DB::table($tables[$key])->where('name_ar', trim($name))->value('slug');

            if ($slug !== null) {
                $filters[$key] = (string) $slug;
            }
        }

        return $filters;
    }

    /** @return array<string, list<string>> */
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
