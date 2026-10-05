<?php

namespace App\Domain\Search;

use App\Domain\Ai\Search\AiQueryUnderstanding;
use App\Domain\Privacy\CredentialPaths;
use App\Domain\Search\Contracts\SearchProvider;
use App\Domain\Search\Models\SearchSynonym;
use App\Domain\Search\Recovery\QueryRecovery;
use App\Domain\Search\Recovery\RecoveryBase;
use App\Domain\Search\Recovery\RecoveryOutcome;
use App\Models\Product;
use App\Models\SearchQuery;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic query pipeline (MIP §6.3) — every stage has a fallback and
 * the lexical core survives every engine failure:
 *
 *   sanitize → normalize → entity extraction → synonym expansion
 *     → provider search (engine, else PG) → correction candidate
 *     (suggest, NEVER silently replace) → zero-result recovery.
 *
 * Every run is logged to search_queries with its zero_result flag.
 */
class QueryPipeline
{
    public const MAX_CHARS = 120;

    public const MAX_TOKENS = 6;

    public const SYNONYM_CAP = 6;

    /** Result totals below this are «weak» — worth a did-you-mean candidate. */
    public const WEAK_THRESHOLD = 3;

    /**
     * أقصى نسبةٍ من الكلمة يجوز أن تُعاد كتابتها في التصحيح الإملائيّ.
     *
     * الربعُ حدٌّ يجعل سماحَ حرفين حكرًا على كلمةٍ من ثمانية أحرف فصاعدًا،
     * وسماحَ حرفٍ واحد لما دونها. وهو ما يفصل «تحظير ← تحضير» (حرفٌ من
     * خمسة، ٢٠٪) عن «فضاييه ← نهاييه» (حرفان من ستّة، ٣٣٪).
     */
    private const MAX_REWRITE_RATIO = 0.25;

    public const SORTS = ['relevance', 'bestselling', 'newest', 'price_asc', 'price_desc'];

    public function __construct(
        private readonly EntityExtractor $extractor,
        private readonly ProviderResolver $resolver,
        /*
         * الفهم بالذكاء تابعٌ اختياريّ. يُحلّ من الحاوية دائمًا، لكنّه يردّ
         * `null` فورًا حين تكون الراية مطفأة — فلا فرعَ شرطيّ هنا ولا مسارٌ
         * ثانٍ يتباعد.
         */
        private readonly AiQueryUnderstanding $understanding,
        /*
         * سلّمُ التعافي. خارج هذا الصنف لأنّه منطقُ قرارٍ خالص: يصنّف قيودًا
         * ويولّد مرشَّحاتٍ ويحكم بحارسين، ولا يعرف مزوّدًا ولا قاعدةَ بيانات.
         * فيُختبر وحده بلا هيكلٍ ولا فهرس.
         */
        private readonly QueryRecovery $recovery,
        private readonly RelatedResults $related = new RelatedResults,
    ) {}

    /**
     * @return array{
     *   raw: string, normalized: string,
     *   filters: array<string, ?string>, editable: ?bool,
     *   request: SearchRequest, result: SearchResult,
     *   related: list<array{reason: string, label: string, items: Collection<int, Product>}>,
     *   correction: ?array{suggestion: string, display: string, applied: bool},
     *   recovery: ?array{popular: array<int, array{query: string, count: int}>, browse: array<int, array{label: string, url: string}>},
     *   zeroResult: bool, sort: string, page: int,
     * }
     */
    /**
     * @param  array<string,string|null>  $explicitFilters  Facet values chosen by the
     *                                                      visitor (stage/subject/semester/kind). Browse surfaces — a stage page,
     *                                                      a facet click — carry no text at all; there the query is empty and these
     *                                                      filters ARE the request. They also override anything the extractor read
     *                                                      out of the text, because an explicit click is a stronger signal than a
     *                                                      guess about a word.
     */
    /**
     * @param  array{source?: string, variant?: ?string}  $context  مصدرُ البحث (search · browse)
     *                                                              وتنويعةُ التجربة — يُسجَّلان مع الحدث.
     */
    public function run(string $raw, string $typeTab = 'all', string $sort = 'relevance', int $page = 1, array $explicitFilters = [], array $context = []): array
    {
        $t0 = hrtime(true);
        $out = $this->resolve($raw, $typeTab, $sort, $page, $explicitFilters, withRelated: true);
        $latencyMs = (int) round((hrtime(true) - $t0) / 1e6);

        /*
         * المعرّف يعود للعميل ليربط النقرة اللاحقة بهذا الاستعلام بعينه —
         * بدونه يبقى عمودا clicked_product_id/position فارغين إلى الأبد ولا
         * يُحسب CTR أبدًا (وقد بقيا كذلك منذ إنشائهما).
         */
        $logged = SearchQuery::query()->create([
            'raw_query' => mb_substr($out['raw'], 0, 500),
            'normalized_query' => mb_substr($out['normalized'], 0, 500),
            'results_count' => $out['result']->total,
            'zero_result' => $out['zeroResult'],
            'provider' => $out['trace']['provider'] ?? 'unknown',
            'degraded' => $out['trace']['degraded'],
            'degraded_reason' => $out['trace']['reason'],
            'latency_ms' => $latencyMs,
            /*
             * ما يلزم لإعادة تفسير الحدث لاحقًا — ولا شيءَ عن صاحبه. النتائجُ
             * بترتيبها (الصفحة المعروضة وحدها) هي مرجعُ الظهور والرتبة
             * (`SearchTracking`)، والصفحةُ وحجمُها مع المرشِّحات لإزاحة الرتبة.
             */
            'filters' => array_filter([
                ...array_filter($out['filters'], static fn ($v): bool => $v !== null),
                ...array_filter($explicitFilters, static fn ($v): bool => $v !== null && $v !== ''),
                'type' => $typeTab !== 'all' ? $typeTab : null,
                'sort' => $sort !== 'relevance' ? $sort : null,
                'page' => $page,
                'perPage' => $out['request']->perPage,
            ], static fn ($v): bool => $v !== null),
            'result_ids' => $out['result']->items->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'recovery_mode' => $this->recoveryLabel($out),
            'source' => in_array($context['source'] ?? null, ['search', 'browse', 'autocomplete'], true) ? $context['source'] : 'search',
            'variant' => isset($context['variant']) ? mb_substr((string) $context['variant'], 0, 32) : null,
            'created_at' => now(),
        ]);

        return [
            'queryId' => (int) $logged->id,
            /*
             * المعرّف تسلسلي فقابل للتعداد: بدون توقيع يستطيع غريب أن يحقن
             * «نقرات» في استعلامات زوّار آخرين — وبقاعدة أوّل نقرة تفوز يسمّم
             * CTR وموضع النقرة تسميمًا دائمًا. الرمز يثبت أن حامله تلقّى هذه
             * الاستجابة بعينها.
             */
            'clickToken' => Analytics\SearchTracking::token((int) $logged->id),
            ...$out,
        ];
    }

