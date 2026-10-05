<?php

namespace App\Domain\Search\Recovery;

use App\Domain\Search\SearchResult;

/**
 * تعافٍ حتميٌّ من أسئلةٍ لا يجيبها الكتالوج كما طُرحت.
 *
 * ─── العطب الذي بُني له ───
 *
 * «بوربوينت علوم اول متوسط» يعود بصفر، و«بوربوينت علوم» و«بوربوينت اول متوسط»
 * كلاهما يعود بنتيجة. والسبب ليس كلمةً مجهولة — كلُّ كلمةٍ هنا يعرفها
 * الكتالوج ويحوّلها المستخرِجُ إلى مرشِّح، حتى يصير المتنُ **فارغًا** وتصير
 * النيّةُ كلُّها قيودًا مُهيكَلة. فتخفيفُ الكلمات المجهولة لا يجد ما يخفّفه،
 * والعطب أنّ **اجتماع** قيودٍ كلُّها معروفة قد يكون خاليًا.
 *
 * فالصنف هذا يخفّف القيودَ لا الكلمات، بترتيبٍ ثابت، وبحارسين.
 *
 * ─── لماذا لا نُعيد ما وجدنا كيفما كان ───
 *
 * «بوربوينت علوم اول متوسط» لو خُفّف إلى «بوربوينت» وحدها لأعاد كلَّ عرضٍ في
 * المتجر: إجابةٌ عن سؤالٍ لم يُطرح، ومصدرُها كلمةٌ عامّة لا تحمل موضوعًا.
 * فحارسان يمنعان ذلك:
 *
 *   ١) **بقاء الموضوع**: لا تعافيَ ما لم يبقَ قيدٌ يحمل الموضوع — مادّةٌ أو
 *      كلمةُ محتوى. الصيغةُ والنوعُ والصفُّ والمرحلة لا تحمل موضوعًا وحدها.
 *   ٢) **أرضيّةُ التغطية**: الوزن الباقي ÷ الوزن الكلّي ≥ MIN_COVERAGE، وهي
 *      مشتقّةٌ لا مُختارة: وزنُ المادّة ÷ مجموع الأوزان كلّها.
 *
 * ─── وخاصّةٌ تجعل البحث رخيصًا ───
 *
 * السلّم تراكميّ، فالتغطية لا ترتفع أبدًا كلّما نزلنا درجة. فأوّلُ درجةٍ تعود
 * بنتيجةٍ هي **أعلى** الدرجات تغطيةً بين ما يعود بنتيجة؛ وإن سقطت عند الحارس
 * سقط ما بعدها يقينًا. فنتوقّف عندها بلا أن نجرّب البقيّة.
 */
final class QueryRecovery
{
    /** سقوفٌ صلبة: لا انفجارَ مرشَّحات ولا انفجارَ نداءات. */
    public const MAX_CANDIDATE_QUERIES = 8;

    public const MAX_BACKEND_SEARCHES = 8;

    public const MAX_RELAXATION_DEPTH = 5;

    /**
     * أرضيّةُ التغطية — مشتقّة لا مُختارة.
     *
     * وزنُ المادّة (١٠٠) ÷ مجموع أوزان الأبعاد كلّها (٢٥٠) = ٠٫٤٠. فالقاعدة
     * تقرأ هكذا: «بقاءُ المادّة وحدها يقف على الأرضيّة تمامًا فيمرّ؛ وما هو
     * أقلُّ من المادّة وحدها يسقط». ولو غُيّر وزنٌ واحد وجب أن يُغيَّر هذا
     * معه — ولذلك يحرسه اختبار.
     */
    public const MIN_COVERAGE = 0.40;

    /**
     * الأبعادُ وأوزانُ نيّتها. الوزن يقيس «كم يخسر السؤال إن سقط هذا القيد».
     *
     * @var array<string, array{role: string, weight: int, topic: bool}>
     */
    private const DIMENSIONS = [
        'subject' => ['role' => 'SUBJECT', 'weight' => 100, 'topic' => true],
        'grade' => ['role' => 'GRADE', 'weight' => 60, 'topic' => false],
        'stage' => ['role' => 'STAGE', 'weight' => 40, 'topic' => false],
        'semester' => ['role' => 'SEMESTER', 'weight' => 25, 'topic' => false],
        'format' => ['role' => 'FORMAT', 'weight' => 15, 'topic' => false],
        'kind' => ['role' => 'PRODUCT_TYPE', 'weight' => 10, 'topic' => false],
    ];

