<?php

namespace Database\Seeders;

use App\Domain\Search\ArabicNormalizer;
use App\Domain\Search\Models\SearchSynonym;
use Illuminate\Database\Seeder;

/**
 * Default synonym dictionary — copied VERBATIM from the legacy theme's
 * Tahdheer_Search_Service::synonyms() $defaults map
 * (inc/search/class-tahdheer-search-service.php). No invented synonyms.
 *
 * Each key→value pair becomes one one_way row (the legacy map already lists
 * تحاضير→تحضير explicitly, so reciprocity stays exactly as the legacy had it).
 * Both sides are stored normalized, matching the legacy behavior of
 * normalizing keys at load time and values at expansion time.
 */
class SynonymSeeder extends Seeder
{
    /** @var array<string, array<int, string>> */
    private const LEGACY_DEFAULTS = [
        'تحضير' => ['تحاضير', 'اعداد', 'خطة'],
        'تحاضير' => ['تحضير'],
        'ابتدائي' => ['الابتدائيه', 'ابتدائيه'],
        'متوسط' => ['المتوسطه', 'متوسطه'],
        'ثانوي' => ['الثانويه', 'ثانويه'],
        'رياضيات' => ['رياضه', 'حساب'],
        'علوم' => ['ساينس'],
        'انجليزي' => ['انجليزيه', 'english'],
        'عرض' => ['بوربوينت', 'presentation', 'powerpoint'],
        'اختبار' => ['اختبارات', 'امتحان', 'امتحانات'],
        'ورقه' => ['اوراق', 'ورق'],
        'توزيع' => ['توزيعات'],
        'منهج' => ['مناهج', 'مقرر'],
        'فصل' => ['الفصل', 'ترم'],
    ];

    public function run(): void
    {
        foreach (self::LEGACY_DEFAULTS as $term => $synonyms) {
            $term = ArabicNormalizer::normalize((string) $term);

            foreach ($synonyms as $synonym) {
                $synonym = ArabicNormalizer::normalize($synonym);
                if ($term === '' || $synonym === '' || $term === $synonym) {
                    continue;
                }

                SearchSynonym::query()->updateOrCreate(
                    ['term' => $term, 'synonym' => $synonym],
                    [
                        'direction' => 'one_way',
                        'state' => 'active',
                        'source' => 'seed',
                        'evidence_count' => 0,
                    ],
                );
            }
        }
    }
}
