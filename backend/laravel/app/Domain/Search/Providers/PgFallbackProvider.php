<?php

namespace App\Domain\Search\Providers;

use App\Domain\Catalog\ProductCapabilities;
use App\Domain\Catalog\ProductPreviews;
use App\Domain\Pricing\PricingService;
use App\Domain\Search\ArabicNormalizer;
use App\Domain\Search\Contracts\SearchProvider;
use App\Domain\Search\ProductSearchProjector;
use App\Domain\Search\SearchRequest;
use App\Domain\Search\SearchResult;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ALWAYS-WORKS lexical core: PostgreSQL/sqlite tiered ranking, upgraded
 * from the legacy Tahdheer_Search_Service tier order — SKU exact ≫ title
 * exact ≫ SKU partial ≫ title prefix ≫ title token ≫ body token. Tiers are
 * kept far apart (legacy weights preserved) so secondary signals order within
 * a tier but never jump across tiers. Matching runs over ArabicNormalizer
 * output on both sides, so «اوراق» reaches «أوراق».
 */
class PgFallbackProvider implements SearchProvider
{
    // Legacy tier weights, verbatim from class-tahdheer-search-service.php.
    private const W_SKU_EXACT = 1000;

    private const W_TITLE_EXACT = 800;

    private const W_SKU_PARTIAL = 700;

    private const W_TITLE_PREFIX = 600;

    private const W_TITLE_TOKEN = 450;

    /**
     * كلمةٌ من كلمات المنتج المفتاحيّة (`product_seo.synonyms`) — يكتبها المشرف
     * أو يعتمدها من اقتراح الذكاء. منتقاةٌ لهذا المنتج، فهي فوق الوصفين ودون
     * العنوان. وكان هذا المسار لا يقرؤها أصلًا (Meilisearch وحده يفهرسها).
     */
    private const W_KEYWORD_TOKEN = 300;

    private const W_BODY_TOKEN = 100;

    /*
     * الوصفُ الكامل — طبقةٌ تحت الجسد، لا بديلٌ عنه.
     *
     * كان `score()` يبني الجسد من `subtitle` و`short_desc` وحدهما، فوصفُ
     * أربعةٍ وعشرين منتجًا من سبعةٍ وعشرين خارج المطابقة تمامًا: عبارةٌ لا
     * ترد إلّا في الوصف تُخرج صفر نتائج.
     *
     * ورتبتُه دون الجسد عمدًا (§٢٣: المطابقة تُوزن بمصدرها). والأثر على
     * الترتيب القائم **صفر** بنيويًّا: كلّ منتجٍ كان يسجّل شيئًا يحتفظ بدرجته
     * كما هي، ولا يبلغ هذه الطبقة إلّا من كان يسجّل صفرًا — أي لا يُزاح أحد،
     * إنّما يظهر من كان غائبًا. وهذا شرطُ §١٤٧: تحسينٌ لا يُحدث ارتدادًا.
     */
    private const W_DESC_TOKEN = 60;

    /*
     * نصّ الملفّ المستخرَج — أدنى طبقةٍ في السلّم، ولا يجوز أن تكون غيرَ ذلك.
     *
     * §٢٣: المطابقة تُوزن بمصدرها، ومطابقةُ وثيقةٍ أو OCR لا تسبق مطابقةَ
     * عنوانٍ ولا وصف. والسبب ليس ذوقًا: العنوان والوصف نصٌّ كتبه الناشر
     * **ليوصف به المنتج**، ونصُّ الملفّ محتوًى استُخرج آليًّا ولم يُكتب
     * للوصف — فيه كلماتٌ عرضيّة («غلاف»، «صفحة»، «معاينة») ترد في كلّ ملفٍّ
     * تقريبًا. لو وزنت كوزن الوصف لتصدّر المنتجُ الذي صدف أنّ ملفّه يذكر
     * كلمةَ البحث منتجًا عنوانُه نفسُ الكلمة — أي يصير الاستخراج ضوضاءً
     * تزيح الإشارة. ودون الجميع: يظهر من كان غائبًا ولا يُزاح أحد.
     *
     * والرقم ٢٠ دون ٦٠ بفجوةٍ تبتلع كلّ مكافأةٍ داخل الطبقة (لا مكافأة هنا
     * أصلًا)، على عادة الصنف: الطبقات متباعدة فلا تقفز إشارةٌ ثانويّة فوق
     * طبقة.
     */
    private const W_FILE_TOKEN = 20;

