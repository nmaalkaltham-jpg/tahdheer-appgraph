<?php

namespace App\Domain\Search;

use App\Models\CurriculumAlias;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;

/**
 * Deterministic entity extraction (MIP §6.3, pipeline stage 5): longest-alias
 * first WHOLE-WORD matching of the normalized query against the seeded
 * curriculum_aliases vocabulary, plus the format-word and editable-phrase
 * dictionaries. Consumed tokens leave the lexical query; what remains is the
 * residual free-text search. No AI, no guessing — dictionary hits only.
 */
class EntityExtractor
{
    /** Format words evidenced in the legacy vocabulary (بوربوينت/pdf/وورد). */
    private const FORMAT_WORDS = [
        'بوربوينت' => 'PPTX', 'powerpoint' => 'PPTX', 'ppt' => 'PPTX', 'pptx' => 'PPTX',
        'pdf' => 'PDF',
        'وورد' => 'DOCX', 'word' => 'DOCX', 'docx' => 'DOCX',
    ];

    private const EDITABLE_PHRASE = 'قابل للتعديل';

    /**
     * Normalized bare-ordinal vocabulary mirroring CurriculumSeeder's grade
     * matrix (bare/definite/digit forms → ordinal index 0-5).
     *
     * @var array<string, int>
     */
    private const ORDINALS = [
        'اول' => 0, 'الاول' => 0, '1' => 0,
        'ثاني' => 1, 'الثاني' => 1, '2' => 1,
        'ثالث' => 2, 'الثالث' => 2, '3' => 2,
        'رابع' => 3, 'الرابع' => 3, '4' => 3,
        'خامس' => 4, 'الخامس' => 4, '5' => 4,
        'سادس' => 5, 'السادس' => 5, '6' => 5,
    ];

    /**
     * @return array{
     *   filters: array{stage: ?string, grade: ?string, subject: ?string, semester: ?string, kind: ?string, format: ?string},
     *   ids: array{stage_id: ?int, grade_id: ?int, subject_id: ?int, semester_id: ?int},
     *   editable: ?bool,
     *   residual: string,
     *   corrected: list<array{from: string, to: string}>,
     * }
     */
    public function extract(string $normalizedQuery): array
    {
        [$canonical, $corrections] = $this->canonicalize(trim($normalizedQuery));
        $original = ' '.$canonical.' ';
        $hay = $original;

        $ids = ['stage_id' => null, 'grade_id' => null, 'subject_id' => null, 'semester_id' => null];
        $filters = ['stage' => null, 'grade' => null, 'subject' => null, 'semester' => null, 'kind' => null, 'format' => null];
        $editable = null;

        // 1) Editable phrase — exact, consumed.
        if (str_contains($hay, ' '.self::EDITABLE_PHRASE.' ')) {
            $editable = true;
            $hay = str_replace(' '.self::EDITABLE_PHRASE.' ', ' ', $hay);
        }

        // 2) Curriculum aliases, longest phrase first, one hit per dimension.
        $kindId = null;
        foreach ($this->vocabulary() as $entry) {
            $dim = $entry['entity_type'];
            $taken = $dim === 'kind' ? $kindId !== null : $ids[$dim.'_id'] !== null;
            if ($taken) {
                continue;
            }
            $needle = ' '.$entry['normalized_alias'].' ';
            if (! str_contains($hay, $needle)) {
                continue;
            }
            /*
             * الصريحة تُستهلك بكلّ مواضعها كما كانت. والمشتقّة يُقرَّر فيها
             * موضعًا موضعًا: أوّلُ ظهورٍ ليس رأسَ إضافة يُستهلك **وحده**، فـ«تاريخ
             * المملكة تاريخ ثالث ثانوي» تُبقي الأولى نصًّا وتأخذ الثانية مادّة.
             */
            if ($entry['derived'] ?? false) {
                $at = $this->entityOccurrence($hay, $needle);
                if ($at === null) {
                    continue;
                }
                $hay = substr_replace($hay, ' ', $at, strlen($needle));
            } else {
                $hay = str_replace($needle, ' ', $hay);
            }
            if ($dim === 'kind') {
                $kindId = $entry['entity_id'];
            } else {
                $ids[$dim.'_id'] = $entry['entity_id'];
            }
        }

        // 3) Format words — detected against the ORIGINAL text so a kind alias
        // («عرض بوربوينت») consuming the word does not hide the format signal.
        foreach (self::FORMAT_WORDS as $word => $format) {
            if (str_contains($original, ' '.$word.' ')) {
                $filters['format'] = $format;
                $hay = str_replace(' '.$word.' ', ' ', $hay);
                break;
            }
        }

        // 4) Bare-ordinal grade fallback («سادس» alone): only when the ordinal
        // resolves to exactly ONE seeded grade — never a guess.
        if ($ids['grade_id'] === null) {
            [$gradeId, $hay] = $this->resolveBareOrdinal($hay, $ids['stage_id']);
            $ids['grade_id'] = $gradeId;
        }

        // Grade ⇒ stage inference, same rule the classifier applies.
        if ($ids['stage_id'] === null && $ids['grade_id'] !== null) {
            $ids['stage_id'] = Grade::query()->whereKey($ids['grade_id'])->value('stage_id');
        }

        $filters['stage'] = $ids['stage_id'] !== null ? Stage::query()->whereKey($ids['stage_id'])->value('slug') : null;
        $filters['grade'] = $ids['grade_id'] !== null ? Grade::query()->whereKey($ids['grade_id'])->value('slug') : null;
        $filters['subject'] = $ids['subject_id'] !== null ? Subject::query()->whereKey($ids['subject_id'])->value('slug') : null;
        $filters['semester'] = $ids['semester_id'] !== null ? Semester::query()->whereKey($ids['semester_id'])->value('slug') : null;
        $filters['kind'] = $kindId !== null ? Kind::query()->whereKey($kindId)->value('slug') : null;

        $residual = trim((string) preg_replace('/\s+/u', ' ', $hay));

        /*
         * تصحيحٌ لم يُستهلك كيانًا يُردّ إلى ما كتبه الزائر: الإملاء هنا
         * يخدم الكيانات وحدها، ولا يُعيد كتابة النصّ المعجميّ.
         */
        $kept = [];
        foreach ($corrections as $c) {
            if (str_contains(' '.$residual.' ', ' '.$c['to'].' ')) {
                $residual = trim(str_replace(' '.$c['to'].' ', ' '.$c['from'].' ', ' '.$residual.' '));
            } else {
                $kept[] = $c;
            }
        }

        return ['filters' => $filters, 'ids' => $ids, 'editable' => $editable, 'residual' => $residual, 'corrected' => $kept];
    }

