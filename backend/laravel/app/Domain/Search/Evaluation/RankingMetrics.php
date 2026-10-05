<?php

declare(strict_types=1);

namespace App\Domain\Search\Evaluation;

/**
 * مقاييس الترتيب القياسيّة على أحكامٍ متدرّجة (0–3).
 *
 * «ذو صلة» في المقاييس الثنائيّة (Precision/Recall/MRR/Top-k) = درجة ≥ 2.
 * NDCG يستعمل الدرجة كاملةً بمكسب ‎2^g − 1‎. دوالّ نقيّة بلا قاعدة ولا إطار.
 */
final class RankingMetrics
{
    public const RELEVANT = 2;

    /**
     * @param  list<int>  $grades  درجات النتائج بترتيبها
     */
    public static function precisionAt(array $grades, int $k): float
    {
        $hits = count(array_filter(array_slice($grades, 0, $k), static fn (int $g): bool => $g >= self::RELEVANT));

        return $hits / $k;
    }

    /**
     * @param  list<int>  $grades
     */
    public static function recallAt(array $grades, int $k, int $totalRelevant): ?float
    {
        if ($totalRelevant === 0) {
            return null;
        }
        $hits = count(array_filter(array_slice($grades, 0, $k), static fn (int $g): bool => $g >= self::RELEVANT));

        return $hits / $totalRelevant;
    }

    /**
     * @param  list<int>  $grades
     */
    public static function reciprocalRankAt(array $grades, int $k): float
    {
        foreach (array_slice($grades, 0, $k) as $i => $g) {
            if ($g >= self::RELEVANT) {
                return 1 / ($i + 1);
            }
        }

        return 0.0;
    }

    /**
     * @param  list<int>  $grades  النتائج المُعادة
     * @param  list<int>  $ideal  كلّ الأحكام الموجبة في الكتالوج
     */
    public static function ndcgAt(array $grades, array $ideal, int $k): ?float
    {
        rsort($ideal);
        $idcg = self::dcg(array_slice($ideal, 0, $k));
        if ($idcg == 0.0) {
            return null;
        }

        return self::dcg(array_slice($grades, 0, $k)) / $idcg;
    }

    /**
     * أعلى درجةٍ ممكنة لهذا الاستعلام ظهرت ضمن أوّل k.
     *
     * @param  list<int>  $grades
     */
    public static function bestInTop(array $grades, int $k, int $bestAvailable): bool
    {
        return $bestAvailable > 0 && in_array($bestAvailable, array_slice($grades, 0, $k), true);
    }

    /**
     * @param  list<float>  $values
     */
    public static function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $rank = ($p / 100) * (count($values) - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);

        return $values[$lo] + ($values[$hi] - $values[$lo]) * ($rank - $lo);
    }

    /**
     * @param  list<int>  $grades
     */
    private static function dcg(array $grades): float
    {
        $sum = 0.0;
        foreach ($grades as $i => $g) {
            $sum += ((2 ** $g) - 1) / log($i + 2, 2);
        }

        return $sum;
    }
}