    /** Legacy MAX_RESULTS bound: a public search box must stay cheap. */
    private const CANDIDATE_CAP = 1000;

    /**
     * مفتاحُ الترتيب الأوّل: عددُ كلمات السؤال المطابَقة في أيّ حقل — قاعدة
     * «words» الأولى في Meilisearch. الطبقةُ (W_*) تحسم التعادل وحدها، فالدرجة
     * = كلماتٌ × هذه الخطوة + الطبقة. بلا بادئة `W_` عمدًا: `ScoreProbe`
     * يقرأ الطبقات من ثوابت `W_`، وهذه ليست طبقة.
     */
    public const COVERAGE_STEP = 10000;

    /**
     * نصّ ملفّات المرشَّحين مطويًّا: معرّف المنتج ← النصّ (فارغٌ = لا نصّ).
     *
     * ذاكرةُ نداءٍ واحد لا أكثر: `search()` تُفرغها في أوّل سطر. الاستخراج
     * يتغيّر بأمرٍ خارج الطلب (`content:extract`)، وذاكرةٌ تعبر الطلبات كانت
     * ستُطابق على نصٍّ حُذف أو تُخفي نصًّا أُضيف — وهو بالضبط عيبُ «الطيّ
     * المخزَّن بلا بصمة» الذي دفعنا ثمنه في `storedFold` أدناه.
     *
     * @var array<int, string>
     */
    private array $extractedFold = [];

    public function search(SearchRequest $request): SearchResult
    {
        /* ذاكرةُ نصّ الملفّات لا تعبر نداءً — انظر `$extractedFold`. */
        $this->extractedFold = [];

        $candidates = $this->filteredCandidates($request);

        if ($request->query !== '') {
            $scored = [];
            $unscored = [];
            /*
             * سؤالٌ بكلمتين فأكثر تُحسب تغطيتُه على الملفّات أيضًا لكلّ مرشَّح:
             * منتجٌ طابق عنوانُه كلمةً وملفُّه الأخرى يُعدّ مطابِقًا للاثنتين،
             * وإلّا لرتّبته الجولةُ الأولى بنصف تغطيته. والكلمة الواحدة تبقى
             * على الجولتين: لا تغطيةَ تتغيّر بقراءة الملفّ حين طابق العنوان.
             */
            $multi = count($this->queryWords($request)) >= 2;
            if ($multi) {
                $this->primeExtracted($candidates->pluck('id')->map(fn ($id): int => (int) $id)->all());
            }

            foreach ($candidates as $product) {
                $score = $this->score($product, $request, withFiles: $multi);
                if ($score > 0) {
                    $scored[$product->id] = $score;
                } else {
                    $unscored[] = (int) $product->id;
                }
            }

            /*
             * جولةٌ ثانية لمن سجّل صفرًا وحده، بعد تحميل نصّ ملفّاتهم دفعةً.
             *
             * الترتيب مقصود: الطبقات الست الأولى تُجيب من صفّ المنتج بلا
             * استعلامٍ إضافيّ، فلا يدفع الباحثُ عن عنوانٍ ثمنَ قراءة
             * `asset_extractions` لألف مرشَّح. والنتيجة واحدة في الحالتين:
             * من بلغ طبقةً أعلى لا يبلغ هذه أصلًا — `score()` تقرأ النصّ
             * المستخرَج آخرَ شيء.
             *
             * والبديل المرفوض: مطابقةٌ خارج `score()`. المسبار
             * (`ScoreProbe`) ينادي `score()` بالانعكاس ليشرح الدرجة، فطبقةٌ
             * تعيش خارجها تجعل «الشرح» يقول صفرًا والبحثُ يقول ٢٠.
             */
            if ($unscored !== []) {
                $this->primeExtracted($unscored);

                foreach ($candidates as $product) {
                    if (isset($scored[$product->id])) {
                        continue;
                    }
                    $score = $this->score($product, $request);
                    if ($score > 0) {
                        $scored[$product->id] = $score;
                    }
                }
            }

            $candidates = $candidates->filter(fn (Product $p) => isset($scored[$p->id]));
        } else {
            $scored = $candidates->mapWithKeys(fn (Product $p) => [$p->id => 0])->all();
        }

        $ordered = $this->order($candidates, $scored, $request->sort);

        // Type-tab counts come from the full filtered set, BEFORE the tab cut.
        $formatCounts = [];
        foreach ($ordered as $product) {
            $key = strtoupper((string) $product->format);
            if ($key !== '') {
                $formatCounts[$key] = ($formatCounts[$key] ?? 0) + 1;
            }
        }
        $totalUnfiltered = $ordered->count();

        /*
         * نفس توزيعات المحرّك، بعدٍّ مباشر على المجموعة المرشَّحة.
         *
         * القيمة هي **slug** حصرًا — نفس ما يفهرسه documentFor ونفس ما يقارنه
         * filteredCandidates أعلاه. العدّ بالاسم العربي بدا مكافئًا (اسم
         * المادة يساوي رمزها غالبًا) لكن slug الصف «خامس-ابتدائي» يخالف اسمه
         * «الصف الخامس الابتدائي»: رابط توزيعٍ مبني على الاسم يعود صفر نتائج
         * في الوضع المتدهور فقط — انكسار يظهر تحديدًا حين يكون المتجر أضعف.
         */
        $facetCounts = [];
        foreach (['stage', 'grade', 'subject', 'semester'] as $dimension) {
            foreach ($ordered as $product) {
                $slug = $product->{$dimension}?->slug;
                if (is_string($slug) && $slug !== '') {
                    $facetCounts[$dimension][$slug] = ($facetCounts[$dimension][$slug] ?? 0) + 1;
                }
            }
        }

        /* نوعُ الملف (`resource_type`) — قيمتُه slug النوع نفسه، كما يرشّح بها `filteredCandidates`. */
        foreach ($ordered as $product) {
            $kind = $product->resource_type;
            if (is_string($kind) && $kind !== '') {
                $facetCounts['kind'][$kind] = ($facetCounts['kind'][$kind] ?? 0) + 1;
            }
        }

        /*
         * وتوزيعُ الأنواع كذلك: مرشِّحٌ بلا أعداد يُعرَض بلا أرقام في الوضع
         * المتدهور وبأرقامٍ في الوضع السليم — فرقٌ يراه الزائر ولا يفهمه.
         */
        foreach ($ordered as $product) {
            $nature = $product->group?->product_nature
                ?? ProductCapabilities::natureOfLegacy($product->fulfillment_type);

            if ($nature !== null) {
                $facetCounts['nature'][$nature] = ($facetCounts['nature'][$nature] ?? 0) + 1;
            }
        }

        if ($request->typeTab !== 'all') {
            $wanted = strtoupper($request->typeTab);
            $ordered = $ordered->filter(fn (Product $p) => strtoupper((string) $p->format) === $wanted)->values();
        }

        $total = $ordered->count();
        $items = $ordered->slice(($request->page - 1) * $request->perPage, $request->perPage)->values();

        return new SearchResult($items, $total, $totalUnfiltered, $formatCounts, $facetCounts);
    }