    /**
     * ترتيبُ الإسقاط — وهو **ليس** ترتيبَ الأوزان.
     *
     * الصفُّ (٦٠) يسبق المرحلة (٤٠) رغم ثِقَله، لأنّ إسقاط المرحلة مع بقاء
     * الصفّ لا معنى له: الصفُّ يتضمّن مرحلته. فالتوسيع يمشي «أوّل متوسط» ←
     * «متوسط» ← بلا قيدِ مرحلة، وهو توسيعٌ يفهمه معلّم. والمادّةُ ليست في
     * القائمة أصلًا: هي الموضوع، وإسقاطها يُلغي السؤال لا يوسّعه.
     *
     * @var list<string>
     */
    private const DROP_ORDER = ['kind', 'format', 'semester', 'grade', 'stage'];

    private const W_CONTENT_TOKEN = 50;

    private const W_UNKNOWN_TOKEN = 20;

    private const W_FILLER_TOKEN = 5;

    /**
     * كلماتُ مخاطبةٍ لا كلماتُ كتالوج.
     *
     * §٢ يوصي بتفضيل أدلّة الكتالوج على القواميس المكتوبة، وهذه هي الحالة
     * التي يستحيل فيها ذلك: «ابغي» و«ممكن» لا تظهر في عنوانِ منتجٍ ولا في
     * مرادفٍ مُدار، فلا دليلَ في الكتالوج يصنّفها. وهي مطبَّعة سلفًا (أ ← ا)
     * لأنّ ما تُقارَن به مطبَّع.
     *
     * ولا تُسقَط بمجرّد كونها هنا: تُسقَط لأنّها ضمن المجموعة القابلة
     * للإسقاط، وبعد أن يكون السؤال كلُّه قد عاد بصفر.
     *
     * @var list<string>
     */
    private const FILLER_WORDS = [
        'ابغي', 'ابي', 'بغيت', 'اريد', 'عايز', 'ابحث', 'ممكن', 'لو', 'سمحت',
        'فضلك', 'هل', 'يوجد', 'عندكم', 'وش', 'ايش', 'للصف', 'مادة', 'درس',
    ];