    /**
     * يُكمل الكلمةَ الأخيرة وهي تُكتب: «رياضيات سا» ⇐ «رياضيات سادس».
     *
     * للإكمال التلقائيّ وحده. الكلمة الأخيرة ناقصةٌ بطبيعتها، فإن كانت بادئةً
     * لكلمةٍ **واحدة** من معجم الكيانات، وما قبلها كلماتُ منهج، أُكملت؛ وإن احتملت أكثر من كلمة أو
     * كانت معروفةً أصلًا تُركت كما هي. لا تخمين بين مرشّحين.
     */
    public function completeLast(string $normalizedQuery): string
    {
        $tokens = preg_split('/\s+/u', trim($normalizedQuery)) ?: [];
        $last = (string) end($tokens);
        if ($last === '' || ArabicNormalizer::length($last) < 2 || isset($this->words()[$last])) {
            return trim($normalizedQuery);
        }
        /*
         * والسياق شرط: ما قبل الأخيرة كلُّه كلماتُ منهج. «تذاكر طيران الرياض»
         * انتهت بكلمةٍ تامّة هي بادئةُ «الرياضيات» صدفةً — وما قبلها ليس منهجًا.
         */
        foreach (array_slice($tokens, 0, -1) as $before) {
            if (! isset($this->words()[$before])) {
                return trim($normalizedQuery);
            }
        }

        $completions = array_values(array_filter(
            array_keys($this->words()),
            static fn ($w): bool => str_starts_with((string) $w, $last),
        ));
        /*
         * أكثرُ من إكمال يُقبل حين يكون أقصرُها بادئةً لسائرها: «الابت» ⇐
         * «الابتدايي» (و«الابتداييه» امتدادُه). غير ذلك تخمينٌ فيُترك.
         */
        usort($completions, static fn ($a, $b): int => mb_strlen((string) $a) <=> mb_strlen((string) $b));
        $shortest = (string) ($completions[0] ?? '');
        foreach ($completions as $c) {
            if (! str_starts_with((string) $c, $shortest)) {
                return trim($normalizedQuery);
            }
        }
        if ($shortest === '') {
            return trim($normalizedQuery);
        }

        $tokens[count($tokens) - 1] = $shortest;

        return implode(' ', $tokens);
    }

