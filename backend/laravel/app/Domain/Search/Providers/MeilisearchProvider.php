<?php

namespace App\Domain\Search\Providers;

use App\Domain\Search\Contracts\SearchProvider;
use App\Domain\Search\Models\SearchIndexState;
use App\Domain\Search\Models\SearchSynonym;
use App\Domain\Search\ProductSearchProjector;
use App\Domain\Search\SearchRequest;
use App\Domain\Search\SearchResult;
use App\Models\Product;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Pure Http-facade Meilisearch client — deliberately NO SDK dependency.
 * Index naming: products_v{generation} (MIP §6.1); the active name lives in
 * search_index_state, so swap/rollback are one bookkeeping update.
 *
 * Every call is timeboxed (default 800ms) — a slow engine must degrade to the
 * PG fallback, never stall the storefront.
 */
class MeilisearchProvider implements SearchProvider
{
    public const TIMEBOX_SECONDS = 0.8;

    /** الكتابة (بناء الفهرس، دفع الوثائق) ليست مسار القراءة — ٠٫٨ ثانية كانت تُسقط كلّ دفعةٍ من ٢٠٠ وثيقة. */
    public const WRITE_TIMEOUT_SECONDS = 30.0;

    private string $host;

    private ?string $key;

    private float $timeout;

    public function __construct(?string $host = null, ?string $key = null, ?float $timeout = null)
    {
        $this->host = rtrim($host ?? config('services.meilisearch.host') ?? (string) env('MEILI_HOST', 'http://127.0.0.1:7700'), '/');
        $this->key = $key ?? config('services.meilisearch.key') ?? env('MEILI_MASTER_KEY');
        $this->timeout = $timeout ?? self::TIMEBOX_SECONDS;
    }

    /** الأبعاد المطلوبة توزيعاتها مع كل بحث — أعمدة filterable قائمة. */
    /*
     * `nature` بُعدٌ سادس: توزيعُ أنواع المنتج يصل في الطلب نفسه، فلا استعلام
     * إضافيّ. وهو مُرشَّحٌ في إعدادات الفهرس أصلًا (`filterableAttributes`)،
     * فلا تغيير في المخطّط — نقصٌ في القراءة كان وحده.
     */
    private const FACET_DIMENSIONS = ['format', 'stage', 'grade', 'subject', 'semester', 'nature', 'resource_type'];

    /** اسمُ البُعد في الفهرس ⇐ اسمُه في `facetCounts` (كما يُصدره `PgFallbackProvider`). */
    private const FACET_KEYS = ['resource_type' => 'kind'];

    public function healthy(): bool
    {
        try {
            $response = $this->http()->get($this->host.'/health');

            return $response->successful() && $response->json('status') === 'available';
        } catch (\Throwable) {
            return false;
        }
    }

    public function search(SearchRequest $request): SearchResult
    {
        $index = $this->indexName();
        $q = trim(implode(' ', $request->allTokens()));

        $payload = [
            'q' => $q,
            'page' => $request->page,
            'hitsPerPage' => $request->perPage,
            // التوزيعات تأتي مع الطلب الرئيس نفسه — لا نداء إضافيًا لكل بُعد.
            'facets' => self::FACET_DIMENSIONS,
            'attributesToRetrieve' => ['id'],
        ];
        if (($filter = $this->filterExpression($request, withTypeTab: true)) !== []) {
            $payload['filter'] = $filter;
        }
        if (($sort = $this->sortExpression($request->sort)) !== null) {
            $payload['sort'] = $sort;
        } elseif ($q === '') {
            /* تصفّحٌ بلا نصّ: لا صلةَ تُرتَّب بها — الأحدثُ نشرًا أوّلًا، كما في PostgreSQL. */
            $payload['sort'] = ['published_at:desc'];
        }

        $response = $this->http()->post($this->host.'/indexes/'.$index.'/search', $payload)->throw();
        $ids = array_map(fn (array $hit) => (int) $hit['id'], $response->json('hits') ?? []);
        $total = (int) ($response->json('totalHits') ?? count($ids));

        // Tab counts must reflect the result set WITHOUT the tab cut; when a
        // tab is active, ask once more for the facet distribution only.
        if ($request->typeTab !== 'all') {
            $facetPayload = [
                'q' => $q,
                'hitsPerPage' => 0,
                'page' => 1,
                // كل الأبعاد من الطلب الخالي من قصّ التبويب — لا الصيغة وحدها:
                // كانت توزيعات التصنيف تُقرأ من الاستجابة المقصوصة فتعطي أرقامًا
                // تخالف مسار الاحتياطي لنفس العنوان حرفيًّا.
                'facets' => self::FACET_DIMENSIONS,
            ];
            if (($facetFilter = $this->filterExpression($request, withTypeTab: false)) !== []) {
                $facetPayload['filter'] = $facetFilter;
            }
            $facetResponse = $this->http()->post($this->host.'/indexes/'.$index.'/search', $facetPayload)->throw();
            $formatCounts = $facetResponse->json('facetDistribution.format') ?? [];
            $totalUnfiltered = (int) ($facetResponse->json('totalHits') ?? array_sum($formatCounts));
        } else {
            $formatCounts = $response->json('facetDistribution.format') ?? [];
            $totalUnfiltered = $total;
        }

        /*
         * مصدر التوزيعات هو المجموعة **قبل** قصّ التبويب دائمًا — نفس عقد
         * formatCounts، ونفس ما يحسبه مسار الاحتياطي. عند تبويب نشط تأتي من
         * الطلب الثاني الخالي من القصّ؛ وبلا تبويب الاستجابة الرئيسة خالية
         * منه أصلًا.
         */
        $facetSource = $request->typeTab !== 'all' ? ($facetResponse ?? $response) : $response;
        $facetCounts = [];
        foreach (self::FACET_DIMENSIONS as $dimension) {
            if ($dimension === 'format') {
                continue;
            }
            $distribution = $facetSource->json('facetDistribution.'.$dimension) ?? [];
            if ($distribution !== []) {
                $facetCounts[self::FACET_KEYS[$dimension] ?? $dimension] = array_map('intval', $distribution);
            }
        }

        return new SearchResult($this->hydrate($ids), $total, $totalUnfiltered, $formatCounts, $facetCounts);
    }