    /**
     * @param  callable(string): bool  $knows  أيعرف الكتالوج هذه الكلمة؟
     * @param  callable(string, array<string, string|null>): SearchResult  $search
     * @param  list<string>  $pinned  أبعادٌ اختارها الزائر بنفسه — لا تُخفَّف
     */
    public function recover(RecoveryBase $base, ?RecoveryBase $typoBase, callable $knows, callable $search, array $pinned = []): RecoveryOutcome
    {
        if ($this->classify($base, $knows) === []) {
            return RecoveryOutcome::none(0, 'لا قيودَ تُخفَّف — السؤال فارغ.');
        }

        $candidates = $this->candidates($base, $typoBase, $knows, $pinned);

        $searches = 0;
        $tried = [];

        foreach ($candidates as $candidate) {
            if ($searches >= self::MAX_BACKEND_SEARCHES) {
                return RecoveryOutcome::none($searches, 'بلغ سقفُ نداءات المحرّك قبل أن يُعثر على مرشَّحٍ مقبول.');
            }

            $retained = array_values(array_filter(
                $candidate['constraints'],
                static fn (array $c): bool => ! in_array($c['key'], $candidate['removed'], true),
            ));

            if ($retained === []) {
                continue;
            }

            $query = $this->queryOf($retained);
            $filters = $this->filtersOf($candidate['base'], $retained);
            $tried[] = $this->label($query, $filters);

            $result = $search($query, $filters);
            $searches++;

            if ($result->total < 1) {
                continue;
            }

            /*
             * وصلنا إلى درجةٍ تعود بنتيجة. والتغطية لا ترتفع بعدها، فالحكمُ
             * هنا حكمٌ على السلّم كلّه: إن سقطت عند الحارس فما دونها أسوأ.
             */
            $candidateTotal = array_sum(array_column($candidate['constraints'], 'weight'));
            $retainedWeight = array_sum(array_column($retained, 'weight'));
            $coverage = $candidateTotal > 0 ? $retainedWeight / $candidateTotal : 0.0;

            /*
             * الحارس يمنع «تصنيعَ» نتيجةٍ بعد أن يُرمى الموضوع — لا يمنع
             * سؤالًا لا موضوعَ فيه أصلًا.
             *
             * قيس الفرق: «ابغي اوراق عمل للصف الثالث الابتدايي» ليس فيه قيدٌ
             * يحمل موضوعًا (نوعٌ وصفٌّ ومرحلة وكلمتا مخاطبة)، وإسقاطُ كلمتَي
             * المخاطبة يُبقي السؤال كما هو: أوراق عمل · ثالث ابتدائي. أمّا
             * «بوربوينت اول متوسط الجهاز الهضمي» ففيه موضوعٌ صريح، وإسقاطُه
             * يحوّل السؤال إلى «أيّ عرضٍ لأوّل متوسط» — وهو ما يُرفض.
             *
             * فأوّلُ صياغةٍ اشترطت **بقاء** موضوع، فأسقطت الحالةَ الأولى معها
             * (١ ← ٠ نتيجة). والشرط الصحيح: إن وُجد موضوعٌ فليبقَ منه شيء.
             */
            $topicExisted = array_filter(
                $candidate['constraints'],
                static fn (array $c): bool => $c['topic'],
            ) !== [];
            $topicRetained = array_filter($retained, static fn (array $c): bool => $c['topic']) !== [];

            if ($topicExisted && ! $topicRetained) {
                return RecoveryOutcome::none(
                    $searches,
                    'رُفض: لم يبقَ قيدٌ يحمل الموضوع — نتيجةٌ من كلماتٍ عامّة وحدها.',
                );
            }

            if ($coverage < self::MIN_COVERAGE) {
                return RecoveryOutcome::none(
                    $searches,
                    sprintf('رُفض: التغطية %.2f دون الأرضيّة %.2f.', $coverage, self::MIN_COVERAGE),
                );
            }

            $depth = count($candidate['removed']);
            $confidence = max(0.0, $coverage * (1 - 0.1 * $depth) * ($candidate['typo'] ? 0.9 : 1.0));

            return new RecoveryOutcome(
                mode: $this->mode($candidate),
                result: $result,
                removed: $candidate['removed'],
                retained: array_column($retained, 'key'),
                candidates: $tried,
                coverage: $coverage,
                confidence: $confidence,
                depth: $depth,
                searches: $searches,
                reason: 'تعافٍ مقبول: بقي الموضوع والتغطية فوق الأرضيّة.',
                legacyRelaxed: $this->legacy($candidate),
                queryUsed: $query,
                filtersUsed: $filters,
            );
        }

        return RecoveryOutcome::none($searches, 'لا مرشَّحَ أعاد نتيجة.');
    }

    /**
     * تصنيفُ القيود: أبعادٌ مُستخرَجة وكلماتٌ باقية في المتن.
     *
     * @param  callable(string): bool  $knows
     * @return list<array{key: string, role: string, weight: int, topic: bool, token: ?string}>
     */
    private function classify(RecoveryBase $base, callable $knows): array
    {
        $constraints = [];

        foreach (self::DIMENSIONS as $key => $meta) {
            $value = $base->filters[$key] ?? null;
            if ($value !== null && $value !== '') {
                $constraints[] = [
                    'key' => $key,
                    'role' => $meta['role'],
                    'weight' => $meta['weight'],
                    'topic' => $meta['topic'],
                    'token' => null,
                ];
            }
        }

        foreach ($base->tokens() as $token) {
            if (in_array($token, self::FILLER_WORDS, true)) {
                $role = 'FILLER';
                $weight = self::W_FILLER_TOKEN;
                $topic = false;
            } elseif ($knows($token)) {
                $role = 'CONTENT_TYPE';
                $weight = self::W_CONTENT_TOKEN;
                $topic = true;
            } else {
                $role = 'UNKNOWN';
                $weight = self::W_UNKNOWN_TOKEN;
                /*
                 * كلمةٌ مجهولةٌ تحمل موضوعًا رغم جهلنا بها — «الجهاز الهضمي».
                 * وعدُّها موضوعًا هو ما يجعل إسقاطَها يُسقِط الموضوع معها،
                 * فيرفض الحارسُ نتيجةً مبنيّةً على «بوربوينت» وحدها.
                 */
                $topic = true;
            }

            $constraints[] = [
                'key' => 'token:'.$token,
                'role' => $role,
                'weight' => $weight,
                'topic' => $topic,
                'token' => $token,
            ];
        }

        return $constraints;
    }