    /**
     * أيُّ مسار تعافٍ أنتج الجواب — للقياس: التصحيحُ والإسقاطُ والتخفيفُ والرجوعُ النصّيّ.
     * null = المطابقة التامّة كفت.
     *
     * @param  array<string, mixed>  $out
     */
    private function recoveryLabel(array $out): ?string
    {
        $mode = $out['recoveryMeta']['mode'] ?? null;
        if (is_string($mode) && $mode !== 'NONE') {
            return mb_strtolower($mode);
        }
        if (in_array('ai', $out['relaxed'], true)) {
            return 'ai';
        }
        if (in_array('typo', $out['relaxed'], true)) {
            return 'entity_typo';
        }
        if (($out['request']->lenient ?? []) !== []) {
            return 'lexical_fallback';
        }

        return null;
    }

    /**
     * تشغيلٌ جافّ: ما سيُجيب به البحث الآن، بلا سجلٍّ ولا طبقةٍ ثانية.
     * يخدم التشخيص (`ZeroResultDiagnosis`) وأدوات القياس.
     *
     * @return array<string, mixed>
     */
    public function dryRun(string $raw): array
    {
        return $this->resolve($raw, 'all', 'relevance', 1, [], withRelated: false);
    }

    /**
     * أقربُ كلمةٍ يعرفها الكتالوج (عناوين، معجم المنهج، المرادفات) ضمن
     * سماح التصحيح، أو null. للتشخيص: «هذه الكلمة قريبةٌ من تلك — أضف مرادفًا».
     */
    public function nearestKnownWord(string $token): ?string
    {
        /* السماحُ نفسُه في `correctTokens`: ٢ من ثمانية أحرف، وإلّا ١. */
        $length = ArabicNormalizer::length($token);
        $tolerance = ($length > 0 && 2 / $length <= self::MAX_REWRITE_RATIO) ? 2 : 1;
        $best = null;
        $bestDistance = $tolerance + 1;
        foreach ($this->vocabularyTokens() as $word => $_) {
            $d = ArabicNormalizer::distance($token, (string) $word, $tolerance);
            if ($d > 0 && $d < $bestDistance) {
                [$best, $bestDistance] = [(string) $word, $d];
            }
        }

        return $best;
    }