    public function suggest(string $normalizedQuery, int $limit): Collection
    {
        $response = $this->http()->post($this->host.'/indexes/'.$this->indexName().'/search', [
            'q' => $normalizedQuery,
            'limit' => $limit,
            'attributesToRetrieve' => ['id'],
        ])->throw();

        return $this->hydrate(array_map(fn (array $hit) => (int) $hit['id'], $response->json('hits') ?? []));
    }

    public function indexName(): string
    {
        return SearchIndexState::forEngine('meilisearch')->index_name;
    }

    /**
     * Index settings per the MIP §6.1 matrix: searchable order = weight order
     * (title 4 ≫ sku exact-priority ≫ stage/grade/subject 3 ≫ synonyms/
     * semester 2 ≫ subtitle 1 ≫ type/format), typo tolerance ON except on sku
     * so fuzzy never outranks exact SKU, synonyms fed from the engine.
     *
     * @return array<string, mixed>
     */
    public function settingsPayload(): array
    {
        return [
            /*
             * الترتيب ترتيب الوزن في Meilisearch: الأوّل أثقل. العنوان ثمّ
             * الرمز قبل التصنيف — فبحثٌ برمزٍ دقيق يسبق مطابقةً تقريبيّة في
             * وصف (§٦٤ §٨٠).
             */
            /*
             * الترتيب هنا وزنٌ لا تعداد: الأوّل أثقل، والأخير أخفّ.
             *
             * و`description` آخرُها عمدًا — مطابقةً لأخفّ طبقةٍ في المزوّد
             * الحيّ (`W_DESC_TOKEN`). ولولاه لاختلف المزوّدان فيما يُعثَر
             * عليه أصلًا: وصفُ أربعةٍ وعشرين منتجًا من سبعةٍ وعشرين يُطابَق
             * على `pg` ولا يُطابَق على المحرّك — وهو تفاوتٌ حقيقيّ بين
             * المزوّدَين، لا تفاوتُ طيٍّ.
             *
             * ولا `search_text` هنا: أُضيف إلى الوثيقة ثمّ أُزيل. مبرّره كان
             * «المحرّك يفهرس خامًا ويطوي الاستعلام»، وقياسٌ مستقلّ على فهرسٍ
             * تجريبيّ أثبت أنّ المحرّك يطوي هذه الأصناف بنفسه
             * (أوراق↔اوراق · مادة↔ماده · الابتدائي↔الابتدايي · مُلَخَّص↔ملخص).
             * فحقلٌ مفهرسٌ لا يُبحَث ومبرّرُه مكذوبٌ وزنٌ ميّت.
             */
            /*
             * و`extracted_content` بعد `description` — آخرُ القائمة كلّها.
             *
             * مقابلُه الحرفيّ في المزوّد الحيّ `W_FILE_TOKEN` (٢٠) تحت
             * `W_DESC_TOKEN` (٦٠): ما استُخرج من ملفٍّ لا يسبق ما كتبه
             * الناشر (§٢٣). وحذفُه من هنا لا يعني «لا يُفهرَس» بل «يُفهرَس
             * على `pg` ولا يُفهرَس على المحرّك» — أي المزوّدان يختلفان فيما
             * يُعثَر عليه أصلًا، وهو العيب الذي عضّ هذا المستودع في `description`
             * قبل أسطر.
             *
             * ومفهرسٌ لا يعني معروضًا: `attributesToRetrieve => ['id']` في
             * `search` و`suggest` معًا، والمنتج يُرطَّب من القاعدة — فلا
             * يخرج محتوى ملفٍّ مدفوع في استجابةٍ عامّة (§٥٨ §١٢٦).
             */
            'searchableAttributes' => [
                'title', 'sku', 'stage', 'grade', 'subject', 'synonyms',
                'semester', 'subtitle', 'resource_type', 'format', 'formats',
                'short_desc', 'use_cases', 'compatibility', 'description',
                'extracted_content',
            ],
            'filterableAttributes' => [
                'stage', 'grade', 'subject', 'semester', 'resource_type',
                'format', 'formats', 'editable', 'price_minor', 'price_max_minor',
                'on_sale', 'discount_pct', 'rating_avg', 'rating_count', 'updated_at',
                /*
                 * `fulfillment` و`in_stock` و`nature` تجعل أسئلة §١٥٤ الذهبية
                 * قابلةً للترشيح: «تنزيل فوري» و«يُشحن» و«متوفّر».
                 */
                'nature', 'fulfillment', 'in_stock', 'variant_count',
            ],
            'sortableAttributes' => ['price_minor', 'price_max_minor', 'rating_avg', 'rating_count', 'sales_count', 'updated_at', 'published_at'],
            /*
             * `sort` أوّلًا: الترتيبُ الصريح (السعر، الأحدث، الأكثر مبيعًا) هو
             * الترتيب، كما في PostgreSQL. كان بعد قواعد الصلة فلا يحسم إلّا
             * التعادل — فـ«الأقلّ سعرًا» في Meilisearch لم يكن مرتّبًا بالسعر
             * (قاسه `search:parity`). وبلا فرزٍ صريح لا تعمل القاعدة: الصلةُ كما هي.
             */
            'rankingRules' => ['sort', 'words', 'typo', 'proximity', 'attribute', 'exactness', 'conversion_score:desc'],
            'typoTolerance' => ['enabled' => true, 'disableOnAttributes' => ['sku']],
            'synonyms' => $this->synonymsMap(),
        ];
    }

