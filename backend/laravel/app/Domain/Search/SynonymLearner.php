<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Search\Models\SearchSynonym;
use App\Models\SearchQuery;

/**
 * يتعلّم مرادفاتٍ من الأسئلة الفاشلة — ولا يُفعّل منها شيئًا.
 *
 * كلُّ سؤالٍ صفريّ يُشخَّص (`ZeroResultDiagnosis`)، وما كان «كلمةٌ مجهولة
 * قريبةٌ من كلمةٍ يعرفها الكتالوج» يصير مرادفًا **معلّقًا** (`pending`،
 * مصدرُه `learned`، باتّجاهٍ واحد: المكتوبة ⇐ المعروفة). يراه المشرف في
 * جدول المرادفات بدليله — عددِ مرّات البحث الفاشل — فيُفعّله أو يرفضه.
 *
 * ─── الضوابط ───
 *
 * · الدليل يُحسب من السجلّ في كلّ تشغيل (لا يُزاد): التشغيلُ المكرّر لا يضخّمه.
 * · حدٌّ أدنى للدليل (٢ افتراضًا): بحثٌ واحد ضجيج لا نمط.
 * · قرارُ المشرف نهائيّ: صفٌّ فُعّل أو رُفض أو كُتب يدويًّا لا يُمسّ.
 */
final class SynonymLearner
{
    public function __construct(private readonly ZeroResultDiagnosis $diagnosis) {}

    /**
     * @return array{created: int, updated: int, kept: int}
     */
    public function learn(int $days = 30, int $minEvidence = 2, bool $dryRun = false): array
    {
        config(['ai.features.search_assist' => false]);

        $rows = SearchQuery::query()
            ->where('zero_result', true)
            ->where('created_at', '>=', now()->subDays($days))
            ->where('normalized_query', '!=', '')
            ->selectRaw('normalized_query, count(*) as hits')
            ->groupBy('normalized_query')
            ->get();

        /** @var array<string, array{term: string, synonym: string, evidence: int}> $pairs */
        $pairs = [];
        foreach ($rows as $row) {
            $d = $this->diagnosis->diagnose((string) $row->normalized_query);
            if ($d['reason'] !== 'unknown_term') {
                continue;
            }
            $key = $d['detail']['term']."\u{0}".$d['detail']['nearest'];
            $pairs[$key] ??= ['term' => $d['detail']['term'], 'synonym' => $d['detail']['nearest'], 'evidence' => 0];
            $pairs[$key]['evidence'] += (int) $row->hits;
        }

        $summary = ['created' => 0, 'updated' => 0, 'kept' => 0];
        foreach ($pairs as $pair) {
            if ($pair['evidence'] < $minEvidence) {
                continue;
            }
            $existing = SearchSynonym::query()->where('term', $pair['term'])->where('synonym', $pair['synonym'])->first();

            if ($existing === null) {
                $summary['created']++;
                if (! $dryRun) {
                    SearchSynonym::query()->create([
                        'term' => $pair['term'], 'synonym' => $pair['synonym'], 'direction' => 'one_way',
                        'state' => 'pending', 'source' => 'learned', 'evidence_count' => $pair['evidence'],
                    ]);
                }
            } elseif ($existing->state === 'pending' && $existing->source === 'learned') {
                $summary['updated']++;
                if (! $dryRun) {
                    $existing->update(['evidence_count' => $pair['evidence']]);
                }
            } else {
                $summary['kept']++;
            }
        }

        return $summary;
    }
}
