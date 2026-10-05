<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Demand\MaterialRequest;
use App\Models\Grade;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;

/**
 * لماذا لم يجد الزائر شيئًا — وما الذي يُفعل.
 *
 * السجلّ (`search_queries`) يقول «صفر» ولا يقول لماذا، فتبقى قائمة الأسئلة
 * الفاشلة قائمةً تُقرأ ولا يُعمل بها. هنا يُعاد كلُّ سؤالٍ على البحث **الحاليّ**
 * تشغيلًا جافًّا، ويُصنَّف بسببٍ واحد وفعلٍ واحد:
 *
 *   resolved            يُجاب الآن — أُصلح منذ سُجّل (مرادف، تصحيح، منتج جديد)
 *   missing_inventory   القصدُ مفهوم (مادّة/صفّ/نوع) ولا منتج له — فرصةُ منتج
 *   unknown_term        كلمةٌ مجهولة قريبةٌ من كلمةٍ يعرفها الكتالوج — مرادفٌ ناقص
 *   off_catalog         لا صلة بالمتجر — لا فعل
 *
 * حتميّ ورخيص: البحث نفسه بلا ذكاء، ومعجمٌ يعرفه الكتالوج.
 */
final class ZeroResultDiagnosis
{
    public function __construct(
        private readonly QueryPipeline $pipeline,
        private readonly EntityExtractor $extractor,
    ) {}

    /**
     * @return array{reason: string, action: string, detail: array<string, mixed>}
     */
    public function diagnose(string $query): array
    {
        $out = $this->pipeline->dryRun($query);

        if ($out['result']->total > 0) {
            return [
                'reason' => 'resolved',
                'action' => 'صار يُجاب بـ'.$out['result']->total.' نتيجة.',
                'detail' => ['results' => $out['result']->total],
            ];
        }

        $normalized = ArabicNormalizer::normalize($query);
        $extraction = $this->extractor->extract($normalized);
        $entities = $this->labels(array_filter($extraction['filters']));
        if ($entities !== []) {
            /* ما بقي بعد الكيانات موضوعُ الدرس غالبًا («الجهاز الهضمي») — جزءٌ من الطلب. */
            $topic = $extraction['residual'];
            $requested = $this->requested($extraction, $normalized);

            return [
                'reason' => 'missing_inventory',
                'action' => 'منتجٌ مطلوب: '.implode(' · ', $entities).($topic !== '' ? " — «{$topic}»" : '')
                    .($requested > 0 ? " · {$requested} طلبًا مسجّلًا" : ''),
                'detail' => ['entities' => $entities, 'topic' => $topic === '' ? null : $topic, 'requested' => $requested],
            ];
        }

        foreach (preg_split('/\s+/u', $extraction['residual']) ?: [] as $token) {
            if (ArabicNormalizer::length($token) < 3) {
                continue;
            }
            $near = $this->pipeline->nearestKnownWord($token);
            if ($near !== null) {
                return [
                    'reason' => 'unknown_term',
                    'action' => "مرادفٌ ناقص: «{$token}» ⇐ «{$near}»",
                    'detail' => ['term' => $token, 'nearest' => $near],
                ];
            }
        }

        return ['reason' => 'off_catalog', 'action' => 'لا فعل مطلوب.', 'detail' => []];
    }

    /**
     * الطلبُ الصريح للشيء نفسه (`material_requests`) — ما لم يُنشر ولم يُرفض.
     *
     * «الشيء نفسه» أحدُ أمرين: كلُّ بُعدٍ فهمه البحث يساوي بُعدَ الطلب (وللطلب
     * أن يزيد بُعدًا)، أو نصُّ الطلب هو نصُّ السؤال مطبَّعًا (طلبٌ جاء من صفحة
     * الصفر بلا أبعاد). وتُجمع `hits`: الطلبُ المكرّر صفٌّ واحد بعدّاده.
     *
     * @param  array{ids: array<string, ?int>, filters: array<string, ?string>}  $extraction
     */
    private function requested(array $extraction, string $normalized): int
    {
        $ids = array_filter($extraction['ids'], static fn ($v): bool => $v !== null);
        /* المرحلةُ مستنتجةٌ من الصفّ في الاستخراج؛ والطلبُ بصفٍّ قلّما يكتبها. الصفُّ يكفي. */
        if (isset($ids['grade_id'])) {
            unset($ids['stage_id']);
        }
        $kind = $extraction['filters']['kind'] ?? null;

        $open = MaterialRequest::query()->whereNotIn('status', ['published', 'declined']);

        $byDims = $ids === [] && $kind === null ? 0 : (int) (clone $open)
            ->where(function ($q) use ($ids, $kind) {
                foreach ($ids as $column => $id) {
                    $q->where($column, $id);
                }
                if ($kind !== null) {
                    $q->where('kind', $kind);
                }
            })
            ->sum('hits');

        $byText = (clone $open)->whereNotNull('query')->get(['query', 'hits', 'stage_id', 'grade_id', 'subject_id', 'semester_id', 'kind'])
            ->filter(fn (MaterialRequest $r): bool => ArabicNormalizer::normalize((string) $r->query) === $normalized
                /* لا يُعدّ مرّتين: طلبٌ طابقت أبعادُه عُدَّ أعلاه. */
                && ! ($ids !== [] && $this->sameDims($r, $ids, $kind)))
            ->sum('hits');

        return $byDims + (int) $byText;
    }

    /** @param  array<string, int>  $ids */
    private function sameDims(MaterialRequest $r, array $ids, ?string $kind): bool
    {
        foreach ($ids as $column => $id) {
            if ((int) $r->{$column} !== $id) {
                return false;
            }
        }

        return $kind === null || $r->kind === $kind;
    }

    /**
     * @param  array<string, string>  $filters  slugs
     * @return list<string> أسماءٌ عربيّة بترتيبٍ ثابت
     */
    private function labels(array $filters): array
    {
        $names = [];
        if (isset($filters['kind'])) {
            $names[] = $filters['kind'];
        }
        foreach (['subject' => Subject::class, 'grade' => Grade::class, 'semester' => Semester::class] as $dim => $model) {
            if (isset($filters[$dim])) {
                $names[] = (string) ($model::query()->where('slug', $filters[$dim])->value('name_ar') ?? $filters[$dim]);
            }
        }
        if (isset($filters['stage']) && ! isset($filters['grade'])) {
            $names[] = (string) (Stage::query()->where('slug', $filters['stage'])->value('name_ar') ?? $filters['stage']);
        }
        /* الصيغة تُذكر حين لا يحملها النوع («عرض بوربوينت» يعني PPTX أصلًا). */
        if (isset($filters['format']) && ! isset($filters['kind'])) {
            $names[] = $filters['format'];
        }

        return $names;
    }
}