    public function suggest(string $normalizedQuery, int $limit): Collection
    {
        if ($normalizedQuery === '') {
            return collect();
        }

        $scored = [];
        foreach ($this->baseQuery()->limit(self::CANDIDATE_CAP)->get() as $product) {
            $titleNorm = $this->storedFold($product)['title'] ?? ArabicNormalizer::normalize($product->title);
            if (str_starts_with($titleNorm, $normalizedQuery)) {
                $scored[] = [$product, self::W_TITLE_PREFIX];
            } elseif (str_contains($titleNorm, $normalizedQuery)) {
                $scored[] = [$product, self::W_TITLE_TOKEN];
            }
        }

        usort($scored, fn (array $a, array $b) => $b[1] <=> $a[1]);

        return collect(array_map(fn (array $row) => $row[0], array_slice($scored, 0, $limit)));
    }

    public function healthy(): bool
    {
        return true; // the database is the application; if it is down nothing answers.
    }

    /** @return Collection<int, Product> */
    private function filteredCandidates(SearchRequest $request): Collection
    {
        $query = $this->baseQuery();

        /* اختيارٌ متعدّد: «أو» داخل البُعد، و«و» بين الأبعاد — كـ`IN` في Meilisearch. */
        foreach (['stage', 'grade', 'subject', 'semester'] as $dim) {
            $slugs = $request->values($dim);
            if ($slugs === []) {
                continue;
            }
            if ($request->isLenient($dim)) {
                $query->where(fn (Builder $w) => $w
                    ->whereHas($dim, fn (Builder $q) => $q->whereIn('slug', $slugs))
                    ->orWhereNull($dim.'_id'));
            } else {
                $query->whereHas($dim, fn (Builder $q) => $q->whereIn('slug', $slugs));
            }
        }

        if (($kinds = $request->values('kind')) !== []) {
            $request->isLenient('kind')
                ? $query->where(fn (Builder $w) => $w->whereIn('resource_type', $kinds)->orWhereNull('resource_type'))
                : $query->whereIn('resource_type', $kinds);
        }
        if (($format = $request->filters['format'] ?? null) !== null) {
            $request->isLenient('format')
                ? $query->where(fn (Builder $w) => $w->whereRaw('upper(format) = ?', [strtoupper($format)])->orWhereNull('format'))
                : $query->whereRaw('upper(format) = ?', [strtoupper($format)]);
        }
        if ($request->editable === true) {
            $query->where('editable', 'yes');
        }

        /*
         * §٦ — تصفيةٌ بنوع المنتج، بالمنطق نفسه الذي يفهرسه المُسقِط.
         *
         * المسار المتدهور يجب أن يُجيب كما يُجيب المحرّك حرفًا بحرف: مرشِّحٌ
         * يعمل في Meilisearch ويُهمَل هنا يُعيد نتائج كاملةً وكأنّه طُبِّق —
         * وذاك انكسارٌ يظهر تحديدًا حين يكون المتجر أضعف.
         *
         * والشرطان معًا: المجموعة إن وُجدت، وإلّا فالعمود القديم — تمامًا
         * كما يشتقّها `ProductSearchProjector`.
         */
        if (($nature = $request->filters['nature'] ?? null) !== null) {
            $legacy = ProductCapabilities::legacyOfNature($nature);

            $query->where(function (Builder $outer) use ($nature, $legacy) {
                $outer->whereHas('group', fn (Builder $g) => $g->where('product_nature', $nature));

                if ($legacy !== null) {
                    $outer->orWhere(fn (Builder $inner) => $inner
                        ->whereDoesntHave('group')
                        ->where('fulfillment_type', $legacy));
                }
            });
        }

        return $query->orderByDesc('published_at')->limit(self::CANDIDATE_CAP)->get();
    }

