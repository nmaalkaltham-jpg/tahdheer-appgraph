<?php

declare(strict_types=1);

namespace App\Domain\Search\Evaluation;

use App\Domain\Search\ArabicNormalizer;
use App\Domain\Search\ProductSearchProjector;
use App\Models\Grade;
use App\Models\Product;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * المجموعة الذهبية المولَّدة — أسئلةٌ بصيغ الناس لا بصيغ الكتالوج.
 *
 * كلّ سؤالٍ يحمل **نيّته** المُهيكَلة (مادّة/صفّ/مرحلة/فصل/نوع/صيغة) كما
 * قصدها السائل، والحكم يُشتقّ منها على الكتالوج كلّه عبر `RelevanceJudge`.
 *
 * ─── لماذا المفردات هنا لا في `curriculum_aliases` ───
 *
 * هذه قائمةُ «كيف يكتب المعلّمون»، مستقلّةٌ عمدًا عن معجم المحرّك: لو
 * وُلّدت الأسئلة من المعجم نفسه لنجح كلّ سؤالٍ بالتعريف، ولما كشف القياسُ
 * فجوةً واحدة. إضافةُ صيغةٍ هنا تكشف الفجوة؛ وإضافتها للمحرّك تسدّها.
 *
 * حتميّة: الترتيب والمحتوى ثابتان للكتالوج نفسه، فالقياسان «قبل/بعد»
 * يقيسان السؤال نفسه.
 */
final class GoldenQuerySet
{
    /** صيغُ المادّة كما تُكتب في البحث — العامّيّة والإنجليزيّة. */
    private const SUBJECT_FORMS = [
        'لغتي' => ['colloquial' => ['لغتي الجميلة'], 'en' => []],
        'اللغة العربية' => ['colloquial' => ['عربي', 'نحو'], 'en' => ['arabic']],
        'الكفايات اللغوية' => ['colloquial' => ['كفايات'], 'en' => []],
        'الرياضيات' => ['colloquial' => ['رياضيات', 'حساب'], 'en' => ['math', 'maths']],
        'العلوم' => ['colloquial' => ['علوم'], 'en' => ['science']],
        'الفيزياء' => ['colloquial' => ['فيزياء'], 'en' => ['physics']],
        'الكيمياء' => ['colloquial' => ['كيمياء'], 'en' => ['chemistry']],
        'الأحياء' => ['colloquial' => ['أحياء'], 'en' => ['biology']],
        'الدراسات الإسلامية' => ['colloquial' => ['إسلامية', 'دين'], 'en' => ['islamic studies']],
        'القرآن الكريم' => ['colloquial' => ['قرآن'], 'en' => ['quran']],
        'الدراسات الاجتماعية' => ['colloquial' => ['اجتماعيات', 'تاريخ', 'جغرافيا'], 'en' => ['social studies']],
        'اللغة الإنجليزية' => ['colloquial' => ['انجليزي', 'انقلش'], 'en' => ['english']],
        'المهارات الرقمية' => ['colloquial' => ['حاسب', 'حاسب آلي'], 'en' => ['computer']],
        'المهارات الحياتية' => ['colloquial' => ['مهارات حياتية', 'أسرية'], 'en' => ['life skills']],
        'التفكير الناقد' => ['colloquial' => ['تفكير ناقد'], 'en' => ['critical thinking']],
        'التربية الفنية' => ['colloquial' => ['فنية', 'رسم'], 'en' => ['art']],
        'التربية البدنية' => ['colloquial' => ['بدنية'], 'en' => ['pe']],
        'علم البيئة' => ['colloquial' => ['بيئة'], 'en' => ['environment']],
    ];

    /** صيغُ النوع — `resource_type` ⇒ صيغ عربيّة + إنجليزيّة. */
    private const KIND_FORMS = [
        'تحضير' => ['ar' => ['تحضير', 'تحاضير', 'تحضير درس'], 'en' => ['lesson plan']],
        'عرض بوربوينت' => ['ar' => ['عرض بوربوينت', 'بوربوينت', 'باوربوينت', 'عرض تقديمي'], 'en' => ['powerpoint', 'ppt']],
        'أوراق عمل' => ['ar' => ['أوراق عمل', 'ورق عمل', 'ورقة عمل'], 'en' => ['worksheets']],
        'اختبارات' => ['ar' => ['اختبارات', 'اختبار', 'أسئلة'], 'en' => ['exam']],
        'توزيع' => ['ar' => ['توزيع', 'توزيع المنهج'], 'en' => []],
        'وسائل تعليمية' => ['ar' => ['وسائل تعليمية', 'وسائل', 'وسيلة تعليمية'], 'en' => []],
        'حلول' => ['ar' => ['حلول', 'حل', 'إجابات'], 'en' => ['solutions']],
    ];