    /**
     * Active synonyms as the Meilisearch map shape; two_way rows also emit the
     * reverse edge. Pending and negative rows never reach the engine.
     *
     * @return array<string, array<int, string>>
     */
    public function synonymsMap(): array
    {
        $map = [];
        foreach (SearchSynonym::query()->active()->get(['term', 'synonym', 'direction']) as $row) {
            $map[$row->term][] = $row->synonym;
            if ($row->direction === 'two_way') {
                $map[$row->synonym][] = $row->term;
            }
        }

        return array_map(fn (array $list) => array_values(array_unique($list)), $map);
    }

    /**
     * المستند = الإسقاط القانونيّ مُسلسَلًا (§٦٣ §١٢٤).
     *
     * كان يُبنى هنا من `products` مباشرةً، فيبني البديل (PostgreSQL) شكلًا
     * آخر من الجداول نفسها: يبحث الزبون بكلمةٍ فيجدها على محرّك ولا يجدها
     * على الآخر، ولا أحد يعرف أيّهما الصحيح لأنّ «الصحيح» لم يكن مكتوبًا في
     * مكان. صار المحوّل يُسلسِل ولا يقرّر.
     *
     * @return array<string, mixed>
     */
    public function documentFor(Product $product): array
    {
        return app(ProductSearchProjector::class)->forProduct($product)->toArray();
    }

    // ── Index administration (used by the Indexer) ──────────────────────────

    public function createIndex(string $uid): void
    {
        $this->http(self::WRITE_TIMEOUT_SECONDS)->post($this->host.'/indexes', ['uid' => $uid, 'primaryKey' => 'id'])->throw();
    }

    public function pushSettings(string $uid): void
    {
        $this->http(self::WRITE_TIMEOUT_SECONDS)->patch($this->host.'/indexes/'.$uid.'/settings', $this->settingsPayload())->throw();
    }

    /** @param array<int, array<string, mixed>> $documents */
    public function pushDocuments(string $uid, array $documents): void
    {
        if ($documents !== []) {
            $this->http(self::WRITE_TIMEOUT_SECONDS)->put($this->host.'/indexes/'.$uid.'/documents', $documents)->throw();
        }
    }