    private function baseQuery(): Builder
    {
        /* `group` محمَّلةٌ مع البقيّة: منها تُقرأ الطبيعة للعدّ وللبطاقة. */
        /* وصيغ المجموعة المنشورة وعلَم العيّنة لسطر بيانات البطاقة (استعلامٌ لكلٍّ منهما للصفحة كلّها). */
        return ProductPreviews::withSampleFlag(Product::query()->published()->with(['price', 'stage', 'grade', 'subject', 'semester', 'cover', 'reviewAggregate', 'group.publishedVariants', 'seo']));
    }

    /**
     * @param  bool  $withFiles  هل تُقرأ طبقةُ نصّ الملفّ؟
     *
     * الجولة الأولى تمرّ `false`: قراءةُ النصّ كسولةٌ داخل الطبقة، فكلُّ
     * مرشَّحٍ يسجّل صفرًا كان يجلب نصَّه بنداءٍ مستقلّ — سبعةٌ وعشرون
     * استعلامًا على `asset_extractions` لبحثةٍ واحدة (مقيسة)، والتحميل
     * الدُفعيّ بعدها يجد كلَّ شيء محمَّلًا فلا يفعل شيئًا. فتُؤجَّل الطبقة
     * إلى الجولة الثانية حيث النصّ محمَّلٌ دفعةً واحدة.
     *
     * والافتراضيّ `true` كي يبقى نداءُ `score()` منفردًا صادقًا: `ScoreProbe`
     * يناديها بالانعكاس ليشرح الدرجة، فشرحٌ لا يرى طبقةً يراها البحث كذب.
     */
    private function score(Product $product, SearchRequest $request, bool $withFiles = true): int
    {
        $tier = $this->tier($product, $request, $withFiles);

        return $tier === 0 ? 0 : self::COVERAGE_STEP * $this->matchedWords($product, $request, $withFiles) + $tier;
    }