    /**
     * سلّمُ المرشَّحات — ثابتُ الترتيب، محدودُ الطول.
     *
     * @param  callable(string): bool  $knows
     * @param  list<string>  $pinned
     * @return list<array{base: RecoveryBase, constraints: list<array<string, mixed>>, removed: list<string>, families: list<string>, typo: bool}>
     */
    private function candidates(RecoveryBase $base, ?RecoveryBase $typoBase, callable $knows, array $pinned = []): array
    {
        $candidates = [];

        /*
         * ١) الإملاء أوّلًا: لا يُسقط قيدًا بل يُصلح واحدًا، فتغطيتُه كاملة.
         *    ويُعاد استخراجُ الكيانات من النصّ المُصحَّح في المسار الداعي،
         *    فتصحيحُ «الريضيات» يصل إلى مرشِّح المادّة لا إلى المتن وحده.
         */
        if ($typoBase !== null) {
            $typoConstraints = $this->classify($typoBase, $knows);
            if ($typoConstraints !== []) {
                $candidates[] = [
                    'base' => $typoBase,
                    'constraints' => $typoConstraints,
                    'removed' => [],
                    'families' => [RecoveryOutcome::TYPO],
                    'typo' => true,
                ];
            }
        }

        /*
         * ٢) وما بعد الإملاء يُبنى **على النصّ المُصحَّح** حين وُجد.
         *
         * السلسلة في §٩ مراتبُ يغذّي بعضُها بعضًا: تصحيحُ الإملاء مرحلةٌ غيرُ
         * فاقدة — تُصلح حرفًا ولا تُسقط نيّة — فما بعدها يجب أن يعمل على
         * ثمرتها. ولولا ذلك لَما وُجد صنف MIXED أصلًا: سؤالٌ فيه خطأٌ إملائيّ
         * **و**إفراطٌ في التحديد كان يُجرَّب مرّةً بإملائه المُصحَّح ثمّ يُترك.
         */
        $effective = $typoBase ?? $base;
        $constraints = $this->classify($effective, $knows);
        $carried = $typoBase !== null ? [RecoveryOutcome::TYPO] : [];
        $isTypo = $typoBase !== null;

        if ($constraints === []) {
            return array_slice($candidates, 0, self::MAX_CANDIDATE_QUERIES);
        }

        /*
         * ٣) الكلماتُ التي لا يعرفها الكتالوج، وكلماتُ المخاطبة معها. تُسقَط
         *    دفعةً واحدة: إسقاطُها واحدةً واحدة يضاعف النداءات بلا أن يغيّر
         *    الحكم، فكلُّها بلا دليلٍ في الكتالوج سواء.
         */
        $droppableTokens = array_values(array_map(
            static fn (array $c): string => $c['key'],
            array_filter(
                $constraints,
                static fn (array $c): bool => in_array($c['role'], ['FILLER', 'UNKNOWN'], true),
            ),
        ));

        $removed = [];
        $families = $carried;

        if ($droppableTokens !== []) {
            $removed = $droppableTokens;
            $families = [...$carried, RecoveryOutcome::UNKNOWN_TOKEN];
            $candidates[] = [
                'base' => $effective,
                'constraints' => $constraints,
                'removed' => $removed,
                'families' => $families,
                'typo' => $isTypo,
            ];
        }

        /*
         * ٤) سلّمُ الأبعاد، تراكميًّا وبالترتيب الثابت. ويُبنى فوق ما سبق:
         *    من أسقط كلمةً مجهولة لا يستعيدها في الدرجة التالية.
         */
        $present = array_column($constraints, 'key');
        $depth = 0;

        foreach (self::DROP_ORDER as $dimension) {
            if (! in_array($dimension, $present, true)) {
                continue;
            }

            /*
             * ما نقره الزائر بيده لا يُخفَّف.
             *
             * كُشف باختبارٍ خصميّ: `?q=علوم&grade=أول-متوسط` كان يعود بعلومٍ
             * ابتدائيّة بعد إسقاط الصفّ، **ورقاقةُ «أول متوسط» ما تزال
             * معروضة**. فالواجهة تقول شيئًا والنتائج شيئًا آخر — وهو أسوأ من
             * صفرٍ صريح. والقاعدة هنا هي قاعدةُ المسار نفسه: النقرةُ الصريحة
             * إشارةٌ أقوى من تخمينٍ عن كلمة، فأولى ألّا تُنقض.
             */
            if (in_array($dimension, $pinned, true)) {
                continue;
            }
            if ($depth >= self::MAX_RELAXATION_DEPTH || count($candidates) >= self::MAX_CANDIDATE_QUERIES) {
                break;
            }

            $removed[] = $dimension;
            $depth++;
            $candidates[] = [
                'base' => $effective,
                'constraints' => $constraints,
                'removed' => $removed,
                'families' => [...$families, RecoveryOutcome::OVER_SPECIFIED],
                'typo' => $isTypo,
            ];
        }

        /*
         * ٥) آخرُ درجة: إسقاطُ كلماتِ المحتوى الزائدة مع إبقاء أولاها. وهي
         *    آخرُ ما يُجرَّب لأنّ كلمةَ المحتوى تحمل الموضوع (§٣).
         */
        $contentKeys = array_values(array_map(
            static fn (array $c): string => $c['key'],
            array_filter($constraints, static fn (array $c): bool => $c['role'] === 'CONTENT_TYPE'),
        ));

        if (count($contentKeys) > 1 && count($candidates) < self::MAX_CANDIDATE_QUERIES) {
            $candidates[] = [
                'base' => $effective,
                'constraints' => $constraints,
                'removed' => [...$removed, ...array_slice($contentKeys, 1)],
                'families' => [...$families, RecoveryOutcome::OVER_SPECIFIED, RecoveryOutcome::PARTIAL],
                'typo' => $isTypo,
            ];
        }

        return array_slice($candidates, 0, self::MAX_CANDIDATE_QUERIES);
    }