    /**
     * كلُّ وثائق الفهرس كما هي — للتدقيق (`EngineParity`) لا للبحث.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(string $uid, int $limit = 10000): array
    {
        $out = [];
        for ($offset = 0; $offset < $limit; $offset += 1000) {
            $page = $this->http()->get($this->host.'/indexes/'.$uid.'/documents', ['limit' => 1000, 'offset' => $offset])->throw()->json('results') ?? [];
            array_push($out, ...$page);
            if (count($page) < 1000) {
                break;
            }
        }

        return $out;
    }

    public function deleteDocument(string $uid, int $productId): void
    {
        $this->http(self::WRITE_TIMEOUT_SECONDS)->delete($this->host.'/indexes/'.$uid.'/documents/'.$productId)->throw();
    }

    public function deleteIndex(string $uid): void
    {
        // فشلُ إزالة جيلٍ قديم كان يمرّ صامتًا فيتسرّب الفهرس.
        $this->http(self::WRITE_TIMEOUT_SECONDS)->delete($this->host.'/indexes/'.$uid)->throw();
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function http(?float $timeout = null): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout($timeout ?? $this->timeout)
            ->connectTimeout($this->timeout);

        return $this->key !== null && $this->key !== '' ? $request->withToken($this->key) : $request;
    }

    /**
     * «ليّن» = يطابق القيمة أو لم يُصنَّف — المعنى نفسه في `PgFallbackProvider`.
     */
    private function clause(string $attribute, string|array $value, bool $lenient): string
    {
        /* اختيارٌ متعدّد: `IN` = «أو» داخل البُعد، كـ`whereIn` في PostgreSQL. */
        $values = array_values((array) $value);
        $eq = count($values) === 1
            ? $attribute.' = '.$this->quote((string) $values[0])
            : $attribute.' IN ['.implode(', ', array_map(fn ($v): string => $this->quote((string) $v), $values)).']';

        return $lenient ? "({$eq} OR {$attribute} IS NULL OR {$attribute} NOT EXISTS)" : $eq;
    }

    /** @return array<int, string> */
    private function filterExpression(SearchRequest $request, bool $withTypeTab): array
    {
        $filter = [];
        foreach (['stage', 'grade', 'subject', 'semester', 'format', 'nature'] as $attribute) {
            $values = $request->values($attribute);
            if ($values !== []) {
                $filter[] = $this->clause($attribute, $attribute === 'format' ? array_map('strtoupper', $values) : $values, $request->isLenient($attribute));
            }
        }
        if (($kinds = $request->values('kind')) !== []) {
            $filter[] = $this->clause('resource_type', $kinds, $request->isLenient('kind'));
        }
        if ($request->editable === true) {
            $filter[] = 'editable = true';
        }
        if ($withTypeTab && $request->typeTab !== 'all' && ($request->filters['format'] ?? null) === null) {
            $filter[] = 'format = '.$this->quote(strtoupper($request->typeTab));
        }

        return $filter;
    }

    private function quote(string $value): string
    {
        /*
         * العكسية قبل الاقتباس، وبهذا الترتيب حصرًا: قيمة تنتهي بـ«\» كانت
         * تُهرِّب علامة الإغلاق فتكسر تعبير المرشِّح كلّه — استثناء من المحرّك
         * ⇒ سقوط تلقائي على مسار PostgreSQL الأغلى. معامل مصنوع بعناية كان
         * يحوّل كل طلب بحث إلى الطريق البطيء عمدًا.
         */
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /** @return array<int, string>|null */
    private function sortExpression(string $sort): ?array
    {
        return match ($sort) {
            'bestselling' => ['sales_count:desc'],
            'newest' => ['published_at:desc'],
            'price_asc' => ['price_minor:asc'],
            'price_desc' => ['price_minor:desc'],
            default => null,
        };
    }

    /**
     * Load the hit products from the authoritative database, preserving the
     * engine's ranking order; unpublished rows are dropped defensively.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Product>
     */
    private function hydrate(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        /*
         * المجموعة تُحمَّل مع البطاقة: `ProductCardResource` يقرأ منها الطبيعة،
         * وبلا تحميلها يسقط إلى العمود القديم — فيُرشَّح الزائر بـ«هجين» ثمّ
         * تقول له البطاقة «رقميّ». استعلامٌ واحد للصفحة كلّها لا لكلّ بطاقة.
         */
        $products = Product::query()->published()->with(['price', 'group'])->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $products->get($id))->filter()->values();
    }
}