    /**
     * طبقةُ المطابقة الأقوى (W_*) — كما كانت الدرجة كلّها قبل مفتاح التغطية.
     */
    private function tier(Product $product, SearchRequest $request, bool $withFiles): int
    {
        $q = $request->query;
        $tokens = $request->allTokens();

        /*
         * الطيّ المخزَّن أوّلًا، والطيّ الآنيّ حين يغيب أو يبيت.
         *
         * كان كلّ استعلامٍ يُعيد طيّ عنوان وجسد ووصف كلّ مرشَّح — حتّى ألف
         * مرشَّح (`CANDIDATE_CAP` أعلاه) — والطيّ دالّةٌ خالصة لا يتغيّر
         * دخلها إلّا بتغيّر المنتج. فمحلّه زمن الكتابة.
         *
         * والعمود الفارغ **ليس** «لا مطابقة»: صفٌّ لم يُسقَط بعد يُطوى هنا
         * كما كان يُطوى بالضبط، فالترتيب واحد والترحيل لا يُخفي أحدًا.
         */
        $fold = $this->storedFold($product);

        $skuNorm = $fold['sku'] ?? mb_strtolower(ArabicNormalizer::normalizeDigits((string) $product->sku), 'UTF-8');
        $titleNorm = $fold['title'] ?? ArabicNormalizer::normalize($product->title);
        $bodyNorm = $fold['body'] ?? ArabicNormalizer::normalize(trim(($product->subtitle ?? '').' '.($product->short_desc ?? '')));

        if ($skuNorm !== '' && $skuNorm === mb_strtolower($q, 'UTF-8')) {
            return self::W_SKU_EXACT;
        }
        if ($titleNorm === $q) {
            return self::W_TITLE_EXACT;
        }
        /*
         * جزءُ SKU يُعدّ بحثًا عن SKU من ثلاثة أحرف فصاعدًا. «17» بقيت من
         * «iphone 17 pro» بعد إسقاط المجهول، فطابقت DESIGN-PREVIEW-17.
         */
        if ($skuNorm !== '' && mb_strlen($q) >= 3 && str_contains($skuNorm, mb_strtolower($q, 'UTF-8'))) {
            return self::W_SKU_PARTIAL;
        }
        if (str_starts_with($titleNorm, $q)) {
            return self::W_TITLE_PREFIX;
        }

        if (! $this->covers($request, $product, $withFiles)) {
            return 0;
        }

        $matched = 0;
        foreach ($tokens as $token) {
            if (ArabicNormalizer::containsWord($titleNorm, $token)) {
                $matched++;
            }
        }
        if ($matched > 0) {
            // Within-tier coverage bonus, capped far below the next tier.
            return self::W_TITLE_TOKEN + min(($matched - 1) * 10, 100);
        }

        $keywordNorm = $this->keywordFold($product);
        if ($keywordNorm !== '') {
            foreach ($tokens as $token) {
                if (ArabicNormalizer::containsWord($keywordNorm, $token)) {
                    return self::W_KEYWORD_TOKEN;
                }
            }
        }

        if ($bodyNorm !== '') {
            foreach ($tokens as $token) {
                if (ArabicNormalizer::containsWord($bodyNorm, $token)) {
                    return self::W_BODY_TOKEN;
                }
            }
        }

        /* الوصف يبقى آخر ما يُطوى عند الغياب: طبقةٌ لا يبلغها إلّا من سجّل صفرًا. */
        $descNorm = $fold['desc'] ?? ArabicNormalizer::normalize((string) ($product->description ?? ''));
        if ($descNorm !== '') {
            foreach ($tokens as $token) {
                if (ArabicNormalizer::containsWord($descNorm, $token)) {
                    return self::W_DESC_TOKEN;
                }
            }
        }

        /*
         * وآخرُ الكلّ: ما استُخرج من ملفّات المنتج (§٢٣ — دون العنوان والوصف).
         *
         * لا يُقرأ من `search_normalized`: بصمةُ ذاك العمود تُحسب من حقول
         * صفّ المنتج وحدها، فاستخراجٌ يُضاف أو يُحذف لا يُبطلها — ونصٌّ
         * مطويٌّ لا شيء يُبطله كذبٌ صامت. مصدرُه الجدولُ نفسه في كلّ نداء.
         */
        if (! $withFiles) {
            return 0;
        }

        $extractedNorm = $this->extractedFoldFor($product);
        if ($extractedNorm !== '') {
            foreach ($tokens as $token) {
                if (ArabicNormalizer::containsWord($extractedNorm, $token)) {
                    return self::W_FILE_TOKEN;
                }
            }
        }

        return 0;
    }

