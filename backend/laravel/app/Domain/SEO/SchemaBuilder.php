<?php

namespace App\Domain\SEO;

use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductCapabilities;
use App\Domain\Inventory\AvailabilityState;
use App\Domain\Pricing\PricingService;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\ReviewFeed;
use App\Domain\Shipping\ShippingRateResolver;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * باني البيانات المهيكلة الوحيد — رسم بياني واحد لكل صفحة.
 *
 * MIP §7.4. The hard rules, all covered by tests:
 *   · EXACTLY ONE `@graph` per page. There is no second JSON-LD writer.
 *   · Nothing enters the markup that the page does not visibly render — every
 *     optional node is driven by {@see VisibleContent}, not by a database read
 *     the renderer never saw.
 *   · NO expired sale price, ever. The Offer price comes from
 *     {@see PricingService::activeSaleMinor()}, which honours sale_from/sale_to.
 *   · AggregateRating only at ≥3 real approved reviews. `review_aggregates` is
 *     read DEFENSIVELY (it is filled by another domain): a missing table, a
 *     missing row, a missing column or a count below the floor all produce the
 *     same result — no aggregateRating node at all, never a zero.
 */
final class SchemaBuilder
{
    /** §7.4 / §4.6: aggregateRating requires at least this many real reviews. */
    public const MIN_REVIEWS_FOR_AGGREGATE = 3;

    /**
     * سياسة المتجر المُعلَنة: البضاعة جديدة.
     *
     * ثابتٌ واحد بدل ثلاثة نصوصٍ متفرّقة كانت تتفق بالصدفة. وهو **سياسة
     * معلَنة** لا حقيقةٌ تُقرأ من عمود: لا عمود حالةٍ على الصيغة ولا على
     * العرض. فإن بيع المستعمل يومًا وجب أن يُقرَأ من صفٍّ لا أن يُفترض هنا.
     */
    private const ITEM_CONDITION = 'https://schema.org/NewCondition';

    public function __construct(
        private readonly SiteIdentity $site,
        private readonly EducationSchemaMapper $education,
        private readonly AvailabilityState $availability,
        private readonly ShippingRateResolver $rates,
    ) {}