    /**
     * صيغةٌ مشتقّة بلا «ال» يتبعها اسمٌ ليس من المنهج رأسُ إضافة، لا كيان.
     *
     * «توحيد المملكة» تاريخٌ لا «التوحيد»، و«تاريخ المملكة» عنوانُ درسٍ لا
     * مادّةٌ مجرّدة: في العربيّة يُحذف التعريف من المضاف تحديدًا. فالصيغة
     * المشتقّة تُقبل آخرَ السؤال أو قبل كلمةٍ منهجيّة («تاريخ ثالث ثانوي»)،
     * وتُترك نصًّا في غير ذلك. والصيغ الصريحة (الجدول والمعجم) لا يمسّها هذا.
     *
     * @return int|null بايتُ أوّل ظهورٍ يصلح كيانًا، أو null إن كان كلُّ ظهورٍ رأسَ إضافة
     */
    private function entityOccurrence(string $hay, string $needle): ?int
    {
        for ($at = strpos($hay, $needle); $at !== false; $at = strpos($hay, $needle, $at + 1)) {
            $after = trim(substr($hay, $at + strlen($needle)));
            $next = $after === '' ? null : explode(' ', $after)[0];
            if ($next === null || isset($this->words()[$next])) {
                return $at;
            }
        }

        return null;
    }

    /**
     * يُصحّح الكلمات المجهولة إلى كلمةٍ من معجم الكيانات — مغلقٍ وصغير.
     *
     * لماذا هنا لا في تصحيح المسار: الكيان مرشِّحٌ صلب، فـ«ريضايات سادس»
     * كانت تُنتج مرشِّح الصفّ وتُبقي «ريضايات» نصًّا لا يطابق شيئًا، ثمّ
     * يرفض التعافي — محقًّا — أن يُسقط كلمة الموضوع. النتيجة صفر. والتصحيح
     * على معجم الكيانات (مئات الكلمات) لا على الكتالوج (آلاف) أضيقُ خطأً.
     *
     * الحراسة: كلمةٌ ≥ ٤ أحرف بلا أرقام، مجهولة، وأقربُ جارٍ لها **واحد**
     * (التعادل بين كلمتين يُرفض). السماح ١، و٢ من ثمانية أحرف فصاعدًا.
     * كلمتان مجهولتان متجاورتان تُجرَّبان ملتصقتين أوّلًا («بور بونت»).
     *
     * @return array{0: string, 1: list<array{from: string, to: string}>}
     */
    private function canonicalize(string $text): array
    {
        $tokens = $text === '' ? [] : (preg_split('/\s+/u', $text) ?: []);
        $words = $this->words();

        /*
         * السياق شرط: كلمةٌ أخرى في السؤال كيانٌ تامّ بذاتها (مادّة، مرحلة،
         * رتبة، صيغة) — أو السؤال كلّه كلمةٌ واحدة. «سيارات رياضية للبيع»
         * لا سياقَ منهجيًّا فيها، و«رياضية» على حرفٍ من «رياضة» (التربية
         * البدنية)، فكانت تُصحَّح وتُعرض منتجات البدنية لإعلان سيّارات.
         */
        $standalone = $this->standalone();
        $hasContext = count($tokens) <= 1;
        foreach ($tokens as $t) {
            $hasContext = $hasContext || isset($standalone[$t]);
        }
        if (! $hasContext) {
            return [$text, []];
        }

        $out = [];
        $corrections = [];

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];