    /** @param array{families: list<string>, typo: bool} $candidate */
    private function mode(array $candidate): string
    {
        $families = array_values(array_unique($candidate['families']));

        return count($families) === 1 ? $families[0] : RecoveryOutcome::MIXED;
    }

    /**
     * صيغةُ `meta.relaxed` القديمة — عقدٌ حيٌّ مع المتجر، يُوسَّع ولا يُكسَر.
     *
     * @param  array{removed: list<string>, typo: bool}  $candidate
     * @return list<string>
     */
    private function legacy(array $candidate): array
    {
        $out = $candidate['typo'] ? ['typo'] : [];

        foreach ($candidate['removed'] as $key) {
            $out[] = $key;
        }

        return $out;
    }

    /** @param list<array{token: ?string}> $retained */
    private function queryOf(array $retained): string
    {
        $tokens = array_values(array_filter(array_column($retained, 'token')));

        return implode(' ', $tokens);
    }

    /**
     * @param  list<array{key: string}>  $retained
     * @return array<string, string|null>
     */
    private function filtersOf(RecoveryBase $base, array $retained): array
    {
        $keep = array_column($retained, 'key');
        $filters = $base->filters;

        foreach (array_keys(self::DIMENSIONS) as $dimension) {
            if (! in_array($dimension, $keep, true)) {
                $filters[$dimension] = null;
            }
        }

        return $filters;
    }

    /** @param array<string, string|null> $filters */
    private function label(string $query, array $filters): string
    {
        $active = array_keys(array_filter($filters, static fn ($v): bool => $v !== null && $v !== ''));
        sort($active);

        return trim($query.' ['.implode(',', $active).']');
    }
}
