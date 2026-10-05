<?php

namespace App\Domain\Curriculum;

use App\Domain\SEO\IndexabilityPolicy;
use App\Domain\SEO\TaxonomyPath;
use App\Models\Grade;
use App\Models\Product;
use App\Models\Subject;

/**
 * الصفحات المحورية للمنهج — تقاطع المادة والصف.
 *
 * المعلّم لا يبحث عن «تحاضير»، بل عن «تحضير رياضيات سادس». ذلك التقاطع موجود
 * في بيانات المنتجات أصلًا، فهذه الوحدة تحوّله إلى صفحات تُبنى وتُحدَّث وحدها
 * بلا كتابة سطر محتوى واحد.
 *
 * ثلاث قواعد من مواصفة `theme-patch/inc/curriculum-hubs.php`، منفَّذة كما هي:
 *
 *  1) **لا صفحة لعنقود دون ثلاثة منتجات.** صفحةٌ فارغة بمظهر ممتلئ أسوأ من
 *     غيابها، وهي أسرع طريق إلى تصنيف الموقع كلّه «محتوى ضعيفًا». دون العتبة
 *     ‏404 حقيقية، لا صفحة بـnoindex.
 *
 *  2) **لا حاصل ضرب كارتيزي.** العناقيد تُحسب من صفوف منتجات فعلية، لا من كل
 *     تقاطع ممكن بين ثماني عشرة مادة واثني عشر صفًّا — وذلك بالضبط ما يُشعل
 *     انفجار الصفحات الرقيقة.
 *
 *  3) **المعرّف يُطابَق لا يُحلَّل.** السلسلة `الرياضيات-رابع-ابتدائي` لا تُفكّ
 *     إلى جزأيها — فالشرطة داخل الجزء لا تُميَّز من الشرطة الفاصلة. تُبنى
 *     معرّفات العناقيد المؤهَّلة ويُبحث عن المطابق، تمامًا كما يفعل الأصل.
 */
final class CurriculumHubs
{
    /** الأنواع المولَّدة. الثلاثة الأخرى (صف · مادة · فصل) لها محاور مستقلّة. */
    public const TYPES = ['subject-grade'];

    public function __construct(private readonly IndexabilityPolicy $policy) {}

    /**
     * العناقيد المؤهَّلة، مرتّبة بعدد المنتجات.
     *
     * @return list<array{type: string, slug: string, label: string, path: string, subject: string, grade: string, subjectSlug: string, gradeSlug: string, count: int}>
     */
    public function eligible(): array
    {
        $subjects = Subject::query()->get(['id', 'slug', 'name_ar'])->keyBy('id');
        $grades = Grade::query()->get(['id', 'slug', 'name_ar'])->keyBy('id');

        $rows = Product::query()
            ->published()
            ->whereNotNull('subject_id')
            ->whereNotNull('grade_id')
            ->groupBy('subject_id', 'grade_id')
            ->selectRaw('subject_id, grade_id, count(*) as aggregate')
            ->orderByDesc('aggregate')
            ->get();

        $hubs = [];

        foreach ($rows as $row) {
            $subject = $subjects->get($row->subject_id);
            $grade = $grades->get($row->grade_id);

            if ($subject === null || $grade === null) {
                continue;
            }

            $slug = $this->slugFor((string) $subject->slug, (string) $grade->slug);
            $path = $this->pathFor('subject-grade', $slug);
            $count = (int) $row->aggregate;

            // نفس العتبة التي تحرس الخريطة — فلا تُعلَن صفحة لا تُخدَم.
            if (! $this->policy->forCollection($path, $count)->isEligible()) {
                continue;
            }

            $hubs[] = [
                'type' => 'subject-grade',
                'slug' => $slug,
                'label' => $subject->name_ar.' — '.$grade->name_ar,
                'path' => $path,
                'subject' => (string) $subject->name_ar,
                'grade' => (string) $grade->name_ar,
                // مفردات المرشِّح كما فُهرست — لا كما كُتبت في المسار.
                'subjectSlug' => (string) $subject->slug,
                'gradeSlug' => (string) $grade->slug,
                'count' => $count,
            ];
        }

        return $hubs;
    }

    /**
     * عنقود واحد بمعرّفه — بالمطابقة لا بالتحليل.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $type, string $slug): ?array
    {
        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        foreach ($this->eligible() as $hub) {
            if ($hub['slug'] === $slug) {
                return $hub;
            }
        }

        return null;
    }

    /** معرّف العنقود: سلسلتا الجزأين موصولتان، بقاعدة المسار نفسها. */
    public function slugFor(string $subjectSlug, string $gradeSlug): string
    {
        return TaxonomyPath::segment($subjectSlug).'-'.TaxonomyPath::segment($gradeSlug);
    }

    public function pathFor(string $type, string $slug): string
    {
        return '/curriculum/'.$type.'/'.$slug;
    }
}