            /* الالتصاق يُجرَّب قبل حارس الطول: «بور» قصيرة، و«بوربونت» لا. */
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && $this->correctable($token.$next, $words) && ! isset($words[$token]) && ! isset($words[$next])) {
                $joined = $this->nearest($token.$next, $words);
                if ($joined !== null) {
                    $out[] = $joined;
                    $corrections[] = ['from' => $token.' '.$next, 'to' => $joined];
                    $i++;

                    continue;
                }
            }

            $orphan = $this->orphanFragmentTarget($tokens, $i);
            if ($orphan !== null) {
                $out[] = $orphan;
                $corrections[] = ['from' => $token, 'to' => $orphan];

                continue;
            }

            if (! $this->correctable($token, $words)) {
                $out[] = $token;

                continue;
            }

            $match = $this->nearest($token, $words);
            if ($match !== null) {
                $out[] = $match;
                $corrections[] = ['from' => $token, 'to' => $match];
            } else {
                $out[] = $token;
            }
        }

        return [implode(' ', $out), $corrections];
    }

    /**
     * كلمةٌ يتيمة: جزءُ عبارةِ كيانٍ لا تحضر عبارتُها، تُصحَّح إلى مادّةٍ تامّة.
     *
     * «علم رابع ابتدائي»: «علم» معروفةٌ لأنّها جزءُ «علم البيئة»، فكانت تُعفى من
     * التصحيح وتبقى نصًّا لا يطابق شيئًا في الصفّ الرابع — صفر نتائج. وهي على
     * حرفٍ من «علوم». الشروط كلّها لازمة، وكلٌّ منها يمنع خطأً بعينه:
     *
     *   - ليست كيانًا تامًّا وحدها، ولا تحضر أيُّ عبارةٍ هي جزؤها («علم البيئة»
     *     تبقى كما هي).
     *   - في السؤال كيانٌ تامٌّ آخر (صفّ، مرحلة، مادّة…) — سياقٌ منهجيّ؛ «علم»
     *     وحدها تبقى بحثًا نصّيًّا.
     *   - ما بعدها نهايةُ السؤال أو كلمةُ كيان: «علم النفس ثانوي» إضافةٌ إلى
     *     مجهول، فلا تُمسّ.
     *   - مادّةٌ تامّة **وحيدة** على حرفٍ واحد؛ التعادل يُرفض.
     *
     * @param  list<string>  $tokens
     */
    private function orphanFragmentTarget(array $tokens, int $i): ?string
    {
        $token = $tokens[$i];
        $words = $this->words();
        $standalone = $this->standalone();
        if (! isset($words[$token]) || isset($standalone[$token]) || preg_match('/\d/u', $token)) {
            return null;
        }

        $others = $tokens;
        unset($others[$i]);
        $context = false;
        foreach ($others as $t) {
            $context = $context || isset($standalone[$t]);
        }
        $next = $tokens[$i + 1] ?? null;
        if (! $context || ($next !== null && ! isset($words[$next]))) {
            return null;
        }

        $text = ' '.implode(' ', $tokens).' ';
        $subjects = [];
        foreach ($this->vocabulary() as $entry) {
            $alias = $entry['normalized_alias'];
            if (str_contains($alias, ' ')) {
                if (in_array($token, explode(' ', $alias), true) && str_contains($text, ' '.$alias.' ')) {
                    return null;
                }

                continue;
            }
            if ($entry['entity_type'] === 'subject' && ArabicNormalizer::distance($token, $alias, 1) === 1) {
                $subjects[$alias] = true;
            }
        }

        return count($subjects) === 1 ? (string) array_key_first($subjects) : null;
    }

    /**
     * @param  array<string, true>  $words
     */
    private function correctable(string $token, array $words): bool
    {
        return ! isset($words[$token])
            && ArabicNormalizer::length($token) >= 4
            && ! preg_match('/\d/u', $token);
    }

    /**
     * أقرب كلمةٍ وحيدة ضمن السماح، أو null عند الغياب أو التعادل.
     *
     * @param  array<string, true>  $words
     */
    private function nearest(string $token, array $words): ?string
    {
        $tolerance = ArabicNormalizer::length($token) >= 8 ? 2 : 1;
        $bestDistance = $tolerance + 1;
        $nearest = [];

        foreach ($words as $word => $_) {
            $d = ArabicNormalizer::distance($token, (string) $word, $tolerance);
            if ($d < $bestDistance) {
                [$bestDistance, $nearest] = [$d, [(string) $word]];
            } elseif ($d === $bestDistance && $d <= $tolerance) {
                $nearest[] = (string) $word;
            }
        }

        if (count($nearest) === 1) {
            return $nearest[0];
        }

        /*
         * التعادل يُحسم لكيانٍ تامّ وحده: «علزم» على حرفٍ من «علوم» (مادّة)
         * ومن «علم» (جزءُ «علم البيئة» لا يكفي وحده). تصحيحٌ إلى جزءِ عبارةٍ
         * لا يُنتج كيانًا، فلا يُرجَّح. وتعادلُ كيانين تامّين يبقى مرفوضًا.
         */
        $standalone = array_values(array_filter($nearest, fn (string $w): bool => isset($this->standalone()[$w])));

        return count($standalone) === 1 ? $standalone[0] : null;
    }

    /** @var array<string, true>|null */
    private ?array $words = null;

    /**
     * كلماتٌ هي كيانٌ تامّ وحدها: صيغةٌ من كلمةٍ واحدة، أو رتبة، أو صيغة ملفّ.
     *
     * @return array<string, true>
     */
    private function standalone(): array
    {
        $out = [];
        foreach (array_column($this->vocabulary(), 'normalized_alias') as $alias) {
            if (! str_contains((string) $alias, ' ')) {
                $out[(string) $alias] = true;
            }
        }
        foreach ([...array_keys(self::ORDINALS), ...array_keys(self::FORMAT_WORDS)] as $w) {
            $out[(string) $w] = true;
        }

        return $out;
    }

    /**
     * كلّ كلمةٍ تظهر في صيغةِ كيان — هدفُ التصحيح الوحيد.
     *
     * @return array<string, true>
     */
    private function words(): array
    {
        if ($this->words !== null) {
            return $this->words;
        }
        $words = [];
        $phrases = [...array_column($this->vocabulary(), 'normalized_alias'), ...array_keys(self::FORMAT_WORDS), ...array_map('strval', array_keys(self::ORDINALS)), self::EDITABLE_PHRASE];
        foreach ($phrases as $phrase) {
            foreach (preg_split('/\s+/u', (string) $phrase) ?: [] as $w) {
                if ($w !== '') {
                    $words[$w] = true;
                }
            }
        }

        return $this->words = $words;
    }

    /**
     * @return array{0: ?int, 1: string} [grade_id, haystack with the token consumed]
     */
    private function resolveBareOrdinal(string $hay, ?int $stageId): array
    {
        foreach (preg_split('/\s+/u', trim($hay)) ?: [] as $token) {
            if (! isset(self::ORDINALS[$token])) {
                continue;
            }

            $index = self::ORDINALS[$token];
            $candidates = Grade::query()
                ->when($stageId !== null, fn ($q) => $q->where('stage_id', $stageId))
                ->orderBy('position')
                ->get(['id', 'slug', 'stage_id'])
                ->filter(function (Grade $grade) use ($index) {
                    $bare = ArabicNormalizer::normalize(explode('-', $grade->slug)[0]);

                    return isset(self::ORDINALS[$bare]) && self::ORDINALS[$bare] === $index;
                });

            if ($candidates->count() === 1) {
                return [$candidates->first()->id, str_replace(' '.$token.' ', ' ', $hay)];
            }
        }

        return [null, $hay];
    }

    /** @var array<int, array{entity_type: string, entity_id: int, normalized_alias: string}>|null */
    private ?array $vocabulary = null;

    /**
     * Whole vocabulary sorted longest-first so «الدراسات الإسلامية» beats
     * «إسلامية» and «عرض بوربوينت» beats «بوربوينت». Cached per instance
     * (one instance per request), never across requests or tests.
     *
     * @return array<int, array{entity_type: string, entity_id: int, normalized_alias: string}>
     */
    private function vocabulary(): array
    {
        if ($this->vocabulary !== null) {
            return $this->vocabulary;
        }

        /* الجدول أوّلًا: صيغةٌ فيه تعلو أيَّ صيغةٍ مشتقّةٍ أو مُضمَّنة تُطابقها. */
        $entries = CurriculumAlias::query()
            ->get(['entity_type', 'entity_id', 'normalized_alias'])
            ->map(fn (CurriculumAlias $a) => [
                'entity_type' => $a->entity_type,
                'entity_id' => (int) $a->entity_id,
                'normalized_alias' => trim($a->normalized_alias),
            ])
            ->all();

        $ids = $this->slugIds();
        foreach (SearchLexicon::entries() as [$type, $slug, $alias]) {
            if (isset($ids[$type][$slug])) {
                $entries[] = ['entity_type' => $type, 'entity_id' => $ids[$type][$slug], 'normalized_alias' => $alias];
            }
        }

        /*
         * «التاريخ» في الجدول و«تاريخ» في البحث: أداةُ التعريف لا تغيّر
         * الكيان. كلُّ صيغةٍ تُولِّد نظيرتها بلا «ال» — وتُهمَل إن كانت صيغةً
         * لكيانٍ آخر (الصريح يعلو المشتقّ).
         */
        foreach ($entries as $e) {
            $bare = implode(' ', array_map(
                static fn (string $w): string => ArabicNormalizer::length($w) >= 5 && str_starts_with($w, 'ال') ? mb_substr($w, 2) : $w,
                explode(' ', $e['normalized_alias']),
            ));
            if ($bare !== $e['normalized_alias']) {
                $entries[] = ['normalized_alias' => $bare, 'derived' => true] + $e;
            }
        }

        $seen = [];
        $unique = [];
        foreach ($entries as $e) {
            if ($e['normalized_alias'] === '' || isset($seen[$e['normalized_alias']])) {
                continue;
            }
            $seen[$e['normalized_alias']] = true;
            $unique[] = $e;
        }

        return $this->vocabulary = collect($unique)
            ->sortByDesc(fn (array $a) => ArabicNormalizer::length($a['normalized_alias']))
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, int>> type ⇒ slug ⇒ id
     */
    private function slugIds(): array
    {
        return [
            'subject' => Subject::query()->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all(),
            'grade' => Grade::query()->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all(),
            'stage' => Stage::query()->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all(),
            'semester' => Semester::query()->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all(),
            'kind' => Kind::query()->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