    /**
     * The site-level nodes present on every page: Organization, WebSite and
     * the WebSite SearchAction.
     *
     * @return list<array<string, mixed>>
     */
    public function siteNodes(): array
    {
        $home = $this->site->url('/');

        return [
            [
                '@type' => 'Organization',
                '@id' => $this->site->organizationId(),
                'name' => SiteIdentity::BRAND,
                'url' => $home,
            ],
            [
                '@type' => 'WebSite',
                '@id' => $this->site->websiteId(),
                'url' => $home,
                'name' => SiteIdentity::BRAND,
                'inLanguage' => 'ar-SA',
                'publisher' => ['@id' => $this->site->organizationId()],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => $this->site->url('/search?q={search_term_string}'),
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ];
    }

    /**
     * The complete graph for a product page.
     *
     * @return array{'@context': string, '@graph': list<array<string, mixed>>}
     */
    public function productPage(Product $product, VisibleContent $visible): array
    {
        $url = $this->site->url('/products/'.$product->slug);
        $productId = $url.'#product';

        $nodes = $this->siteNodes();

        if ($visible->breadcrumbs !== []) {
            $nodes[] = $this->breadcrumbList($visible->breadcrumbs, $url);
        }

        $productNode = [
            '@type' => 'Product',
            '@id' => $productId,
            'name' => $product->title,
            'url' => $url,
            'sku' => $product->sku,
            'brand' => ['@id' => $this->site->organizationId()],
        ];

        // Description is the one the page shows — not a second, richer copy.
        if ($visible->description !== null) {
            $productNode['description'] = $visible->description;
        }

        if ($visible->image !== null) {
            $productNode['image'] = $visible->image;
        }

        /*
         * §٦٩ §٧٠ — العرض يتبع الصيغ لا السعر المفرد.
         *
         * منتجٌ بثلاث صيغٍ (٣٠ و٢٥ و٩٠) كان يُعلن ثمنًا واحدًا لجوجل. والنتيجة
         * الغنيّة تُقارَن بما في الصفحة: اختلافُهما يُسقط النتيجة، وقد يجلب
         * إجراءً يدويًّا. `AggregateOffer` يقول الحقيقة: من كذا إلى كذا.
         */
        $offers = $this->offersFor($product, $url);

        if ($offers !== null) {
            $productNode['offers'] = $offers;
        }

        $variantNodes = $this->variantNodes($product, $url);

        if ($variantNodes !== []) {
            $productNode['hasVariant'] = array_map(
                static fn (array $node) => ['@id' => $node['@id']],
                $variantNodes,
            );
            foreach ($variantNodes as $node) {
                $nodes[] = $node;
            }
        }

        $rating = $this->aggregateRating((int) $product->id);

        if ($rating !== null) {
            $productNode['aggregateRating'] = $rating;

            /*
             * المراجعات المفردة تتبع بوّابة المعدّل نفسها ولا تعيش بعدها: دون
             * العتبة لا معدّل، ومراجعةٌ مفردةٌ حينئذ تُظهر نجمةً في نتيجة
             * البحث لا تقابلها حصيلةٌ في الصفحة.
             */
            $reviews = $this->reviewNodes((int) $product->id);

            if ($reviews !== []) {
                $productNode['review'] = $reviews;
            }
        }

        $education = $this->education->entity($product, $visible->facts);

        if ($education !== null) {
            // Legacy @id linking: the educational node is separate and referenced,
            // so the Product node is never duplicated.
            $education['mainEntityOfPage'] = ['@id' => $url];
            $productNode['subjectOf'] = ['@id' => $education['@id']];
            $nodes[] = $education;
        }

        $nodes[] = $productNode;

        if ($visible->faqs !== []) {
            $nodes[] = $this->faqPage($visible->faqs, $url);
        }

        if ($visible->author !== null) {
            foreach ($this->authorNodes($visible->author, $url) as $node) {
                $nodes[] = $node;
            }
        }

        return ['@context' => 'https://schema.org', '@graph' => array_values($nodes)];
    }

    /**
     * BreadcrumbList from the SAME array the visible breadcrumb nav renders.
     *
     * @param  list<array{name: string, url: string}>  $breadcrumbs
     * @return array<string, mixed>
     */
    public function breadcrumbList(array $breadcrumbs, string $pageUrl): array
    {
        $items = [];
        $position = 1;

        foreach ($breadcrumbs as $crumb) {
            $name = TextBudget::normalize($crumb['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $item = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $name,
            ];

            $itemUrl = $crumb['url'] ?? '';

            if (is_string($itemUrl) && $itemUrl !== '') {
                $item['item'] = str_starts_with($itemUrl, 'http')
                    ? $itemUrl
                    : $this->site->url($itemUrl);
            }

            $items[] = $item;
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $pageUrl.'#breadcrumbs',
            'itemListElement' => $items,
        ];
    }

    /**
     * FAQPage from real authored rows only.
     *
     * @param  list<array{question: string, answer: string}>  $faqs
     * @return array<string, mixed>
     */
    private function faqPage(array $faqs, string $pageUrl): array
    {
        $questions = [];

        foreach ($faqs as $faq) {
            $question = TextBudget::normalize($faq['question'] ?? null);
            $answer = TextBudget::normalize($faq['answer'] ?? null);

            // A question without an answer is not a FAQ — it is a gap.
            if ($question === null || $answer === null) {
                continue;
            }

            $questions[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }

        return [
            '@type' => 'FAQPage',
            '@id' => $pageUrl.'#faq',
            'mainEntity' => $questions,
        ];
    }

    /**
     * ProfilePage + Person for a real, consented author.
     *
     * @param  array{name: string, url: ?string, bio: ?string}  $author
     * @return list<array<string, mixed>>
     */
    private function authorNodes(array $author, string $pageUrl): array
    {
        $name = TextBudget::normalize($author['name'] ?? null);

        if ($name === null) {
            return [];
        }

        $personId = $pageUrl.'#author';

        $person = ['@type' => 'Person', '@id' => $personId, 'name' => $name];

        $bio = TextBudget::normalize($author['bio'] ?? null);

        if ($bio !== null) {
            $person['description'] = $bio;
        }

        $profileUrl = $author['url'] ?? null;

        if (is_string($profileUrl) && $profileUrl !== '') {
            $person['url'] = str_starts_with($profileUrl, 'http')
                ? $profileUrl
                : $this->site->url($profileUrl);
        }

        return [
            $person,
            [
                '@type' => 'ProfilePage',
                '@id' => $pageUrl.'#profile',
                'mainEntity' => ['@id' => $personId],
            ],
        ];
    }

    /**
     * العروض: عرضٌ مفرد لصيغةٍ واحدة، ومُجمَّعٌ لأكثر.
     *
     * `AggregateOffer` نوعٌ قياسيّ تفهمه محرّكات البحث، ويحمل الحدّين وعددَ
     * العروض. وهو الصدق الوحيد الممكن حين تختلف أثمان الصيغ.
     *
     * @return array<string, mixed>|null
     */
    private function offersFor(Product $product, string $url): ?array
    {
        $prices = $this->variantPrices($product);

        if (count($prices) <= 1) {
            return $this->offer($product, $url);
        }

        $node = [
            '@type' => 'AggregateOffer',
            '@id' => $url.'#offers',
            'url' => $url,
            'lowPrice' => $this->money(min($prices)),
            'highPrice' => $this->money(max($prices)),
            'priceCurrency' => 'SAR',
            'offerCount' => count($prices),
            'itemCondition' => self::ITEM_CONDITION,
            'seller' => ['@id' => $this->site->organizationId()],
        ];

        /*
         * التوفّر من المخزون لا من حالة النشر.
         *
         * كان `status === 'published'` يُعلن «متوفّر» لكلّ منتجٍ منشور ولو نفد
         * مخزونه كلُّه — إعلانٌ كاذب يُعاقَب عليه في نتائج التسوّق ويُرسل
         * زائرًا إلى صفحةٍ لا يشتري منها. والمجمَّع «متوفّر» إن توفّر **أيُّ**
         * عرضٍ من عروضه، وذاك معنى التجميع.
         */
        $state = $this->aggregateAvailability($product);
        $schemaUrl = AvailabilityState::schemaUrl($state);

        if ($schemaUrl !== null) {
            $node['availability'] = $schemaUrl;
        }

        return $node;
    }

    /**
     * أثمان الصيغ النافذة، أو ثمن المنتج القديم حين لا مجموعة.
     *
     * @return list<int>
     */
    /**
     * حالة توفّر المنتج، مجموعةً من عروضه النافذة.
     *
     * «متوفّر» إن توفّر **أيُّ** عرض: منتجٌ صيغتُه المطبوعة نفدت وملفُّه
     * الرقميّ متاح ما يزال يُشترى. و«مجهول» حين لا عرض يُسأل عنه أصلًا —
     * منتجٌ قديم بلا مجموعة — فتُسقط العقدة ولا يُخترع جواب.
     */
    private function aggregateAvailability(Product $product): string
    {
        $offers = $this->activeOffers($product);

        if ($offers === []) {
            /*
             * لا عرض ⇒ لا مخزون يُسأل عنه. والمنتجُ القديم يُسلَّم رقميًّا
             * بلا عدّ، فحالته `IN_STOCK` ما دام منشورًا — وهذا **سقوطٌ
             * معلَن** لا افتراضٌ مخبوء: الحالة السابقة للنظام كلّه.
             */
            return ($product->status ?? null) === 'published'
                ? AvailabilityState::IN_STOCK
                : AvailabilityState::OUT_OF_STOCK;
        }

        $states = array_map(fn (ProductOffer $o) => $this->availability->forOffer($o), $offers);

        foreach ([AvailabilityState::IN_STOCK, AvailabilityState::PRE_ORDER, AvailabilityState::BACK_ORDER] as $good) {
            if (in_array($good, $states, true)) {
                return $good;
            }
        }

        // كلُّها إمّا نافدة وإمّا مجهولة. النافدة تُقال، والمجهولة تُسكَت عنها.
        return in_array(AvailabilityState::OUT_OF_STOCK, $states, true)
            ? AvailabilityState::OUT_OF_STOCK
            : AvailabilityState::UNKNOWN;
    }

    /** @return list<ProductOffer> العروض النافذة تحت مجموعة هذا المنتج. */
    private function activeOffers(Product $product): array
    {
        $group = ProductGroup::query()
            ->where('legacy_product_id', $product->id)
            ->with('variants.offers.fulfillments')
            ->first();

        if ($group === null) {
            return [];
        }

        return $group->variants
            ->flatMap(fn (ProductVariant $v) => $v->offers)
            ->filter(fn (ProductOffer $o) => $o->status === 'active')
            ->values()
            ->all();
    }

    /**
     * §٢٦ — تفاصيل الشحن، من مناطق ورسومٍ حقيقيّة أو لا شيء.
     *
     * ─── لماذا «أو لا شيء» شرطٌ لا تهرّب ───
     *
     * عقدةُ شحنٍ بأزمنة تسليمٍ مخترَعة أسوأ من غيابها: تُعرَض في نتائج
     * التسوّق وعدًا للزبون، ويُحاسَب عليها المتجر. وما يعرفه هذا المستودع
     * هو رسمٌ محسوب و`max_days` مسجَّل على قاعدة السعر — ولا يعرف زمنَ
     * التجهيز ولا ساعةَ القطع. فيُعلَن ما يُعرف ويُسكَت عمّا لا يُعرف.
     *
     * والسؤال «هل يُشحن؟» يُطرح على استراتيجيّات العرض لا على اسم طبيعة:
     * عرضٌ استلامُه من المتجر لا يُشحن وإن كان منتجُه ماديًّا.
     *
     * @return array<string, mixed>
     */
    private function shippingDetails(Product $product): array
    {
        $offers = $this->activeOffers($product);

        $shipped = array_filter($offers, fn (ProductOffer $o) => $o->fulfillments
            ->contains(fn ($f) => ProductCapabilities::requiresShipping($f->strategy)));

        if ($shipped === []) {
            return [];
        }

        /** @var ProductOffer $offer */
        $offer = reset($shipped);
        $variant = $offer->variant ?? ProductVariant::query()->find($offer->product_variant_id);
        $weight = $variant?->weight_grams;

        /*
         * بلا وزنٍ لا رسمَ يُحسب — و`ShipmentExecutor` يرفض الشحن بدونه
         * أصلًا. فالصمت هنا يطابق ما يفعله التسليم فعلًا.
         */
        if ($weight === null) {
            return [];
        }

        $rate = $this->rates->cheapestFor(
            countryCode: 'SA',
            region: null,
            weightGrams: (int) $weight,
            orderMinor: $offer->effectivePriceMinor(),
        );

        if ($rate === null) {
            return [];
        }

        $details = [
            '@type' => 'OfferShippingDetails',
            'shippingRate' => [
                '@type' => 'MonetaryAmount',
                'value' => $this->money((int) $rate->price_minor),
                'currency' => 'SAR',
            ],
            'shippingDestination' => [
                '@type' => 'DefinedRegion',
                'addressCountry' => 'SA',
            ],
        ];

        /*
         * زمنُ العبور وحده يُعلَن، وزمنُ التجهيز يُترك: لا عمود له. وإعلانُ
         * صفرٍ للتجهيز يَعِد بشحنٍ في اليوم نفسه — وعدٌ لا يملكه أحد هنا.
         */
        if ($rate->max_days !== null) {
            $details['deliveryTime'] = [
                '@type' => 'ShippingDeliveryTime',
                'transitTime' => [
                    '@type' => 'QuantitativeValue',
                    'maxValue' => (int) $rate->max_days,
                    'unitCode' => 'DAY',
                ],
            ];
        }

        return $details;
    }

    /*
     * ─── ولا `hasMerchantReturnPolicy` ───
     *
     * جدول `return_policies` بلا نموذجٍ ولا خدمةٍ ولا نقطة نهاية ولا قارئٍ
     * في التطبيق، و`product_offers.return_policy_id` فارغٌ في كلّ صفّ. فكلّ
     * حقلٍ تحتاجه العقدة — مدّة الإرجاع ومن يدفع الشحن ورسم إعادة التخزين —
     * كان سيُخترع. والناقص الحقيقيّ سطحُ تحرير سياسات الإرجاع، لا عقدةٌ في
     * بيانات المدرسة.
     */

    private function variantPrices(Product $product): array
    {
        $group = ProductGroup::query()
            ->where('legacy_product_id', $product->id)
            ->with('variants.offers')
            ->first();

        if ($group !== null) {
            $prices = $group->variants
                ->flatMap(fn (ProductVariant $v) => $v->offers)
                ->filter(fn (ProductOffer $o) => $o->status === 'active')
                ->map(fn (ProductOffer $o) => $o->effectivePriceMinor())
                ->values()->all();

            if ($prices !== []) {
                return $prices;
            }
        }

        $price = $product->relationLoaded('price') ? $product->price : $product->price()->first();

        return $price === null ? [] : [PricingService::activeSaleMinor($price) ?? (int) $price->regular_minor];
    }

    /**
     * عقدةُ `Product` لكلّ صيغة (§٧٠).
     *
     * لكلٍّ رمزُها وثمنُها: الصيغ لا تتشارك SKU، وإعلانُ رمزٍ واحد لثلاث
     * صيغٍ يجعل تغذيةَ التسوّق ترفضها بوصفها مكرّرة.
     *
     * ولا تُصدَر إلّا حين تكون الصيغ أكثر من واحدة: صيغةٌ واحدة هي المنتج
     * نفسه، وإفرادُها عقدةً يُنتج تكرارًا في الرسم البيانيّ.
     *
     * @return list<array<string, mixed>>
     */
    private function variantNodes(Product $product, string $url): array
    {
        $group = ProductGroup::query()
            ->where('legacy_product_id', $product->id)
            ->with('variants.offers')
            ->first();

        if ($group === null || $group->variants->count() <= 1) {
            return [];
        }

        $nodes = [];

        foreach ($group->variants as $variant) {
            $offer = $variant->offers->firstWhere('status', 'active');

            if ($offer === null) {
                continue; // صيغةٌ بلا عرضٍ نشط لا تُعلَن قابلةً للشراء
            }

            $nodes[] = [
                '@type' => 'Product',
                '@id' => $url.'#variant-'.$variant->id,
                'name' => $variant->title ?? $group->title,
                'sku' => $variant->sku,
                'brand' => ['@id' => $this->site->organizationId()],
                'offers' => [
                    '@type' => 'Offer',
                    '@id' => $url.'#variant-'.$variant->id.'-offer',
                    'url' => $url,
                    'price' => $this->money($offer->effectivePriceMinor()),
                    'priceCurrency' => $offer->currency ?? 'SAR',
                    'availability' => $offer->status === 'active'
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'seller' => ['@id' => $this->site->organizationId()],
                ],
            ];
        }

        return $nodes;
    }

    /**
     * Offer built from the price that is actually in effect right now.
     *
     * An expired sale is invisible to this method: PricingService returns null
     * once `sale_to` has passed, so the regular price is emitted instead. There
     * is no code path that can reach `sale_minor` directly.
     *
     * @return array<string, mixed>|null
     */
    private function offer(Product $product, string $url): ?array
    {
        $price = $product->relationLoaded('price') ? $product->price : $product->price()->first();

        if ($price === null) {
            return null;
        }

        $activeSaleMinor = PricingService::activeSaleMinor($price);
        $effectiveMinor = $activeSaleMinor ?? (int) $price->regular_minor;

        $offer = [
            '@type' => 'Offer',
            '@id' => $url.'#offer',
            'url' => $url,
            'price' => $this->money($effectiveMinor),
            'priceCurrency' => $price->currency ?? 'SAR',
            'itemCondition' => self::ITEM_CONDITION,
            'seller' => ['@id' => $this->site->organizationId()],
        ];

        /* التوفّر من المخزون — و«مجهول» تُسقط العقدة ولا تُملأ بـ«متوفّر». */
        $schemaUrl = AvailabilityState::schemaUrl($this->aggregateAvailability($product));

        if ($schemaUrl !== null) {
            $offer['availability'] = $schemaUrl;
        }

        /* §٢٦ — تفاصيل الشحن، من مناطق ورسوم حقيقيّة أو لا شيء. */
        $shipping = $this->shippingDetails($product);

        if ($shipping !== []) {
            $offer['shippingDetails'] = $shipping;
        }

        // priceValidUntil is only meaningful while a sale is genuinely running.
        if ($activeSaleMinor !== null && $price->sale_to !== null) {
            $offer['priceValidUntil'] = $price->sale_to->toDateString();
        }

        return $offer;
    }

    /**
     * AggregateRating, read defensively from a table another domain owns.
     *
     * @return array<string, mixed>|null
     */
    private function aggregateRating(int $productId): ?array
    {
        if (! Schema::hasTable('review_aggregates')) {
            return null;
        }

        if (! Schema::hasColumn('review_aggregates', 'count')
            || ! Schema::hasColumn('review_aggregates', 'avg_rating')) {
            return null;
        }

        $row = DB::table('review_aggregates')->where('product_id', $productId)->first();

        if ($row === null) {
            return null;
        }

        $count = (int) ($row->count ?? 0);
        $avg = $row->avg_rating ?? null;

        // Below the floor, or no real average: emit NOTHING. Never a zero.
        if ($count < self::MIN_REVIEWS_FOR_AGGREGATE || $avg === null) {
            return null;
        }

        return [
            '@type' => 'AggregateRating',
            'ratingValue' => (string) round((float) $avg, 2),
            'reviewCount' => $count,
            'bestRating' => '5',
            'worstRating' => '1',
        ];
    }

    /**
     * عُقَد `Review` من المراجعات نفسها التي تُصيَّر في الصفحة.
     *
     * الصفحة تطلب الصفحة الأولى من {@see ReviewFeed} بترتيبها الافتراضيّ
     * (الأنفع ثمّ المعرّف)، فيُعاد البناء هنا بالترتيب والعدد ذاتهما: مراجعةٌ
     * في البيانات المهيكلة لا يجدها الزائر في الصفحة هي الخلاف الذي يُسقط
     * النتيجة الغنيّة.
     *
     * والتصفية بعد الجلب لا داخله عمدًا: شرطٌ في الاستعلام كان سيسحب مراجعةً
     * من الصفحة الثانية مكان المستبعَدة، فتُعلَن مراجعةٌ لا تُعرض.
     *
     * ويُشترط طلبٌ حقيقيّ خلف المراجعة — وهو المعنى نفسه الذي تعلنه الصفحة
     * بـ«شراء موثّق» — ونصٌّ مكتوب: مراجعةٌ بلا نصّ ليست `Review` بل تقييمٌ
     * تحمله الحصيلة وحدها.
     *
     * @return list<array<string, mixed>>
     */
    private function reviewNodes(int $productId): array
    {
        $rows = Review::query()
            ->approved()
            ->where('product_id', $productId)
            ->with('customer:id,name')
            ->orderByDesc('helpful_count')
            ->orderByDesc('id')
            ->limit(ReviewFeed::PER_PAGE)
            ->get();

        $nodes = [];

        foreach ($rows as $review) {
            if ($review->order_id === null) {
                continue;
            }

            $body = TextBudget::normalize($review->body);

            if ($body === null) {
                continue;
            }

            $node = [
                '@type' => 'Review',
                'author' => [
                    '@type' => 'Person',
                    // الاسم كما تعرضه الصفحة بالضبط: «نورة ع.» لا الاسم الكامل.
                    'name' => ReviewResource::displayName($review->customer?->name),
                ],
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => (string) (int) $review->rating,
                    'bestRating' => '5',
                    'worstRating' => '1',
                ],
                'reviewBody' => $body,
            ];

            $published = $review->created_at?->toDateString();

            if ($published !== null) {
                $node['datePublished'] = $published;
            }

            $name = TextBudget::normalize($review->title);

            if ($name !== null) {
                $node['name'] = $name;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /** Integer halalas → the decimal string schema.org expects. */
    private function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