    private const ORDINAL = ['أول', 'ثاني', 'ثالث', 'رابع', 'خامس', 'سادس'];

    private const ORDINAL_DEF = ['الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس'];

    /** «أولى متوسط»، «ثالثة ثانوي» — المؤنّث الشائع في الكلام. */
    private const ORDINAL_FEM = ['أولى', 'ثانية', 'ثالثة', 'رابعة', 'خامسة', 'سادسة'];

    /** @var array<int, string> stage id ⇒ slug */
    private array $stageSlugs = [];

    private const INDIC = ['١', '٢', '٣', '٤', '٥', '٦'];

    /** الحرف المختصر: «أول م»، «ثاني ث». */
    private const STAGE_ABBR = ['ابتدائي' => 'ب', 'متوسط' => 'م', 'ثانوي' => 'ث'];

    /** سنة الصفّ في السلّم الموحّد (grade 1–12). */
    private const STAGE_OFFSET = ['ابتدائي' => 0, 'متوسط' => 6, 'ثانوي' => 9];

    private const SEMESTER_FORMS = [
        0 => ['الفصل الأول', 'ترم أول', 'ف1', 'الترم الأول', 'term 1'],
        1 => ['الفصل الثاني', 'ترم ثاني', 'ف2', 'الترم الثاني', 'term 2'],
        2 => ['الفصل الثالث', 'ترم ثالث', 'ف3', 'الترم الثالث', 'term 3'],
    ];

    /** أخطاءٌ مرصودةٌ حرفيًّا من الطلب: الصيغة ⇒ [البُعد، القيمة الصحيحة]. */
    private const CURATED_TYPOS = [
        'علووم' => ['subject', 'العلوم'],
        'الرايضيات' => ['subject', 'الرياضيات'],
        'رياضايات' => ['subject', 'الرياضيات'],
        'الريضيات' => ['subject', 'الرياضيات'],
        'رياضيا' => ['subject', 'الرياضيات'],
        'فيزيا' => ['subject', 'الفيزياء'],
        'الكمياء' => ['subject', 'الكيمياء'],
        'انجلش' => ['subject', 'اللغة الإنجليزية'],
        'بوربويت' => ['kind', 'عرض بوربوينت'],
        'بور بوينت' => ['kind', 'عرض بوربوينت'],
        'بور بونت' => ['kind', 'عرض بوربوينت'],
        'بوربونت' => ['kind', 'عرض بوربوينت'],
        'اختبرات' => ['kind', 'اختبارات'],
        'تحظير' => ['kind', 'تحضير'],
    ];

    /** أخطاءُ المرحلة والرتبة: تُطبَّق على «رتبة مرحلة». */
    private const GRADE_TYPOS = ['ابتداءي' => 'ابتدائي', 'ابتدايى' => 'ابتدائي', 'متوسظ' => 'متوسط', 'متوصط' => 'متوسط', 'ثانوى' => 'ثانوي', 'خامص' => 'خامس', 'ساد' => 'سادس'];

    /** لا منتج يطابقها — يجب أن تعود بلا نتائج أو بتعافٍ مُعلَن. */
    private const NONSENSE = ['xyzqwerty', 'zzzzqqqx', 'قطط فضائية', 'زقنبوطية', 'سيارات رياضية للبيع', 'وصفة كبسة دجاج', 'iphone 17 pro', 'تذاكر طيران الرياض', 'asdfgh', 'كرسي مكتب'];

    /** لوحة المفاتيح العربيّة — جيرانُ كلّ حرفٍ في صفّه، لأخطاء الإصبع. */
    private const KEY_ROWS = ['ضصثقفغعهخحجد', 'شسيبلاتنمكط', 'ئءؤرىةوزظ'];

