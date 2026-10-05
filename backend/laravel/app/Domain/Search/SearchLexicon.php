<?php

declare(strict_types=1);

namespace App\Domain\Search;

/**
 * معجمُ البحث المُضمَّن — صيغُ الناس التي لا يحملها `curriculum_aliases`.
 *
 * الجدول يحمل الصيغ الفصيحة والشائعة للمنهج، ويُدار من الإدارة. وهذا
 * المعجم يحمل ما قيس غيابُه على المجموعة الذهبية (`search:eval`): الأسماء
 * الإنجليزيّة، والعامّيّة («انقلش»، «دين»)، و«grade N»، والمؤنّث («أولى
 * متوسط»)، ومراحل التعليم بالإنجليزيّة. في الكود لا في البيانات: يُراجَع
 * في الفرق، ويعمل على قاعدةٍ فارغة، ولا يحتاج ترحيلًا.
 *
 * المفتاح هو **slug** الكيان كما يبذره `CurriculumSeeder`؛ صيغةٌ لكيانٍ
 * غير موجود تُتجاهل بصمت. والجدول يعلو المعجم عند التعارض.
 */
final class SearchLexicon
{
    /** @var array<string, list<string>> subject slug ⇒ صيغ */
    public const SUBJECTS = [
        'لغتي' => [],
        'اللغة العربية' => ['arabic', 'arabic language'],
        'الكفايات اللغوية' => [],
        'الرياضيات' => ['math', 'maths', 'mathematics'],
        'العلوم' => ['science', 'sciences'],
        'الفيزياء' => ['physics'],
        'الكيمياء' => ['chemistry'],
        'الأحياء' => ['biology'],
        'الدراسات الإسلامية' => ['دين', 'islamic', 'islamic studies'],
        'القرآن الكريم' => ['quran'],
        'الدراسات الاجتماعية' => ['social studies', 'history', 'geography'],
        'اللغة الإنجليزية' => ['انقلش', 'انجلش', 'انجليزيه', 'انقليزي', 'english'],
        'المهارات الرقمية' => ['computer', 'computer science', 'digital skills', 'ict'],
        'المهارات الحياتية' => ['life skills'],
        'التفكير الناقد' => ['critical thinking'],
        'التربية الفنية' => ['رسم', 'art', 'arts'],
        'التربية البدنية' => ['pe', 'physical education'],
        'علم البيئة' => ['environment', 'environmental science'],
    ];

    /** @var array<string, list<string>> kind slug ⇒ صيغ */
    public const KINDS = [
        'تحضير' => ['lesson plan', 'lesson plans'],
        'عرض بوربوينت' => ['باوربوينت', 'بوربوينت', 'slides', 'presentation'],
        'أوراق عمل' => ['worksheet', 'worksheets'],
        'اختبارات' => ['exam', 'exams', 'test', 'tests', 'quiz'],
        'توزيع' => [],
        'وسائل تعليمية' => ['وسيله تعليميه'],
        'حلول' => ['solutions', 'answers'],
    ];

    /** @var array<string, list<string>> stage slug ⇒ صيغ */
    public const STAGES = [
        'ابتدائي' => ['primary', 'elementary'],
        'متوسط' => ['intermediate', 'middle school'],
        'ثانوي' => ['secondary', 'high school'],
    ];

    /** @var array<string, list<string>> semester slug ⇒ صيغ */
    public const SEMESTERS = [
        'الفصل-الأول' => ['ترم اول', 'term 1', 'semester 1'],
        'الفصل-الثاني' => ['ترم ثاني', 'term 2', 'semester 2'],
        'الفصل-الثالث' => ['ترم ثالث', 'term 3', 'semester 3'],
    ];

    /** المؤنّث الشائع: «أولى متوسط»، «ثالثة ثانوي». */
    private const ORDINAL_FEMININE = ['اولي', 'ثانيه', 'ثالثه', 'رابعه', 'خامسه', 'سادسه'];

    private const ORDINAL_SLUG = ['أول', 'ثاني', 'ثالث', 'رابع', 'خامس', 'سادس'];

    /** المُعرَّف: «الأول»… — لـ«الأول م». */
    private const ORDINAL_DEFINITE = ['الاول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس'];

    /** stage slug ⇒ [عدد الصفوف، إزاحة السلّم الموحّد 1–12، حرف الاختصار أو null]. */
    private const STAGE_GRADES = ['ابتدائي' => [6, 0, null], 'متوسط' => [3, 6, 'م'], 'ثانوي' => [3, 9, 'ث']];

    /**
     * كلّ الصيغ مطبَّعةً: [entity_type, slug, normalized_alias].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function entries(): array
    {
        $out = [];
        $push = static function (string $type, string $slug, string $alias) use (&$out): void {
            $normalized = ArabicNormalizer::normalize($alias);
            if ($normalized !== '') {
                $out[] = [$type, $slug, $normalized];
            }
        };

        foreach (['subject' => self::SUBJECTS, 'kind' => self::KINDS, 'stage' => self::STAGES, 'semester' => self::SEMESTERS] as $type => $map) {
            foreach ($map as $slug => $aliases) {
                foreach ($aliases as $alias) {
                    $push($type, $slug, $alias);
                }
            }
        }

        foreach (self::STAGE_GRADES as $stage => [$count, $offset, $abbr]) {
            for ($i = 0; $i < $count; $i++) {
                $slug = self::ORDINAL_SLUG[$i].'-'.$stage;
                $push('grade', $slug, self::ORDINAL_FEMININE[$i].' '.$stage);
                $push('grade', $slug, 'grade '.($offset + $i + 1));
                $push('grade', $slug, ($offset + $i + 1).'th grade');
                /* «أول م»، «الثاني ث»، «2 ث»، «3م» — الابتدائيّ بلا اختصار: «ب» لا تُكتب. */
                if ($abbr !== null) {
                    foreach ([self::ORDINAL_SLUG[$i], self::ORDINAL_DEFINITE[$i], self::ORDINAL_FEMININE[$i], (string) ($i + 1)] as $ord) {
                        $push('grade', $slug, $ord.' '.$abbr);
                    }
                    $push('grade', $slug, ($i + 1).$abbr);
                }
            }
        }

        return $out;
    }
}