    /**
     * الفهمُ والاسترجاعُ كاملين بلا أثر: لا سجلَّ في `search_queries`.
     * يخدم `run()` (الذي يسجّل) و`suggest()` (الذي لا يسجّل كلَّ ضغطة مفتاح).
     *
     * @param  array<string, string|null>  $explicitFilters
     * @return array<string, mixed>
     */
    private function resolve(string $raw, string $typeTab, string $sort, int $page, array $explicitFilters, bool $withRelated): array
    {
        $this->trace = ['provider' => null, 'degraded' => false, 'reason' => null];

        // 1) Sanitize: strip control/format chars, cap length and token count.
        $raw = trim((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $raw));
        // A pasted order link is a key, not a search: nothing downstream (logs, search_queries) sees it.
        $raw = CredentialPaths::redactText($raw);
        $raw = mb_substr($raw, 0, self::MAX_CHARS);
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'relevance';
        $page = max(1, $page);

        // 2) Normalize (ArabicNormalizer — same folding as the index side).
        $normalized = ArabicNormalizer::normalize($raw);

        // 3) Entity extraction — consumed tokens leave the lexical query. The
        // legacy 6-token bound applies to the residual LEXICAL query (what
        // reaches the engine); entity phrases are structured filters, not
        // lexical work, so «عرض بوربوينت علوم سادس الفصل الأول قابل للتعديل»
        // still extracts completely.
        $extraction = $this->extractor->extract($normalized);
        $residualTokens = $extraction['residual'] === '' ? [] : (preg_split('/\s+/u', $extraction['residual']) ?: []);
        if (count($residualTokens) > self::MAX_TOKENS) {
            $extraction['residual'] = implode(' ', array_slice($residualTokens, 0, self::MAX_TOKENS));
        }

        // 4) Synonym expansion (active rows only, capped +6 tokens).
        $expanded = $this->expandSynonyms($extraction['residual']);

        foreach ($explicitFilters as $key => $value) {
            if ($value !== null && $value !== '') {
                $extraction['filters'][$key] = $value;
            }
        }

        $request = new SearchRequest(
            query: $extraction['residual'],
            rawQuery: $raw,
            filters: $extraction['filters'],
            editable: $extraction['editable'],
            expandedTokens: $expanded,
            typeTab: $typeTab,
            sort: $sort,
            page: $page,
        );

        // 5) Provider retrieval, PG fallback on any engine failure.
        $result = $this->searchWithFallback($request);

        $relaxed = [];

        /*
         * ٥ب) التعافي الحتميّ — مسارٌ واحد لا ثلاثة.
         *
         * كان هنا ثلاثةُ مخفِّفاتٍ متنافسة: إسقاطُ النوع حين تجتمع صيغةٌ
         * ونوع، وإسقاطُ الكلمات المجهولة، وتخفيفُ مرشِّحات الذكاء. وكلٌّ
         * منها يعرف حالتَه وحدها، فسقط بينها صنفٌ كامل من الأسئلة: ما كانت
         * كلُّ كلماته معروفةً والاجتماعُ وحده خالٍ — «بوربوينت علوم اول
         * متوسط». المتن فيها فارغ (استُهلك كلُّه مرشِّحات) فلا يجد مخفِّفُ
         * الكلمات ما يخفّفه، ومرشِّحا النوع والصيغة ليسا سببَ الفراغ.
         *
         * فصار الأمرُ سلّمًا واحدًا يملكه `QueryRecovery`: إملاءٌ ثمّ كلماتٌ
         * مجهولة ثمّ أبعادٌ مُفرِطة، بترتيبٍ ثابتٍ وسقوفٍ صلبة وحارسَي موضوعٍ
         * وتغطية.
         */
        $recoveryOutcome = null;
        $correctedFull = null;
        if ($result->total === 0) {
            $typoBase = null;
            $correctedFull = $this->correctTokens($normalized);
            if ($correctedFull !== null && $correctedFull !== $normalized) {
                $typoExtraction = $this->extractor->extract($correctedFull);
                $typoBase = new RecoveryBase(
                    $typoExtraction['residual'],
                    $this->withExplicit($typoExtraction['filters'], $explicitFilters),
                );
            }

            $recoveryOutcome = $this->recovery->recover(
                base: new RecoveryBase($extraction['residual'], $extraction['filters']),
                typoBase: $typoBase,
                knows: fn (string $token): bool => $this->catalogKnows($token),
                search: fn (string $query, array $filters): SearchResult => $this->searchWithFallback(new SearchRequest(
                    query: $query,
                    rawQuery: $raw,
                    filters: $filters,
                    editable: $extraction['editable'],
                    expandedTokens: $this->expandSynonyms($query),
                    typeTab: $typeTab,
                    sort: $sort,
                    page: $page,
                )),
                // ما اختاره الزائر صراحةً محميٌّ من التخفيف.
                pinned: array_keys(array_filter(
                    $explicitFilters,
                    static fn ($v): bool => $v !== null && $v !== '',
                )),
            );

            if ($recoveryOutcome->recovered()) {
                $result = $recoveryOutcome->result;
                $request = new SearchRequest(
                    query: $recoveryOutcome->queryUsed,
                    rawQuery: $raw,
                    filters: $recoveryOutcome->filtersUsed,
                    editable: $extraction['editable'],
                    expandedTokens: $expanded,
                    typeTab: $typeTab,
                    sort: $sort,
                    page: $page,
                );
                $relaxed = $recoveryOutcome->legacyRelaxed;
                Log::info('search.recovered', [
                    'mode' => $recoveryOutcome->mode,
                    'removed' => $recoveryOutcome->removed,
                    'coverage' => round($recoveryOutcome->coverage, 3),
                    'raw' => mb_substr($raw, 0, 120),
                ]);
            }
        }

        /*
         * ٥ب٢) الكلمات نصًّا — حين تُفرغ المرشِّحاتُ المشتقّةُ المجموعة.
         *
         * الاستخراج يحوّل «تاريخ» إلى مرشِّح المادّة، والمرشِّح صلب: منتجٌ
         * غيرُ مصنّف عنوانُه «ملزمة قديمة تاريخ» يسقط، ولا يُسقط التعافي
         * بُعدَ المادّة أبدًا (هو حاملُ القصد). فقبل الاستسلام يُسأل الفهرس
         * بكلمات الزائر نصًّا، والمرشِّحاتُ المشتقّة **ليّنة**: تُدخل غيرَ
         * المصنّف ولا تُدخل المناقض — «أول ابتدائي» لا تُعيد منتجَ السادس
         * لأنّ «تحضير» و«ابتدائي» في عنوانه. والصريحةُ تبقى صلبة.
         */
        $derived = array_keys(array_filter($extraction['filters'], static fn ($v): bool => $v !== null));
        $derived = array_values(array_diff($derived, array_keys(array_filter($explicitFilters, static fn ($v): bool => $v !== null && $v !== ''))));
        if ($result->total === 0 && $derived !== [] && $normalized !== '') {
            $lexical = new SearchRequest(
                query: $normalized,
                rawQuery: $raw,
                filters: $extraction['filters'],
                editable: $extraction['editable'],
                expandedTokens: $this->expandSynonyms($normalized),
                typeTab: $typeTab,
                sort: $sort,
                page: $page,
                lenient: $derived,
            );
            $lexicalResult = $this->searchWithFallback($lexical);
            if ($lexicalResult->total > 0) {
                /*
                 * لا يُعلَن بعدٌ «متجاهَلًا»: لا نتيجةَ تناقض ما سمّاه الزائر،
                 * والمُضاف منتجاتٌ لم تُصنَّف فيه ويطابقها نصُّه.
                 */
                $result = $lexicalResult;
                $request = $lexical;
                Log::info('search.lexical_fallback', ['lenient' => $derived, 'total' => $lexicalResult->total]);
            }
        }

        /*
         * 5c) فهمٌ بالذكاء — بعد أن يعجز الحتميّ كلّه.
         *
         * ─── لماذا هنا لا قبل ───
         *
         * كلّ ما سبق حتميٌّ وسريع: تطبيعٌ واستخراجُ كياناتٍ وتوسيعُ مرادفاتٍ
         * وتخفيفُ مرشِّح. والنموذج لا يُنادى إلّا حين تعود كلّها بصفر — واحدٌ
         * من تسعين بحثًا في هذه القاعدة. فالمسار السعيد لا يدفع مليًّا واحدًا،
         * وهو ما تفرضه قاعدة «لا نموذج لغةٍ في مسار كلّ بحث».
         *
         * ─── وما يُعاد ليس نتائج ───
         *
         * النموذج يردّ مرشِّحاتٍ مُهيكَلة، والبحث يُعاد بها إلى المحرّك نفسه.
         * فالنتائج تخرج من الفهرس كما تخرج دائمًا: لا منتجَ يُختلق، ولا
         * معرّفَ يُخترع — النموذج لا يرى الكتالوج أصلًا.
         *
         * ─── والتوسيع يُعلَن ───
         *
         * يُضاف إلى `relaxed` كما يُضاف إسقاط النوع، فلا نتائج «مفهومة»
         * تتنكّر مطابقةً حرفيّة للاستعلام.
         */
        if ($result->total === 0) {
            $understood = $this->understanding->understand($raw);

            if ($understood !== null && $understood['filters'] !== []) {
                /*
                 * ─── المرشِّحات وحدها حين لا تصحيح ───
                 *
                 * قيس هذا: «math worksheets grade one» فُهم فهمًا صحيحًا
                 * فأعطى مادّةً وصفًّا ومرحلة، ثمّ عاد البحث بصفرٍ ثانٍ — لأنّ
                 * النصّ الإنجليزيّ بقي في الاستعلام المعجميّ ولا يطابق عنوانًا
                 * عربيًّا واحدًا. فالمرشِّحات تضيّق والنصّ يُفرّغ.
                 *
                 * وهذه ليست حالةً جديدة: المسار يدعمها أصلًا في التصفّح
                 * بالأوجه، حيث لا نصّ والمرشِّحات **هي** الطلب.
                 */
                /*
                 * ─── المرشِّح الصريح يعلو تخمين النموذج ───
                 *
                 * كُشف في مراجعةٍ خصميّة: كان `$understood['filters'] +
                 * $extraction['filters']`، واتّحاد المصفوفات في PHP يُبقي
                 * **اليسار**. فتخمينُ النموذج كان يُزيح ما نقره الزائر بيده،
                 * بينما الملفّ نفسه ينصّ على أنّ «النقرة الصريحة إشارةٌ أقوى
                 * من تخمينٍ عن كلمة».
                 * في هذا المستودع بعينه.
                 *
                 * فالنموذج يملأ الخانات الفارغة وحدها.
                 */
                $merged = $extraction['filters'];

                foreach ($understood['filters'] as $key => $value) {
                    if (($merged[$key] ?? null) === null) {
                        $merged[$key] = $value;
                    }
                }

                $aiRequest = new SearchRequest(
                    query: $understood['corrected'] ?? '',
                    rawQuery: $raw,
                    filters: $merged,
                    editable: $extraction['editable'],
                    expandedTokens: $expanded,
                    typeTab: $typeTab,
                    sort: $sort,
                    page: $page,
                );

                /*
                 * تخفيفٌ تدريجيّ على مرشِّحات الذكاء — بالمبدأ نفسه في ٥ب.
                 *
                 * النموذج قد يصيب المادّة ويخطئ الصفّ، فتعود المجموعة كلّها
                 * فارغة بسبب مرشِّحٍ واحد. والأضيق يُسقَط أوّلًا: الصفّ ثمّ
                 * الفصل، وتبقى المادّة والمرحلة — وهما ما يحمل القصد.
                 *
                 * وكلّ محاولةٍ نداءٌ للفهرس لا للنموذج: عشرة أجزاء من الألف،
                 * لا ثلاث ثوانٍ. فالتخفيف رخيصٌ والاستدلال هو الغالي.
                 */
                foreach ([[], ['grade'], ['grade', 'semester']] as $drop) {
                    /*
                     * التخفيف يُسقط ما **أضافه النموذج** وحده. وكان يُعاد
                     * بناؤه باتّحادٍ مع مرشِّحات الاستخراج، فيُعيد المفتاح
                     * المُسقَط من الطرف الآخر ولا يخفّف شيئًا — ثلاث محاولاتٍ
                     * متطابقة.
                     */
                    $filters = $merged;

                    foreach ($drop as $key) {
                        if (($extraction['filters'][$key] ?? null) === null) {
                            unset($filters[$key]);
                        }
                    }

                    $filters = array_filter($filters, static fn ($v): bool => $v !== null);

                    if ($filters === []) {
                        break;
                    }

                    $attempt = new SearchRequest(
                        query: $aiRequest->query,
                        rawQuery: $raw,
                        filters: $filters,
                        editable: $extraction['editable'],
                        expandedTokens: $expanded,
                        typeTab: $typeTab,
                        sort: $sort,
                        page: $page,
                    );

                    $aiResult = $this->searchWithFallback($attempt);

                    if ($aiResult->total > 0) {
                        $result = $aiResult;
                        $request = $attempt;
                        $relaxed[] = 'ai';
                        Log::info('search.ai_recovered', [
                            'filters' => array_keys($filters),
                            'dropped' => $drop,
                            'total' => $aiResult->total,
                        ]);

                        break;
                    }
                }
            }
        }

        // 6) Correction candidate on weak/zero results — suggest only, never
        // replace, and only when the suggestion itself yields ≥1 result.
        $correction = null;
        if ($result->total < self::WEAK_THRESHOLD) {
            $correction = $this->correctionCandidate($request);
        }

        /*
         * تصحيحٌ **طُبِّق** يجب أن يُقال، لا أن يُستنتج من غيابه.
         *
         * حين ينقذ التصحيحُ الإملائيّ سؤالًا، يصير الطلبُ المُنفَّذ هو
         * المُصحَّح، فيعود `correctionCandidate` بلا شيء (كلماتُه كلُّها
         * صحيحة) — فيرى الزائر نتائجَ كلمةٍ لم يكتبها بلا سطرٍ يقول لماذا.
         * فتُعلَن هنا صراحةً، ويميّزها `applied` عن الاقتراح المعروض.
         */
        if ($recoveryOutcome !== null
            && $correctedFull !== null
            && in_array($recoveryOutcome->mode, [RecoveryOutcome::TYPO, RecoveryOutcome::MIXED], true)) {
            $correction = [
                'suggestion' => $correctedFull,
                'display' => 'عُرضت نتائج ‹'.$correctedFull.'›',
                'applied' => true,
            ];
        } elseif ($correction !== null) {
            $correction['applied'] = false;
        }

        /*
         * وتصحيحُ الكيانات يُقال كذلك: «ريضايات سادس» فُهمت «رياضيات سادس»
         * في الاستخراج نفسه، فلم تمرّ بالتعافي ولا تُعلنها الفروع أعلاه.
         */
        if ($correction === null && $result->total > 0 && ($extraction['corrected'] ?? []) !== []) {
            $shown = ' '.$normalized.' ';
            foreach ($extraction['corrected'] as $c) {
                $shown = str_replace(' '.$c['from'].' ', ' '.$c['to'].' ', $shown);
            }
            $correction = ['suggestion' => trim($shown), 'display' => 'عُرضت نتائج ‹'.trim($shown).'›', 'applied' => true];
            if (! in_array('typo', $relaxed, true)) {
                $relaxed[] = 'typo';
            }
        }

        /*
         * 6ب) الطبقة الثانية — «قريبٌ ممّا تبحث عنه» (`RelatedResults`).
         * لا تُبنى فوق إجابةٍ مُخفَّفة: التخفيف وسّع السؤال أصلًا، وطبقةٌ
         * ثانية فوقه توسيعٌ لا يُعلَن.
         */
        /*
         * ما يمنع الطبقة هو توسيعُ **بُعد** (صفٍّ أُسقط، أو فهمُ الذكاء).
         * التصحيحُ الإملائيّ وإسقاطُ كلمةٍ مجهولة («ابي») يُعيدان قراءة
         * السؤال ولا يوسّعان قصده المُهيكَل.
         */
        $widened = array_filter($relaxed, static fn (string $k): bool => $k !== 'typo' && ! str_starts_with($k, 'token:'));
        $related = $withRelated && $widened === []
            ? $this->related->for(
                $request,
                $result,
                fn (SearchRequest $r): SearchResult => $this->searchWithFallback($r),
                array_keys(array_filter($explicitFilters, static fn ($v): bool => $v !== null && $v !== '')),
            )
            : [];

        $result = $this->disjunctiveFacets($request, $result, $explicitFilters);

        // 7) Zero-result recovery: real popular queries + seeded browse links.
        $zeroResult = $result->total === 0;
        $recovery = $zeroResult ? $this->recovery() : null;

        return [
            'relaxed' => $relaxed,
            /*
             * تشخيصُ التعافي كاملًا — نمطُه وما أُسقط وتغطيتُه وثقتُه وعددُ
             * نداءاته. `relaxed` وحده كان يقول «خُفّف» ولا يقول بمَ ولا بأيّ
             * ثمن، فيستحيل أن يُقاس أثرُه أو يُراجَع قرارُه.
             */
            'recoveryMeta' => $recoveryOutcome?->toArray() ?? RecoveryOutcome::none(0, 'المطابقةُ التامّة كفت.')->toArray(),
            'raw' => $raw,
            'normalized' => $normalized,
            'filters' => $extraction['filters'],
            'editable' => $extraction['editable'],
            'request' => $request,
            'result' => $result,
            'related' => $related,
            'correction' => $correction,
            'recovery' => $recovery,
            'zeroResult' => $zeroResult,
            'sort' => $sort,
            'page' => $page,
            'trace' => $this->trace,
        ];
    }