    /**
     * @return list<array{query: string, type: string, intent: array<string, mixed>}>
     */
    public function build(): array
    {
        $products = Product::query()->published()->whereNotNull('subject_id')->whereNotNull('grade_id')->orderBy('id')->get();
        $subjects = Subject::query()->get(['id', 'name_ar'])->keyBy('id');
        $grades = Grade::query()->orderBy('id')->get(['id', 'stage_id', 'position', 'name_ar'])->keyBy('id');
        $semesters = Semester::query()->orderBy('id')->get(['id'])->pluck('id')->values();
        $this->stageSlugs = Stage::query()->pluck('slug', 'id')->map(fn ($s): string => (string) $s)->all();

        $cases = [];
        $add = function (string $query, string $type, array $intent) use (&$cases): void {
            $query = trim((string) preg_replace('/\s+/u', ' ', $query));
            if ($query !== '' && ! isset($cases[$query])) {
                $cases[$query] = ['query' => $query, 'type' => $type, 'intent' => $intent];
            }
        };

        $covered = [];
        foreach ($products as $p) {
            $subject = (string) $subjects[$p->subject_id]->name_ar;
            $grade = $grades[$p->grade_id];
            $stageId = (int) $grade->stage_id;
            $ord = $this->ordinalIndex($grade, $grades);
            $semIdx = $p->semester_id !== null ? $semesters->search($p->semester_id) : false;
            $kind = (string) $p->resource_type;
            $covered[$p->subject_id.':'.$p->grade_id] = true;

            $sForms = $this->subjectForms($subject);
            $gForms = $this->gradeForms($ord, $stageId, (string) $grade->name_ar);
            $kForms = self::KIND_FORMS[$kind]['ar'] ?? [$kind];
            $kEn = self::KIND_FORMS[$kind]['en'] ?? [];

            $sg = ['subject_id' => $p->subject_id, 'grade_id' => $p->grade_id];
            $sgk = $sg + ['kind' => $kind];
            $sgkm = $sgk + ['semester_id' => $p->semester_id];

            $add((string) $p->title, 'exact_title', $sgkm + ['product_id' => $p->id]);

            foreach ([$sForms['full'], $sForms['bare']] as $s) {
                foreach (['full', 'short', 'def', 'digit'] as $gk) {
                    $add("{$s} {$gForms[$gk]}", 'subject_grade', $sg);
                }
            }
            foreach ($kForms as $k) {
                $add("{$k} {$sForms['bare']} {$gForms['short']}", 'kind_subject_grade', $sgk);
                $add("{$k} {$sForms['full']} {$gForms['full']}", 'kind_subject_grade', $sgk);
            }
            if ($semIdx !== false) {
                foreach (self::SEMESTER_FORMS[$semIdx] as $i => $m) {
                    $add("{$kForms[$i % count($kForms)]} {$sForms['bare']} {$gForms['short']} {$m}", 'long_tail', $sgkm);
                }
                /* فصلٌ آخر غير الموجود: الأفضل المتاح درجة 2 — يقيس التخفيف. */
                $other = ($semIdx + 1) % 3;
                $add("{$kForms[0]} {$sForms['bare']} {$gForms['short']} ".self::SEMESTER_FORMS[$other][0], 'over_specified', $sgk + ['semester_id' => $semesters[$other]]);
            }
            if ($gForms['abbr'] !== null) {
                $add("{$kForms[0]} {$sForms['bare']} {$gForms['abbr']}", 'abbreviation', $sgk);
                $add("{$sForms['bare']} {$gForms['abbr']}", 'abbreviation', $sg);
            }
            $add("{$sForms['bare']} {$gForms['fem']}", 'colloquial_grade', $sg);
            $add("{$kForms[0]} {$sForms['bare']} {$gForms['fem']}", 'colloquial_grade', $sgk);
            $add("{$sForms['bare']} {$gForms['indic']}", 'indic_digits', $sg);

            foreach ($sForms['colloquial'] as $c) {
                $add("{$c} {$gForms['short']}", 'colloquial_subject', $sg);
                $add("ابي {$kForms[0]} {$c} {$gForms['short']}", 'conversational', $sgk);
            }
            $add("ابغى {$kForms[0]} {$sForms['full']} للصف ".self::ORDINAL_DEF[$ord].' '.$this->stageWord($stageId, true), 'conversational', $sgk);
            $add("ممكن {$kForms[0]} {$sForms['bare']} {$gForms['short']}", 'conversational', $sgk);

            foreach ($sForms['en'] as $e) {
                $add("{$e} {$gForms['en']}", 'english', $sg);
                $add("{$e} {$gForms['short']}", 'mixed_language', $sg);
                foreach ($kEn as $ke) {
                    $add("{$e} {$ke} {$gForms['en']}", 'english', $sgk);
                }
            }
            foreach ($kEn as $ke) {
                $add("{$ke} {$sForms['bare']} {$gForms['short']}", 'mixed_language', $sgk);
            }

            foreach ($this->typos($sForms['bare']) as $t) {
                $add("{$t} {$gForms['short']}", 'typo', $sg);
            }
            /*
             * «مُستبعَدة» — أخطاءٌ في مواضع لم تُرَ عند بناء التصحيح (الحرف
             * الثاني وقبل الأخير)، وعلى الصفّ لا المادّة. تقيس التعميم لا الحفظ.
             */
            foreach ($this->typos($sForms['bare'], [1, -2]) as $t) {
                $add("{$kForms[0]} {$t} {$gForms['short']}", 'typo_holdout', $sgk);
            }
            foreach (self::GRADE_TYPOS as $wrong => $right) {
                if (str_contains($gForms['short'], $right)) {
                    $add($sForms['bare'].' '.str_replace($right, $wrong, $gForms['short']), 'typo', $sg);
                }
            }
            foreach (self::CURATED_TYPOS as $wrong => [$dim, $right]) {
                if ($dim === 'subject' && $right === $subject) {
                    $add("{$wrong} {$gForms['short']}", 'typo', $sg);
                }
                if ($dim === 'kind' && $right === $kind) {
                    $add("{$wrong} {$sForms['bare']} {$gForms['short']}", 'typo', $sgk);
                }
            }

            $add("{$kForms[0]} {$sForms['bare']} {$this->stageWord($stageId, false)}", 'kind_subject_stage', ['subject_id' => $p->subject_id, 'stage_id' => $stageId, 'kind' => $kind]);
            $add("{$kForms[0]} {$gForms['short']}", 'kind_grade', ['grade_id' => $p->grade_id, 'kind' => $kind]);
        }

        /* فئات عامّة: مادّة وحدها، صفّ وحده. */
        foreach ($products->pluck('subject_id')->unique() as $sid) {
            $f = $this->subjectForms((string) $subjects[$sid]->name_ar);
            foreach (array_unique([$f['full'], $f['bare'], ...$f['colloquial'], ...$f['en']]) as $s) {
                $add($s, 'subject_only', ['subject_id' => $sid]);
            }
        }
        foreach ($products->pluck('grade_id')->unique() as $gid) {
            $g = $grades[$gid];
            $f = $this->gradeForms($this->ordinalIndex($g, $grades), (int) $g->stage_id, (string) $g->name_ar);
            foreach (['full', 'short', 'digit', 'fem'] as $k) {
                $add($f[$k], 'grade_only', ['grade_id' => $gid]);
            }
        }

        /* مخزونٌ ناقص: مادّةٌ موجودة في صفٍّ لا منتجَ لها فيه. */
        foreach ($products->pluck('subject_id')->unique() as $sid) {
            $f = $this->subjectForms((string) $subjects[$sid]->name_ar);
            foreach ($grades as $g) {
                if (isset($covered[$sid.':'.$g->id]) || ($g->id % 3) !== ($sid % 3)) {
                    continue;
                }
                $gf = $this->gradeForms($this->ordinalIndex($g, $grades), (int) $g->stage_id, (string) $g->name_ar);
                $add("{$f['bare']} {$gf['short']}", 'missing_inventory', ['subject_id' => $sid, 'grade_id' => $g->id]);
            }
        }

        /*
         * البحث داخل الملفّات: أوّلُ سطرٍ قصير من نصّ الملفّ المستخرَج لا تظهر
         * كلماتُه في عنوان المنتج ولا وصفه — لا يصل إليه إلّا من قرأ الملفّ.
         * مشتقٌّ من الاستخراج نفسه، فيعمل على أيّ كتالوجٍ فيه ملفّاتٌ مقروءة.
         */
        $texts = ProductSearchProjector::extractedTextFor($products->pluck('id')->map(fn ($id): int => (int) $id)->all());
        /*
         * السطر يدلّ على منتجه وحده: «86 صفحة» و«أهداف الدرس» في ملفّاتٍ كثيرة،
         * وسؤالٌ عنهما ليس بحثًا عن منتجٍ بعينه. يُعدّ كلُّ سطرٍ في كم منتجٍ ورد.
         */
        $owners = [];
        foreach ($texts as $id => $text) {
            foreach (array_unique(array_map(fn (string $l): string => ArabicNormalizer::normalize($l), preg_split('/\R/u', (string) $text) ?: [])) as $l) {
                $owners[$l] = ($owners[$l] ?? 0) + 1;
            }
        }
        foreach ($products as $p) {
            $visible = ArabicNormalizer::normalize(implode(' ', [$p->title, $p->subtitle, $p->short_desc, $p->description]));
            foreach (preg_split('/\R/u', (string) ($texts[$p->id] ?? '')) ?: [] as $line) {
                $line = trim($line);
                $words = preg_split('/\s+/u', ArabicNormalizer::normalize($line)) ?: [];
                if ($line === '' || count($words) > 5 || ($owners[ArabicNormalizer::normalize($line)] ?? 0) !== 1 || array_filter($words, fn (string $w): bool => mb_strlen($w) > 2 && str_contains($visible, $w)) !== []) {
                    continue;
                }
                $add($line, 'content_only', ['product_id' => (int) $p->id]);
                break;
            }
        }

        foreach (self::NONSENSE as $n) {
            $add($n, 'nonsense', []);
        }

        return array_values($cases);
    }