    /**
     * نصفُ كلمات السؤال على الأقلّ تظهر في المنتج — حين يكون السؤال كلمتين فأكثر.
     *
     * كانت الطبقات تُعطي درجةً لأوّل كلمةٍ تطابق، فـ«تذاكر طيران الرياض»
     * أعادت ثلاثةَ منتجات رياضيات: «الرياض» بادئةُ «الرياضيات»، والكلمتان
     * الأخريان لا تطابقان شيئًا. كلمةٌ من ثلاث ليست مطابقة.
     *
     * الكلمة تُحسب مغطّاةً إن ظهرت هي — أو مرادفٌ لها في `expandedTokens` —
     * في أيّ حقلٍ مقروء. والسؤال بكلمةٍ واحدة لا يمرّ بهذا الحارس أصلًا.
     */
    private function covers(SearchRequest $request, Product $product, bool $withFiles): bool
    {
        $words = $this->queryWords($request);
        if (count($words) < 2) {
            return true;
        }
        [$covered, $headCovered] = $this->coverage($product, $request, $withFiles);

        /*
         * والمطابقةُ الجزئيّة تُبقي رأسَ السؤال (كلمتَه الأولى). هكذا يُسقط
         * Meilisearch الكلمات — من الآخر (`matchingStrategy: last`) — فلا يُعيد
         * لـ«62 شريحة» كلَّ ما فيه «شريحة». وكان هذا المسار يقبل أيَّ نصفٍ من
         * الكلمات، فأجاب بستّة عشر منتجًا حيث أجاب المحرّك بواحد (search:parity).
         */
        return $headCovered && $covered * 2 >= count($words);
    }

    /** كلماتُ المنتج المفتاحيّة مطويّة — فارغةٌ حين لا صفَّ SEO أو لا كلمات. */
    private function keywordFold(Product $product): string
    {
        return ArabicNormalizer::normalize((string) ($product->seo?->synonyms ?? ''));
    }

    /** @return list<string> كلماتُ السؤال المعجميّة (بلا المرادفات) */
    private function queryWords(SearchRequest $request): array
    {
        return $request->query === '' ? [] : array_values(array_filter(preg_split('/\s+/u', $request->query) ?: [], fn (string $w): bool => $w !== ''));
    }

    /**
     * كم كلمةً من السؤال تظهر في المنتج — في رمزه أو عنوانه أو وصفيه أو (إن
     * قُرئت) ملفّاته. المرادفُ يُغطّي كلمةً واحدة: هو بديلُها لا إضافة.
     */
    private function matchedWords(Product $product, SearchRequest $request, bool $withFiles): int
    {
        return $this->coverage($product, $request, $withFiles)[0];
    }

    /**
     * @return array{0: int, 1: bool} [عدد الكلمات المطابَقة، هل طوبقت الأولى (أو مرادفٌ قام مقامها)]
     */
    private function coverage(Product $product, SearchRequest $request, bool $withFiles): array
    {
        $words = $this->queryWords($request);
        if ($words === []) {
            return [0, false];
        }

        $fold = $this->storedFold($product);
        $hay = implode(' ', [
            $fold['sku'] ?? mb_strtolower(ArabicNormalizer::normalizeDigits((string) $product->sku), 'UTF-8'),
            $fold['title'] ?? ArabicNormalizer::normalize($product->title),
            $fold['body'] ?? ArabicNormalizer::normalize(trim(($product->subtitle ?? '').' '.($product->short_desc ?? ''))),
            $fold['desc'] ?? ArabicNormalizer::normalize((string) ($product->description ?? '')),
            $this->keywordFold($product),
            $withFiles ? $this->extractedFoldFor($product) : '',
        ]);

        $covered = 0;
        $head = false;
        foreach ($words as $i => $word) {
            if (ArabicNormalizer::containsWord($hay, $word)) {
                $covered++;
                $head = $head || $i === 0;
            }
        }
        if ($covered < count($words)) {
            foreach ($request->expandedTokens as $synonym) {
                if (ArabicNormalizer::containsWord($hay, $synonym)) {
                    $covered++;
                    /* المرادف بديلُ كلمةٍ غائبة؛ إن غابت الأولى فهو بديلُها. */
                    $head = true;
                    break;
                }
            }
        }

        return [$covered, $head];
    }

