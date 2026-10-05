<?php

namespace Database\Seeders;

use App\Domain\Catalog\ProductClassifier;
use App\Models\CurriculumAlias;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Canonical curriculum vocabulary — copied VERBATIM from the legacy theme's
 * inc/curriculum/dimensions.php (seed version 1). No invention, no omission.
 *
 * Every term name and every alias is stored in curriculum_aliases together
 * with its normalized form, so the classifier and search entity extraction
 * match every phrasing a real product title uses.
 */
class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedStages();
        $this->seedGrades();
        $this->seedSubjects();
        $this->seedSemesters();
        $this->seedKinds();
    }

    private function seedStages(): void
    {
        $stages = [
            'ابتدائي' => ['المرحلة الابتدائية', 'الابتدائية', 'ابتدائية'],
            'متوسط' => ['المرحلة المتوسطة', 'المتوسطة', 'متوسطة'],
            'ثانوي' => ['المرحلة الثانوية', 'الثانوية', 'ثانوية'],
        ];

        $position = 0;
        foreach ($stages as $name => $aliases) {
            $stage = Stage::query()->updateOrCreate(
                ['slug' => $name],
                ['name_ar' => $name, 'position' => $position++],
            );
            $this->seedAliases('stage', $stage->id, [$name, ...$aliases]);
        }
    }

    /**
     * The twelve Saudi general-education grades — same generation matrix as
     * tahdheer_curriculum_grades(): every ordinal form (bare/definite/Latin
     * digit/Arabic digit) × every stage form (bare/definite), with and without
     * the leading «الصف».
     */
    private function seedGrades(): void
    {
        $bare = ['أول', 'ثاني', 'ثالث', 'رابع', 'خامس', 'سادس'];
        $definite = ['الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس'];
        $digits = ['1', '2', '3', '4', '5', '6'];
        $arabic = ['١', '٢', '٣', '٤', '٥', '٦'];
        $stages = [
            'ابتدائي' => ['count' => 6, 'definite' => 'الابتدائي'],
            'متوسط' => ['count' => 3, 'definite' => 'المتوسط'],
            'ثانوي' => ['count' => 3, 'definite' => 'الثانوي'],
        ];

        $position = 0;
        foreach ($stages as $stageSlug => $conf) {
            $stageId = Stage::query()->where('slug', $stageSlug)->value('id');

            for ($i = 0; $i < $conf['count']; $i++) {
                $ordinals = [$bare[$i], $definite[$i], $digits[$i], $arabic[$i]];
                $stageForms = [$stageSlug, $conf['definite']];

                $aliases = [];
                foreach ($ordinals as $ord) {
                    foreach ($stageForms as $st) {
                        $aliases[] = $ord.' '.$st;
                        $aliases[] = 'الصف '.$ord.' '.$st;
                    }
                }

                $name = 'الصف '.$definite[$i].' '.$conf['definite'];
                $grade = Grade::query()->updateOrCreate(
                    ['slug' => $bare[$i].'-'.$stageSlug],
                    ['stage_id' => $stageId, 'name_ar' => $name, 'position' => $position++],
                );

                $this->seedAliases('grade', $grade->id, [$name, ...array_values(array_unique($aliases))]);
            }
        }
    }

    /** All 18 subjects with their aliases, verbatim. */
    private function seedSubjects(): void
    {
        $subjects = [
            'لغتي' => ['لغتي الجميلة', 'لغتي الخالدة'],
            'اللغة العربية' => ['عربي', 'النحو', 'الأدب', 'البلاغة'],
            'الكفايات اللغوية' => ['كفايات لغوية', 'كفايات'],
            'الرياضيات' => ['رياضيات', 'حساب'],
            'العلوم' => ['علوم'],
            'الفيزياء' => ['فيزياء'],
            'الكيمياء' => ['كيمياء'],
            'الأحياء' => ['أحياء'],
            'الدراسات الإسلامية' => ['إسلامية', 'تربية إسلامية', 'التوحيد', 'الفقه', 'التفسير', 'الحديث'],
            'القرآن الكريم' => ['قرآن', 'تلاوة', 'تجويد'],
            'الدراسات الاجتماعية' => ['اجتماعيات', 'التاريخ', 'الجغرافيا', 'الوطنية'],
            'اللغة الإنجليزية' => ['إنجليزي', 'english'],
            'المهارات الرقمية' => ['مهارات رقمية', 'الحاسب', 'الحاسب الآلي', 'حاسوب'],
            'المهارات الحياتية' => ['مهارات حياتية', 'الأسرية', 'التربية الأسرية'],
            'التفكير الناقد' => ['تفكير ناقد', 'التفكير'],
            'التربية الفنية' => ['فنية', 'الفنون'],
            'التربية البدنية' => ['بدنية', 'رياضة'],
            'علم البيئة' => ['بيئة'],
        ];

        $position = 0;
        foreach ($subjects as $name => $aliases) {
            $subject = Subject::query()->updateOrCreate(
                ['slug' => $name],
                ['name_ar' => $name, 'position' => $position++],
            );
            $this->seedAliases('subject', $subject->id, [$name, ...$aliases]);
        }
    }

    /** Three semesters — formal names, short slugs, verbatim aliases. */
    private function seedSemesters(): void
    {
        $semesters = [
            ['name' => 'الفصل الدراسي الأول', 'slug' => 'الفصل-الأول', 'aliases' => ['الفصل الأول', 'ف1', 'الترم الأول']],
            ['name' => 'الفصل الدراسي الثاني', 'slug' => 'الفصل-الثاني', 'aliases' => ['الفصل الثاني', 'ف2', 'الترم الثاني']],
            ['name' => 'الفصل الدراسي الثالث', 'slug' => 'الفصل-الثالث', 'aliases' => ['الفصل الثالث', 'ف3', 'الترم الثالث']],
        ];

        foreach ($semesters as $position => $row) {
            $semester = Semester::query()->updateOrCreate(
                ['slug' => $row['slug']],
                ['name_ar' => $row['name'], 'position' => $position],
            );
            $this->seedAliases('semester', $semester->id, [$row['name'], ...$row['aliases']]);
        }
    }

    /** Seven file kinds with their aliases, verbatim. */
    private function seedKinds(): void
    {
        $kinds = [
            'تحضير' => ['تحاضير', 'تحضير درس', 'دفتر تحضير', 'خطة درس'],
            'عرض بوربوينت' => ['بوربوينت', 'عروض', 'عرض تقديمي', 'powerpoint', 'ppt'],
            'أوراق عمل' => ['ورق عمل', 'ورقة عمل', 'أنشطة', 'تدريبات'],
            'اختبارات' => ['اختبار', 'بنك أسئلة', 'أسئلة', 'نماذج اختبار'],
            'توزيع' => ['توزيع المنهج', 'خطة توزيع'],
            'وسائل تعليمية' => ['وسائل', 'سبورة', 'لوحات'],
            'حلول' => ['حل', 'الحل', 'إجابات'],
        ];

        $position = 0;
        foreach ($kinds as $name => $aliases) {
            $kind = Kind::query()->updateOrCreate(
                ['slug' => $name],
                ['name_ar' => $name, 'position' => $position++],
            );
            $this->seedAliases('kind', $kind->id, [$name, ...$aliases]);
        }
    }

    /**
     * Store each phrasing with its normalized form, deduplicated on the
     * normalized value (e.g. «1 ابتدائي» and «١ ابتدائي» normalize identically).
     *
     * @param  array<int, string>  $phrases
     */
    private function seedAliases(string $entityType, int $entityId, array $phrases): void
    {
        $rows = [];
        foreach ($phrases as $phrase) {
            $phrase = trim($phrase);
            if ($phrase === '') {
                continue;
            }
            $normalized = trim(ProductClassifier::norm($phrase));
            if ($normalized === '' || isset($rows[$normalized])) {
                continue;
            }
            $rows[$normalized] = [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'alias' => $phrase,
                'normalized_alias' => $normalized,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        CurriculumAlias::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->delete();
        CurriculumAlias::query()->insert(array_values($rows));
    }
}