    /**
     * @return array{full: string, bare: string, colloquial: list<string>, en: list<string>}
     */
    private function subjectForms(string $name): array
    {
        $bare = implode(' ', array_map(static fn (string $w): string => preg_replace('/^ال(?=\X{2,})/u', '', $w) ?? $w, explode(' ', $name)));

        return [
            'full' => $name,
            'bare' => $bare,
            'colloquial' => self::SUBJECT_FORMS[$name]['colloquial'] ?? [],
            'en' => self::SUBJECT_FORMS[$name]['en'] ?? [],
        ];
    }

    /**
     * @return array{full: string, short: string, def: string, digit: string, indic: string, fem: string, abbr: ?string, en: string}
     */
    private function gradeForms(int $ord, int $stageId, string $full): array
    {
        /* slug المرحلة هو كلمتُها كما تُكتب («ابتدائي»)؛ المعرّفات تختلف بين قاعدةٍ وأخرى. */
        $stage = $stageSlug = $this->stageSlugs[$stageId];

        return [
            'full' => $full,
            'short' => self::ORDINAL[$ord].' '.$stage,
            'def' => self::ORDINAL_DEF[$ord].' '.$stage,
            'digit' => ($ord + 1).' '.$stage,
            'indic' => 'الصف '.self::INDIC[$ord].' '.$stage,
            'fem' => self::ORDINAL_FEM[$ord].' '.$stage,
            'abbr' => $stageSlug === 'ابتدائي' ? null : self::ORDINAL[$ord].' '.self::STAGE_ABBR[$stageSlug],
            'en' => 'grade '.(self::STAGE_OFFSET[$stageSlug] + $ord + 1),
        ];
    }

