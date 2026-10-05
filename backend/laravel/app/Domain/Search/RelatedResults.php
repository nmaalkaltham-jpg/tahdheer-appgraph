<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * الطبقة الثانية من صفحة النتائج — «قريبٌ ممّا تبحث عنه».
 *
 * ─── لماذا ───
 *
 * قيس على المجموعة الذهبية (`search:eval`): الترتيب لم يضع منتجًا أضعف فوق
 * أقوى في سؤالٍ واحد من ١٤٠٤، وكلّ خسارة NDCG كانت منتجاتٍ ذات صلة تُسقطها
 * المرشِّحات الصلبة كاملةً. «رياضيات سادس» تُعيد منتجًا واحدًا، والرياضيات
 * موجودةٌ للأوّل والثالث. الصلابة صحيحة للنتائج المطابقة، والنقص في البنية.
 *
 * ─── القاعدة ───
 *
 * درجاتٌ بالقرب، الأقرب أوّلًا، ولكلٍّ سببُه المُعلَن:
 *
 *   kind    المادّة والصفّ نفساهما — نوعٌ أو فصلٌ أو صيغةٌ أخرى
 *   grade   المادّة نفسها في المرحلة نفسها — صفوفٌ أخرى
 *
 * المادّة والمرحلة لا تُسقطان أبدًا. المادّة حاملُ القصد. والمرحلة قيست:
 * درجةُ «مراحل أخرى» جعلت ٩٪ من صفحة «اختبارات أحياء ثانوي» منتجاتٍ لمرحلةٍ
 * سمّاها الزائر بنفسه — ورياضيات الثانوي لا تنفع معلّم السادس.
 * ولا تظهر الطبقة إلّا في الصفحة الأولى، لسؤالٍ مُهيكَل، أجاب إجابةً مطابقة
 * أقلَّ من صفحة — وإلّا فالنتائج تكفي أو التخفيف أجاب أصلًا.
 */
final class RelatedResults
{
    public const CAP = 8;

    /** @var array<string, array{drop: list<string>, needs: string, label: string}> */
    private const TIERS = [
        'kind' => ['drop' => ['kind', 'format', 'semester'], 'needs' => 'grade', 'label' => 'المادة والصف نفسهما — أنواع أخرى'],
        'grade' => ['drop' => ['kind', 'format', 'semester', 'grade'], 'needs' => 'subject', 'label' => 'المادة نفسها — صفوف أخرى'],
    ];

    /**
     * @param  callable(SearchRequest): SearchResult  $search
     * @param  list<string>  $pinned  أبعادٌ اختارها الزائر صراحةً — لا تُسقط
     * @return list<array{reason: string, label: string, items: Collection<int, Product>}>
     */
    public function for(SearchRequest $request, SearchResult $primary, callable $search, array $pinned = []): array
    {
        $filters = array_filter($request->filters, static fn ($v): bool => $v !== null);
        if ($request->page !== 1 || $primary->total === 0 || $primary->total >= $request->perPage || $filters === []) {
            return [];
        }

        $seen = $primary->items->pluck('id')->flip()->all();
        $groups = [];
        $room = self::CAP;

        foreach (self::TIERS as $reason => $tier) {
            if ($room <= 0 || ($request->filters[$tier['needs']] ?? null) === null) {
                continue;
            }

            $droppable = array_diff($tier['drop'], $pinned);
            $next = $request->filters;
            foreach ($droppable as $dim) {
                $next[$dim] = null;
            }
            /* درجةٌ لا تُسقط شيئًا مضبوطًا هي السؤال نفسه — لا جديد فيها. */
            if ($next === $request->filters || array_filter($next, static fn ($v): bool => $v !== null) === []) {
                continue;
            }

            $result = $search(new SearchRequest(
                query: $request->query,
                rawQuery: $request->rawQuery,
                filters: $next,
                editable: $request->editable,
                expandedTokens: $request->expandedTokens,
                typeTab: $request->typeTab,
                sort: $request->sort,
                page: 1,
                perPage: self::CAP + count($seen),
            ));

            $items = $result->items->reject(fn (Product $p): bool => isset($seen[$p->id]))->take($room)->values();
            if ($items->isEmpty()) {
                continue;
            }

            foreach ($items as $p) {
                $seen[$p->id] = true;
            }
            $room -= $items->count();
            $groups[] = ['reason' => $reason, 'label' => $tier['label'], 'items' => $items];
        }

        return $groups;
    }
}