    /**
     * يُحمّل نصّ الملفّات المطويّ لمجموعة منتجات باستعلامٍ واحد.
     *
     * الغائب يُسجَّل `''` لا يُترك خارج الخريطة: «سألنا فلا نصّ» ليست
     * «لم نسأل بعد»، والخلط يُعيد الاستعلام لكلّ منتجٍ بلا ملفّات في كلّ
     * دورة.
     *
     * @param  list<int>  $productIds
     */
    private function primeExtracted(array $productIds): void
    {
        $missing = array_values(array_diff($productIds, array_keys($this->extractedFold)));

        if ($missing === []) {
            return;
        }

        $raw = ProductSearchProjector::extractedTextFor($missing);

        foreach ($missing as $id) {
            // الطيّ على الطرفين: الاستعلام مطويٌّ أصلًا، والنصّ يُطوى هنا.
            $this->extractedFold[$id] = ArabicNormalizer::normalize((string) ($raw[$id] ?? ''));
        }
    }

    /** نصّ ملفّات منتجٍ واحد؛ يستعلم عند الحاجة فيصحّ نداء `score()` منفردًا. */
    private function extractedFoldFor(Product $product): string
    {
        $id = (int) $product->id;

        if (! array_key_exists($id, $this->extractedFold)) {
            $this->primeExtracted([$id]);
        }

        return $this->extractedFold[$id];
    }

    /**
     * الطيّ المخزَّن على صفّ المنتج، أو null إن لم يكن موثوقًا به.
     *
     * البصمة تُحسب هنا في كلّ نداء — وهي `md5` على النصّ الخام، أرخص بمراتب
     * من ثلاث مرّات `normalize` (تطبيع يونيكود ثمّ أربع خرائط استبدال ثمّ
     * تعبير نمطيّ). والبديل المرفوض: الوثوق بالعمود بلا بصمة. يُسقط حتّى
     * `md5`، لكنّ `SyncProductJob::handle` يعود فورًا حين لا يكون المزوّد
     * `meilisearch` — وهو حال النشر القائم (`config/search.php:16`) — فلا
     * يمرّ تعديلُ منتجٍ بالمُسقِط، ويبقى في العمود عنوانٌ قديم يُطابَق عليه
     * بثقة. وبحثٌ يكذب صامتًا أسوأ من بحثٍ يعيد الحساب.
     *
     * @return array{v: int, src: string, sku: string, title: string, body: string, desc: string}|null
     */
    private function storedFold(Product $product): ?array
    {
        $fold = ProductSearchProjector::decodeFold($product->getAttribute(ProductSearchProjector::FOLDED_COLUMN));

        $current = ProductSearchProjector::fingerprintOf(
            $product->sku, $product->title, $product->subtitle, $product->short_desc, $product->description
        );

        if ($fold === null || $fold['src'] !== $current) {
            return null;
        }

        return $fold;
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @param  array<int, int>  $scored
     * @return Collection<int, Product>
     */
    private function order(Collection $candidates, array $scored, string $sort): Collection
    {
        $effective = fn (Product $p) => $p->price === null
            ? PHP_INT_MAX
            : (PricingService::activeSaleMinor($p->price) ?? $p->price->regular_minor);

        return match ($sort) {
            'newest' => $candidates->sortByDesc(fn (Product $p) => $p->published_at?->getTimestamp() ?? 0)->values(),
            'price_asc' => $candidates->sortBy($effective)->values(),
            'price_desc' => $candidates->sortByDesc($effective)->values(),
            'bestselling' => $this->orderBySales($candidates, $scored),
            default => $candidates
                ->sort(fn (Product $a, Product $b) => [$scored[$b->id] ?? 0, $b->published_at?->getTimestamp() ?? 0]
                    <=> [$scored[$a->id] ?? 0, $a->published_at?->getTimestamp() ?? 0])
                ->values(),
        };
    }

    /**
     * Bestselling = REAL entitlement counts per product (no fabricated sales).
     *
     * @param  Collection<int, Product>  $candidates
     * @param  array<int, int>  $scored
     * @return Collection<int, Product>
     */
    private function orderBySales(Collection $candidates, array $scored): Collection
    {
        $sales = DB::table('entitlements')
            ->whereIn('product_id', $candidates->pluck('id'))
            ->select('product_id', DB::raw('count(*) as n'))
            ->groupBy('product_id')
            ->pluck('n', 'product_id')
            ->all();

        return $candidates
            ->sort(fn (Product $a, Product $b) => [(int) ($sales[$b->id] ?? 0), $scored[$b->id] ?? 0]
                <=> [(int) ($sales[$a->id] ?? 0), $scored[$a->id] ?? 0])
            ->values();
    }
}