    public function suggest(string $raw, int $limit = 3): Collection
    {
        $normalized = ArabicNormalizer::normalize(mb_substr(trim($raw), 0, self::MAX_CHARS));

        if (ArabicNormalizer::length($normalized) < 2) {
            return collect();
        }

        /*
         * الاقتراح يفهم ما يفهمه البحث — الكيانات والإملاء والإنجليزيّة
         * والترتيب نفسه — بعد إكمال الكلمة الأخيرة وهي تُكتب. وبلا سجلّ:
         * ضغطةُ المفتاح ليست بحثًا، وتسجيلُها يُغرق «الأكثر بحثًا» بأنصاف
         * كلمات. قيس قبله: ٩٪ فقط من الأسئلة الناقصة أظهرت منتجها.
         *
         * مطابقةُ بداية العنوان تبقى آخرَ درجة: عنوانٌ يُكتب حرفًا بحرف
         * («ملزمة مرا») قد لا يفهمه البحث بعد، ويعرفه العنوان.
         */
        try {
            $understood = $this->resolve($this->extractor->completeLast($normalized), 'all', 'relevance', 1, [], withRelated: false);
            if ($understood['result']->total > 0) {
                return $understood['result']->items->take($limit)->values();
            }
        } catch (\Throwable $e) {
            Log::warning('search.suggest_understanding_failed', ['reason' => $e->getMessage()]);
        }

        try {
            return $this->resolver->resolve()->suggest($normalized, $limit);
        } catch (\Throwable $e) {
            Log::warning('search.suggest_fallback', ['reason' => $e->getMessage()]);

            return $this->resolver->fallback()->suggest($normalized, $limit);
        }
    }