    private function stageWord(int $stageId, bool $definite): string
    {
        return ($definite ? 'ال' : '').$this->stageSlugs[$stageId];
    }

    /**
     * @param  Collection<int, Grade>  $grades
     */
    private function ordinalIndex(Grade $grade, $grades): int
    {
        return $grades->where('stage_id', $grade->stage_id)->sortBy('id')->keys()->search($grade->id);
    }

    /**
     * أربعة أخطاء حتميّة لكلمةٍ طويلة: حذف، تكرار، تبديل متجاورين، جارُ لوحة.
     *
     * @param  list<int>|null  $positions  مواضع الخطأ (سالبٌ من النهاية)؛ الافتراضيّ المنتصف
     * @return list<string>
     */
    private function typos(string $phrase, ?array $positions = null): array
    {
        if ($positions !== null) {
            $out = [];
            foreach ($positions as $p) {
                $out = [...$out, ...$this->typosAt($phrase, $p)];
            }

            return array_values(array_unique($out));
        }

        return $this->typosAt($phrase, null);
    }

    /**
     * @return list<string>
     */
    private function typosAt(string $phrase, ?int $position): array
    {
        $words = explode(' ', $phrase);
        $longest = array_reduce($words, static fn (?string $c, string $w): string => $c === null || mb_strlen($w) > mb_strlen($c) ? $w : $c);
        $chars = mb_str_split((string) $longest);
        $n = count($chars);
        if ($n < 4) {
            return [];
        }
        $mid = $position === null ? intdiv($n, 2) : ($position < 0 ? $n + $position : $position);
        $mid = max(1, min($n - 1, $mid));
        $variants = [];

        $del = $chars;
        array_splice($del, $mid, 1);
        $variants[] = implode('', $del);

        $dup = $chars;
        array_splice($dup, $mid, 0, [$chars[$mid]]);
        $variants[] = implode('', $dup);

        $swap = $chars;
        [$swap[$mid - 1], $swap[$mid]] = [$swap[$mid], $swap[$mid - 1]];
        $variants[] = implode('', $swap);

        foreach (self::KEY_ROWS as $row) {
            $keys = mb_str_split($row);
            $at = array_search($chars[$mid], $keys, true);
            if ($at !== false) {
                $sub = $chars;
                $sub[$mid] = $keys[$at + 1] ?? $keys[$at - 1];
                $variants[] = implode('', $sub);
                break;
            }
        }

        return array_values(array_unique(array_map(
            static fn (string $v): string => str_replace((string) $longest, $v, $phrase),
            array_filter($variants, static fn (string $v): bool => $v !== $longest),
        )));
    }
}
