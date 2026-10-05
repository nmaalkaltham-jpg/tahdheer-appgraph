<?php

namespace App\Domain\SEO;

use App\Domain\SEO\Models\ProductAuthor;
use App\Domain\SEO\Models\ProductFaq;
use App\Models\Product;

/**
 * يجمّع حمولة SEO لصفحة المنتج مرة واحدة.
 *
 * This assembler is where the «visible = schema» law is ENFORCED rather than
 * merely documented: it builds ONE {@see VisibleContent}, hands that same
 * instance to {@see SchemaBuilder}, and ships it to the storefront alongside
 * the resulting graph. The renderer and the markup literally cannot disagree,
 * because they are fed by the same object.
 */
final class ProductPageSeo
{
    public function __construct(
        private readonly MetadataService $metadata,
        private readonly SchemaBuilder $schema,
        private readonly RouteRegistry $routes,
        private readonly InternalLinks $links,
    ) {}

    /**
     * @return array{metadata: array<string, mixed>, visible: array<string, mixed>, schema: array<string, mixed>, internalLinks: list<array{title: string, links: list<array{label: string, href: string}>}>}
     */
    public function forProduct(Product $product): array
    {
        $visible = $this->visibleContent($product);

        return [
            'metadata' => $this->metadata->forProduct($product),
            'visible' => $visible->toArray(),
            'schema' => $this->schema->productPage($product, $visible),
            /*
             * كتلة التنقّل الداخليّ — روابطُ ملاحةٍ لا محتوى: خارج `visible`
             * لأنّها لا تدخل الرسم البيانيّ، فلا يلزمها قانون «المرئيّ = المخطّط».
             */
            'internalLinks' => $this->links->forProduct($product),
        ];
    }

    /** Everything the PDP renders, gathered once. */
    public function visibleContent(Product $product): VisibleContent
    {
        $description = $this->metadata->description($product)['text'];

        return new VisibleContent(
            breadcrumbs: $this->breadcrumbs($product),
            faqs: $this->faqs($product),
            facts: $this->facts($product),
            author: $this->author($product),
            description: $description,
            image: $this->coverImage($product),
        );
    }

    /**
     * Breadcrumb trail from the real curriculum relations.
     *
     * A crumb is only LINKED when its route class is actually built — a link to
     * an unbuilt route is a 404, and a BreadcrumbList item pointing at a 404 is
     * a schema error. Unlinked crumbs keep their name, which is honest: the
     * hierarchy is real even when the landing page is not live yet.
     *
     * @return list<array{name: string, url: string}>
     */
    public function breadcrumbs(Product $product): array
    {
        $crumbs = [['name' => 'الرئيسية', 'url' => '/']];

        $hierarchy = [
            ['relation' => 'stage', 'route' => 'stages', 'prefix' => '/stages/'],
            ['relation' => 'grade', 'route' => 'grades', 'prefix' => '/grades/'],
            ['relation' => 'semester', 'route' => 'semesters', 'prefix' => '/semesters/'],
            ['relation' => 'subject', 'route' => 'subjects', 'prefix' => '/subjects/'],
        ];

        foreach ($hierarchy as $level) {
            $relation = $level['relation'];

            if (! $product->relationLoaded($relation) || $product->{$relation} === null) {
                continue;
            }

            if (! $this->routes->isLive($level['route'])) {
                continue;
            }

            $crumbs[] = [
                'name' => (string) $product->{$relation}->name_ar,
                // نفس بناء المسار الذي تعتمده الخريطة والمتجر — فتات تُشير
                // إلى عنوان غير الذي يُخدم هي رابط مكسور في بيانات مُهيكلة.
                'url' => TaxonomyPath::join($level['prefix'], (string) $product->{$relation}->slug),
            ];
        }

        $crumbs[] = ['name' => $product->title, 'url' => '/products/'.$product->slug];

        return $crumbs;
    }

    /**
     * Authored FAQ rows in display order. No rows ⇒ empty array ⇒ no FAQPage.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function faqs(Product $product): array
    {
        return ProductFaq::query()
            ->where('product_id', $product->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductFaq $faq) => [
                'question' => (string) $faq->question,
                'answer' => (string) $faq->answer,
            ])
            ->values()
            ->all();
    }

    /**
     * The typed facts the PDP renders in its facts table.
     *
     * @return array<string, scalar|null>
     */
    public function facts(Product $product): array
    {
        $facts = [];

        $rows = $product->relationLoaded('facts') ? $product->facts : $product->facts()->get();

        foreach ($rows as $fact) {
            $facts[(string) $fact->key] = $fact->int_value ?? $fact->text_value;
        }

        return $facts;
    }

    /**
     * The first consented author, or null. Consent is opt-in.
     *
     * @return array{name: string, url: ?string, bio: ?string}|null
     */
    public function author(Product $product): ?array
    {
        $author = ProductAuthor::query()
            ->where('product_id', $product->id)
            ->where('consented', true)
            ->orderBy('id')
            ->first();

        if ($author === null) {
            return null;
        }

        return [
            'name' => (string) $author->name,
            'url' => $author->profile_url,
            'bio' => $author->bio,
        ];
    }

    /** Cover image URL as rendered by the gallery, or null. */
    private function coverImage(Product $product): ?string
    {
        $image = $this->metadata->imageFor($product);

        // Only a real product image counts as page-visible; the logo fallback
        // is an OG-only asset and must not become a Product `image` claim.
        return $image['source'] === 'logo' ? null : $image['url'];
    }
}