    /** أبعادٌ يختار الزائر فيها أكثر من قيمة («أو» داخل البُعد). */
    public const MULTI_SELECT = ['stage', 'grade', 'subject', 'semester', 'kind'];

    /**
     * توزيعاتٌ مفصولة: عددُ البُعد المختار يُحسب **بلا** مرشِّحه هو.
     *
     * بلا هذا يُظهر بُعدُ المادّة بعد اختيار «العلوم» قيمةً واحدة — «العلوم» —
     * فلا يستطيع الزائر إضافة «الرياضيات»، ويصير الاختيار المتعدّد مستحيلًا.
     * والعدّ عبر `searchWithFallback` نفسه، فالمحرّكان يُجيبان بالمنطق نفسه
     * (التكافؤ). نداءٌ إضافيّ لكلّ بُعدٍ اختاره الزائر صراحةً فقط؛ البحث
     * النصّيّ بلا اختيار لا يدفع شيئًا.
     *
     * @param  array<string, mixed>  $explicit
     */
    private function disjunctiveFacets(SearchRequest $request, SearchResult $result, array $explicit): SearchResult
    {
        if ($result->total === 0) {
            return $result;
        }

        $counts = $result->facetCounts;
        $changed = false;
        foreach (self::MULTI_SELECT as $dim) {
            $chosen = $explicit[$dim] ?? null;
            if ($chosen === null || $chosen === '' || $chosen === [] || $request->values($dim) === []) {
                continue;
            }
            $filters = $request->filters;
            $filters[$dim] = null;
            $sibling = $this->searchWithFallback(new SearchRequest(
                query: $request->query,
                rawQuery: $request->rawQuery,
                filters: $filters,
                editable: $request->editable,
                expandedTokens: $request->expandedTokens,
                typeTab: $request->typeTab,
                sort: $request->sort,
                page: 1,
                perPage: 1,
                lenient: $request->lenient,
            ));
            $counts[$dim] = $sibling->facetCounts[$dim] ?? [];
            $changed = true;
        }

        return $changed
            ? new SearchResult($result->items, $result->total, $result->totalUnfiltered, $result->formatCounts, $counts)
            : $result;
    }

