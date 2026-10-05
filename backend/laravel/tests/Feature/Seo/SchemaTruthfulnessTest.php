<?php

namespace Tests\Feature\Seo;

use App\Domain\SEO\Models\ProductAuthor;
use App\Domain\SEO\Models\ProductFaq;
use App\Domain\SEO\ProductPageSeo;
use App\Domain\SEO\SchemaBuilder;
use App\Domain\SEO\VisibleContent;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MIP §7.4 hard rules. These are the tests that make «visible = schema» a
 * property of the system rather than a promise in a comment.
 */
class SchemaTruthfulnessTest extends TestCase
{
    use RefreshDatabase, SeedsSeoFixtures;

    private SchemaBuilder $schema;

    private ProductPageSeo $page;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://example.test']);
        $this->schema = app(SchemaBuilder::class);
        $this->page = app(ProductPageSeo::class);
    }

    /** @return list<array<string, mixed>> */
    private function nodesOfType(array $graph, string $type): array
    {
        return array_values(array_filter(
            $graph['@graph'],
            static fn (array $node) => ($node['@type'] ?? null) === $type,
        ));
    }

    public function test_a_page_emits_exactly_one_graph_with_one_product_node(): void
    {
        $product = $this->makeProduct('TD-G1', 'عرض بوربوينت الضوء');
        $this->makePrice($product, 2500);

        $graph = $this->schema->productPage($product, new VisibleContent);

        $this->assertSame('https://schema.org', $graph['@context']);
        $this->assertArrayHasKey('@graph', $graph);
        // One @context key, one @graph key — there is no second JSON-LD block.
        $this->assertSame(['@context', '@graph'], array_keys($graph));
        $this->assertCount(1, $this->nodesOfType($graph, 'Product'));
        $this->assertCount(1, $this->nodesOfType($graph, 'Organization'));
        $this->assertCount(1, $this->nodesOfType($graph, 'WebSite'));

        $website = $this->nodesOfType($graph, 'WebSite')[0];
        $this->assertSame('SearchAction', $website['potentialAction']['@type']);
    }

    public function test_no_aggregate_rating_below_three_real_reviews(): void
    {
        $product = $this->makeProduct('TD-R1', 'منتج');
        $this->makePrice($product, 1000);

        // No aggregate row at all.
        $graph = $this->schema->productPage($product, new VisibleContent);
        $this->assertArrayNotHasKey('aggregateRating', $this->nodesOfType($graph, 'Product')[0]);

        // A real row, but below the floor: still nothing. NEVER a zero rating.
        $this->makeReviewAggregate($product, 5.0, 2);
        $graph = $this->schema->productPage($product, new VisibleContent);
        $this->assertArrayNotHasKey('aggregateRating', $this->nodesOfType($graph, 'Product')[0]);
    }

    public function test_aggregate_rating_appears_at_exactly_three_reviews(): void
    {
        $product = $this->makeProduct('TD-R2', 'منتج');
        $this->makePrice($product, 1000);
        $this->makeReviewAggregate($product, 4.67, SchemaBuilder::MIN_REVIEWS_FOR_AGGREGATE);

        $rating = $this->nodesOfType(
            $this->schema->productPage($product, new VisibleContent),
            'Product',
        )[0]['aggregateRating'];

        $this->assertSame('4.67', $rating['ratingValue']);
        $this->assertSame(3, $rating['reviewCount']);
    }

    public function test_an_empty_review_aggregates_table_is_read_defensively(): void
    {
        // Another domain fills this table; an empty one must never break the PDP.
        $product = $this->makeProduct('TD-R3', 'منتج');
        $this->makePrice($product, 1000);

        $this->assertSame(0, \DB::table('review_aggregates')->count());
        $graph = $this->schema->productPage($product, new VisibleContent);

        $this->assertArrayNotHasKey('aggregateRating', $this->nodesOfType($graph, 'Product')[0]);
    }

    public function test_an_expired_sale_price_is_never_emitted(): void
    {
        $product = $this->makeProduct('TD-S1', 'منتج مخفَّض');
        $this->makePrice($product, 40000, [
            'sale_minor' => 30000,
            'sale_from' => now()->subMonth(),
            'sale_to' => now()->subDay(),   // ended yesterday
        ]);

        $offer = $this->nodesOfType(
            $this->schema->productPage($product, new VisibleContent),
            'Product',
        )[0]['offers'];

        $this->assertSame('400.00', $offer['price'], 'السعر النظامي بعد انتهاء التخفيض');
        $this->assertArrayNotHasKey('priceValidUntil', $offer, 'لا صلاحية لعرض منتهٍ');
    }

    public function test_a_future_sale_price_is_not_emitted_early(): void
    {
        $product = $this->makeProduct('TD-S2', 'منتج');
        $this->makePrice($product, 40000, [
            'sale_minor' => 30000,
            'sale_from' => now()->addWeek(),
        ]);

        $offer = $this->nodesOfType(
            $this->schema->productPage($product, new VisibleContent),
            'Product',
        )[0]['offers'];

        $this->assertSame('400.00', $offer['price']);
    }

    public function test_an_active_sale_price_is_emitted_with_its_real_end_date(): void
    {
        $product = $this->makeProduct('TD-S3', 'منتج');
        $endsAt = now()->addWeek();
        $this->makePrice($product, 40000, [
            'sale_minor' => 30000,
            'sale_from' => now()->subDay(),
            'sale_to' => $endsAt,
        ]);

        $offer = $this->nodesOfType(
            $this->schema->productPage($product, new VisibleContent),
            'Product',
        )[0]['offers'];

        $this->assertSame('300.00', $offer['price']);
        $this->assertSame('SAR', $offer['priceCurrency']);
        $this->assertSame($endsAt->toDateString(), $offer['priceValidUntil']);
        $this->assertSame('https://schema.org/InStock', $offer['availability']);
    }

    public function test_no_schema_is_emitted_for_content_the_page_does_not_render(): void
    {
        $product = $this->makeProduct('TD-V1', 'منتج');
        $this->makePrice($product, 1000);

        // Real FAQ and author rows EXIST in the database…
        ProductFaq::query()->create([
            'product_id' => $product->id,
            'question' => 'هل الملف قابل للتعديل؟',
            'answer' => 'نعم، بصيغة بوربوينت.',
        ]);
        ProductAuthor::query()->create([
            'product_id' => $product->id,
            'name' => 'معلّم حقيقي',
            'consented' => true,
        ]);

        // …but the page renders none of them, so the graph must not claim them.
        $graph = $this->schema->productPage($product, new VisibleContent);

        $this->assertSame([], $this->nodesOfType($graph, 'FAQPage'));
        $this->assertSame([], $this->nodesOfType($graph, 'Person'));
        $this->assertSame([], $this->nodesOfType($graph, 'ProfilePage'));
        $this->assertSame([], $this->nodesOfType($graph, 'BreadcrumbList'));
    }

    public function test_faq_page_is_built_from_the_same_array_the_page_renders(): void
    {
        $product = $this->makeProduct('TD-F1', 'منتج');
        $this->makePrice($product, 1000);
        ProductFaq::query()->create([
            'product_id' => $product->id,
            'question' => 'هل الملف قابل للتعديل؟',
            'answer' => 'نعم، بصيغة بوربوينت.',
            'position' => 0,
        ]);

        $payload = $this->page->forProduct($product->fresh(['price', 'facts', 'seo']));
        $faqNode = $this->nodesOfType($payload['schema'], 'FAQPage')[0];

        $this->assertCount(1, $payload['visible']['faqs']);
        $this->assertSame(
            $payload['visible']['faqs'][0]['question'],
            $faqNode['mainEntity'][0]['name'],
        );
        $this->assertSame(
            $payload['visible']['faqs'][0]['answer'],
            $faqNode['mainEntity'][0]['acceptedAnswer']['text'],
        );
    }

    public function test_a_question_without_an_answer_is_a_gap_not_a_faq(): void
    {
        $graph = $this->schema->productPage(
            $this->makeProduct('TD-F2', 'منتج'),
            new VisibleContent(faqs: [['question' => 'سؤال بلا إجابة', 'answer' => '   ']]),
        );

        $this->assertSame([], $this->nodesOfType($graph, 'FAQPage')[0]['mainEntity']);
    }

    public function test_only_a_consented_author_reaches_profile_page_markup(): void
    {
        $product = $this->makeProduct('TD-A1', 'منتج');
        ProductAuthor::query()->create([
            'product_id' => $product->id,
            'name' => 'مؤلف بلا موافقة',
            'consented' => false,
        ]);

        $this->assertNull($this->page->author($product), 'الموافقة اختيار صريح لا افتراض');

        ProductAuthor::query()->create([
            'product_id' => $product->id,
            'name' => 'مؤلف موافق',
            'bio' => 'معلّم لغة عربية.',
            'consented' => true,
        ]);

        $author = $this->page->author($product);
        $this->assertSame('مؤلف موافق', $author['name']);

        $graph = $this->schema->productPage($product, new VisibleContent(author: $author));
        $this->assertSame('مؤلف موافق', $this->nodesOfType($graph, 'Person')[0]['name']);
        $this->assertCount(1, $this->nodesOfType($graph, 'ProfilePage'));
    }

    public function test_breadcrumb_list_mirrors_the_visible_breadcrumb_array(): void
    {
        $graph = $this->schema->productPage(
            $this->makeProduct('TD-B1', 'منتج'),
            new VisibleContent(breadcrumbs: [
                ['name' => 'الرئيسية', 'url' => '/'],
                ['name' => 'منتج', 'url' => '/products/td-b1'],
            ]),
        );

        $items = $this->nodesOfType($graph, 'BreadcrumbList')[0]['itemListElement'];

        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]['position']);
        $this->assertSame('https://example.test/', $items[0]['item']);
        $this->assertSame('https://example.test/products/td-b1', $items[1]['item']);
    }

    public function test_a_product_without_educational_metadata_gets_no_education_node(): void
    {
        $product = $this->makeProduct('TD-E1', 'منتج بلا بيانات تعليمية', ['format' => null]);
        $graph = $this->schema->productPage($product, new VisibleContent);

        foreach (['LearningResource', 'Course', 'DigitalDocument'] as $type) {
            $this->assertSame([], $this->nodesOfType($graph, $type), $type.' بلا بيانات = لا كيان');
        }
    }

    public function test_a_digital_file_product_maps_to_digital_document(): void
    {
        $product = $this->makeProduct('TD-E2', 'عرض بوربوينت', [
            'format' => 'PPTX',
            'resource_type' => 'عرض بوربوينت',
        ]);
        $this->makePrice($product, 2500);

        $graph = $this->schema->productPage($product, new VisibleContent(facts: ['slides_count' => 42]));
        $node = $this->nodesOfType($graph, 'DigitalDocument')[0];

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            $node['encodingFormat'],
        );
        // Educational node is linked, never a duplicated Product.
        $this->assertSame(
            $node['@id'],
            $this->nodesOfType($graph, 'Product')[0]['subjectOf']['@id'],
        );
        $this->assertCount(1, $this->nodesOfType($graph, 'Product'));

        $slides = collect($node['additionalProperty'])->firstWhere('name', 'عدد الشرائح');
        $this->assertSame(42, $slides['value']);
    }

    public function test_a_product_with_no_price_row_emits_no_offer(): void
    {
        $product = $this->makeProduct('TD-P1', 'منتج بلا سعر');
        $product->setRelation('price', null);

        $node = $this->nodesOfType(
            $this->schema->productPage($product, new VisibleContent),
            'Product',
        )[0];

        $this->assertArrayNotHasKey('offers', $node, 'لا سعر ⇒ لا عرض مُختلَق');
    }

    public function test_the_graph_never_carries_a_product_image_that_is_only_the_logo(): void
    {
        $product = $this->makeProduct('TD-M1', 'منتج بلا صورة');
        $this->makePrice($product, 1000);

        $payload = $this->page->forProduct(Product::query()->find($product->id));

        $this->assertNull($payload['visible']['image']);
        $this->assertArrayNotHasKey(
            'image',
            $this->nodesOfType($payload['schema'], 'Product')[0],
            'شعار المتجر ليس صورة منتج',
        );
    }
}