    private function searchWithFallback(SearchRequest $request): SearchResult
    {
        $provider = $this->resolver->resolve();

        /* المُعدُّ Meilisearch وأجاب الاحتياطيّ: فحصُ الصحّة ساقط — تدهورٌ يُسجَّل. */
        if ($this->resolver->configuredName() === 'meilisearch' && $provider instanceof Providers\PgFallbackProvider) {
            $this->traceDegraded('unhealthy');
        }

        try {
            $result = $provider->search($request);
            $this->traceServed($provider);

            return $result;
        } catch (\Throwable $e) {
            if ($provider instanceof Providers\PgFallbackProvider) {
                throw $e;
            }
            Log::warning('search.provider_fallback', ['from' => 'meilisearch', 'to' => 'pg', 'reason' => $e->getMessage()]);
            $this->traceDegraded(class_basename($e));
            $fallback = $this->resolver->fallback();
            $result = $fallback->search($request);
            $this->traceServed($fallback);

            return $result;
        }
    }

    /**
     * تتبّعُ ما خدم هذا السؤال: مزوّدُ الاسترجاع الأوّل، وأيُّ تدهورٍ في أيّ
     * استرجاعٍ لاحق (تعافٍ، طبقةٌ ثانية) وسببُه الأوّل. يُصفَّر في `resolve()`
     * ويُكتب في `search_queries` — منه تقرأ اللوحةُ «ما خدم فعلًا».
     *
     * @var array{provider: ?string, degraded: bool, reason: ?string}
     */
    private array $trace = ['provider' => null, 'degraded' => false, 'reason' => null];

    private function traceServed(SearchProvider $provider): void
    {
        $this->trace['provider'] ??= $provider instanceof Providers\MeilisearchProvider ? 'meilisearch' : 'pg';
    }

    private function traceDegraded(string $reason): void
    {
        $this->trace['degraded'] = true;
        $this->trace['reason'] ??= mb_substr($reason, 0, 64);
    }

    /** @return array<int, string> */
    private function expandSynonyms(string $residual): array
    {
        if ($residual === '') {
            return [];
        }

        $tokens = preg_split('/\s+/u', $residual) ?: [];
        $expanded = [];

        $rows = SearchSynonym::query()->active()
            ->where(fn ($q) => $q->whereIn('term', $tokens)
                ->orWhere(fn ($qq) => $qq->whereIn('synonym', $tokens)->where('direction', 'two_way')))
            ->get(['term', 'synonym', 'direction']);

        foreach ($rows as $row) {
            $addition = in_array($row->term, $tokens, true) ? $row->synonym : $row->term;
            if (! in_array($addition, $tokens, true) && ! in_array($addition, $expanded, true)) {
                $expanded[] = $addition;
            }
            if (count($expanded) >= self::SYNONYM_CAP) {
                break;
            }
        }

        return $expanded;
    }

    /**
     * Did-you-mean over REAL vocabulary only: published catalog title tokens,
     * curriculum aliases and active synonym terms, matched per token with the
     * bounded UTF-8 Levenshtein of ArabicNormalizer. The candidate must itself
     * produce at least one result, or nothing is suggested.
     *
     * @return array{suggestion: string, display: string}|null
     */
    private function correctionCandidate(SearchRequest $request): ?array
    {
        if ($request->query === '') {
            return null;
        }

        $suggestion = $this->correctTokens($request->query);

        if ($suggestion === null) {
            return null;
        }

        // The suggestion is only honest if it actually finds something.
        $probe = new SearchRequest(
            query: $suggestion,
            rawQuery: $suggestion,
            filters: $request->filters,
            editable: $request->editable,
            expandedTokens: $this->expandSynonyms($suggestion),
        );

        try {
            if ($this->searchWithFallback($probe)->total < 1) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return [
            'suggestion' => $suggestion,
            'display' => 'هل تقصد ‹'.$suggestion.'›؟',
        ];
    }

    /**
     * تصحيحُ الكلمات على مفرداتٍ حقيقيّة — بلا بحثٍ ولا أثرٍ جانبيّ.
     *
     * استُخرجت من `correctionCandidate` ليناديها **اثنان**: الاقتراحُ الذي
     * يُعرض («هل تقصد…»)، وسلّمُ التعافي الذي يجرّب النصَّ المُصحَّح فعلًا.
     * ولولا الاستخراج لكان في المستودع حلقتا تصحيحٍ تتباعدان.
     *
     * وكلمةٌ موجودةٌ في المفردات لا تُمسّ (§٩): «بوربوينت» صحيحةٌ فلا يُبحث
     * لها عن أقرب جار.
     *
     * @return string|null النصُّ المُصحَّح، أو null إن لم تتغيّر كلمة
     */
    private function correctTokens(string $query): ?string
    {
        if (trim($query) === '') {
            return null;
        }

        $vocabulary = $this->vocabularyTokens();
        if ($vocabulary === []) {
            return null;
        }

        $tokens = preg_split('/\s+/u', trim($query)) ?: [];
        $replaced = false;
        $corrected = [];

        foreach ($tokens as $token) {
            if (isset($vocabulary[$token])) {
                $corrected[] = $token;

                continue;
            }

            /*
             * السماحُ مشتقٌّ من نسبة إعادة الكتابة لا مُختار.
             *
             * كان «الطولُ ≥ ٦ ⇒ سماحُ ٢»، أي أنّ كلمةً من ستّة أحرف يجوز أن
             * يُعاد ثلثُها. وقد أنتج ذلك خطأً موجبًا حقيقيًّا: «قطط فضائية»
             * يُطبَّع «قطط فضاييه»، و«فضاييه» على مسافة ٢ من «نهاييه» (من
             * «ملزمة مراجعة نهائية») — فعاد سؤالٌ عن قططٍ فضائيّة بملزمتَي
             * مراجعة. فالسماحُ ٢ لا يُمنح إلّا حين يبقى التغيير ضمن الربع.
             */
            $length = ArabicNormalizer::length($token);
            $tolerance = ($length > 0 && 2 / $length <= self::MAX_REWRITE_RATIO) ? 2 : 1;
            $best = null;
            $bestDistance = $tolerance + 1;
            foreach ($vocabulary as $word => $_) {
                $distance = ArabicNormalizer::distance($token, (string) $word, $tolerance);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = (string) $word;
                    if ($distance === 1) {
                        break;
                    }
                }
            }

            if ($best !== null) {
                $corrected[] = $best;
                $replaced = true;
            } else {
                $corrected[] = $token;
            }
        }

        if (! $replaced) {
            return null;
        }

        /*
         * ولا يُوثَق بتصحيحٍ يترك خلفه كلمةً مجهولة.
         *
         * «قطط نهاييه» صحّحت كلمةً وتركت «قطط» كما هي، فصار المعروضُ نتائجَ
         * نصفِ سؤالٍ مُخترَع. والدليلُ الكتالوجيّ هو الفيصل: التصحيح يُقبل
         * حين يجعل السؤال **كلَّه** معروفًا؛ وإن بقيت كلمةٌ مجهولة فالأداةُ
         * الصحيحة هي إسقاطُ المجهول (UNKNOWN_TOKEN) لا إعادةُ كتابة جارها.
         */
        foreach ($corrected as $token) {
            if (! isset($vocabulary[$token])) {
                return null;
            }
        }

        return implode(' ', $corrected);
    }

    /**
     * المرشِّحُ الصريح يعلو الاستخراج — نفس القاعدة المطبَّقة على مسار النصّ
     * الأصليّ، مُطبَّقةً على الاستخراج المُصحَّح إملائيًّا كي لا يفقد نقرةَ
     * الزائر لمجرّد أنّه سلك فرعًا ثانيًا.
     *
     * @param  array<string, string|null>  $filters
     * @param  array<string, string|null>  $explicit
     * @return array<string, string|null>
     */
    private function withExplicit(array $filters, array $explicit): array
    {
        foreach ($explicit as $key => $value) {
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /** @return array<string, true> normalized vocabulary tokens as keys */
    private function vocabularyTokens(): array
    {
        $vocabulary = [];

        foreach (Product::query()->published()->limit(500)->pluck('title') as $title) {
            foreach (ArabicNormalizer::tokenize($title) as $token) {
                $vocabulary[$token] = true;
            }
        }

        foreach (DB::table('curriculum_aliases')->pluck('normalized_alias') as $alias) {
            foreach (preg_split('/\s+/u', (string) $alias) ?: [] as $token) {
                if (ArabicNormalizer::length($token) >= 2) {
                    $vocabulary[$token] = true;
                }
            }
        }

        foreach (SearchSynonym::query()->active()->pluck('term') as $term) {
            $vocabulary[$term] = true;
        }

        return $vocabulary;
    }

    /**
     * Honest zero-result recovery: popular = REAL top non-zero-result queries
     * (empty when none exist yet); browse = the seeded stages and subjects.
     *
     * @return array{popular: array<int, array{query: string, count: int}>, browse: array<int, array{label: string, url: string}>}
     */
    /**
     * أيعرف الكتالوج هذه الكلمة؟ — فحصُ وجودٍ واحد، لا بحثٌ كامل.
     *
     * يُسأل عن الطيّ المخزَّن أوّلًا لأنّه مطويٌّ سلفًا، ويسقط إلى الحقول
     * الخام حين يغيب العمود — فصفٌّ لم يُسقَط بعد يبقى مفهومًا. والسؤال
     * يقتصر على درب صفر النتائج، وهو نادر.
     */
    private function catalogKnows(string $token): bool
    {
        $folded = ArabicNormalizer::normalize($token);
        $needle = '%'.mb_strtolower($folded, 'UTF-8').'%';

        /*
         * «يعرفها الكتالوج» أوسع من «ترد في نصّ منتج».
         *
         * أوّل صياغةٍ فحصت نصوص المنتجات وحدها، فحكمت على «حساب» بالجهل —
         * وهي مرادفٌ فعّال لـ«رياضيات» (`search_synonyms`) ومدخلٌ في
         * `curriculum_aliases`، أي أنّ النظام يفهمها تمامًا ويصل بها إلى
         * المادة. فكانت الكلمتان تُعدّان مجهولتين معًا، ولا يبقى شيء،
         * فيمتنع التخفيف عن العمل أصلًا.
         *
         * فالمفردات المُدارة جزءٌ من المعرفة: كلمةٌ فيها معروفة وإن لم ترد
         * حرفًا في أيّ عنوان.
         */
        $inVocabulary = DB::table('search_synonyms')
            ->where('state', 'active')
            ->where(fn ($q) => $q->where('term', $folded)->orWhere('synonym', $folded))
            ->exists()
            || DB::table('curriculum_aliases')->where('alias', $folded)->exists();

        if ($inVocabulary) {
            return true;
        }

        /*
         * «معروفة» = كلمةٌ في نصّ منتج، لا سلسلةٌ داخل كلمة.
         *
         * كان `LIKE '%…%'` وحده الحَكَم، فكانت «الرياض» معروفةً لأنّها
         * بادئةُ «الرياضيات» — والتعافي يُبقي المعروف ويُسقط المجهول، فصارت
         * «تذاكر طيران الرياض» بحثًا عن «الرياض» وحدها وأعادت الرياضيات.
         * فـ`LIKE` يُرشّح، والكلمةُ تُتحقّق: مطابقةٌ تامّة مع سوابق العربيّة
         * الملتصقة (و، ب، ل، ف، ك، ال) وأداة التعريف في أيٍّ من الجانبين.
         */
        $rows = Product::query()
            ->published()
            ->where(function ($q) use ($needle) {
                $q->whereRaw("lower(coalesce(search_normalized::text, '')) like ?", [$needle])
                    ->orWhereRaw("lower(coalesce(title, '')) like ?", [$needle])
                    ->orWhereRaw("lower(coalesce(subtitle, '')) like ?", [$needle])
                    ->orWhereRaw("lower(coalesce(short_desc, '')) like ?", [$needle])
                    ->orWhereRaw("lower(coalesce(description, '')) like ?", [$needle]);
            })
            ->limit(25)
            ->get(['title', 'subtitle', 'short_desc', 'description']);

        $stem = str_starts_with($folded, 'ال') && ArabicNormalizer::length($folded) > 4 ? mb_substr($folded, 2) : $folded;
        $pattern = '/(?:^|\s)(?:[وبلفك]|ال|وال|بال|فال|كال|لل)?(?:'.preg_quote($folded, '/').'|'.preg_quote($stem, '/').')(?:\s|$)/u';

        foreach ($rows as $row) {
            $text = ArabicNormalizer::normalize(implode(' ', [$row->title, $row->subtitle, $row->short_desc, $row->description]));
            if (preg_match($pattern, mb_strtolower($text, 'UTF-8')) === 1) {
                return true;
            }
        }

        return false;
    }

    private function recovery(): array
    {
        /*
         * الاستعلام الفارغ ليس اقتراحًا.
         *
         * «تصفّح كلّ شيء» يُسجَّل استعلامًا ناجحًا فارغًا، وهو أكثر ما يتكرّر
         * بفارقٍ هائل: ٣٣٢٥ صفًّا من ٣٨٢٠ في قاعدة التطوير (مقيس ٢٠٢٦-٠٩-٢٣).
         * فكان أعلى «الأكثر بحثًا» سلسلةً فارغة، تُصيَّرها صفحة البحث رقاقةً
         * بلا نصّ يقودُ نقرُها إلى حيث المستعمل واقفٌ أصلًا — أسوأ اقتراحٍ
         * يُقدَّم لمن لم يجد شيئًا.
         *
         * ولا يُحذف الصفّ من القياس: هو حقيقةٌ عن التصفّح تُحسب في التحليلات.
         * إنّما يُستبعد من **الاقتراح** وحده، وهو موضعٌ يشترط نصًّا قابلًا
         * للنقر.
         */
        $popular = SearchQuery::query()
            ->where('zero_result', false)
            ->whereNotNull('normalized_query')
            ->where('normalized_query', '<>', '')
            ->select('normalized_query', DB::raw('count(*) as n'))
            ->groupBy('normalized_query')
            ->orderByDesc('n')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['query' => $row->normalized_query, 'count' => (int) $row->n])
            ->all();

        $browse = [
            ...Stage::query()->orderBy('position')->get(['name_ar', 'slug'])
                ->map(fn (Stage $s) => ['label' => $s->name_ar, 'url' => '/stages/'.$s->slug]),
            ...Subject::query()->orderBy('position')->limit(6)->get(['name_ar', 'slug'])
                ->map(fn (Subject $s) => ['label' => $s->name_ar, 'url' => '/subjects/'.$s->slug]),
        ];

        return ['popular' => $popular, 'browse' => $browse];
    }
}
