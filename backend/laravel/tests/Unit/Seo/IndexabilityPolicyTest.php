<?php

namespace Tests\Unit\Seo;

use App\Domain\SEO\IndexabilityPolicy;
use App\Domain\SEO\IndexabilityVerdict;
use App\Models\Product;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The single indexability authority. These cases are the ones the sitemap,
 * llms.txt, IndexNow and the link graph all inherit.
 */
class IndexabilityPolicyTest extends TestCase
{
    private IndexabilityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new IndexabilityPolicy;
    }

    /** @return list<array{0: string}> */
    public static function excludedPaths(): array
    {
        return [
            ['/cart'], ['/cart/'], ['/checkout'], ['/checkout/payment'],
            ['/account'], ['/account/downloads'], ['/orders/abc123'],
            ['/search'], ['/search?q=test'], ['/api/v1/products'],
            ['/admin'], ['/admin/seo/redirects'], ['/wishlist'], ['/compare'],
            ['/login'], ['/pay/order-1'],
        ];
    }

    #[DataProvider('excludedPaths')]
    public function test_transactional_and_private_surfaces_are_excluded(string $path): void
    {
        $verdict = $this->policy->forPath($path);

        $this->assertSame(IndexabilityVerdict::EXCLUDED, $verdict->verdict, $path.' يجب أن يُستبعد');
        $this->assertNotSame('', $verdict->reason, 'كل حكم يحمل سببًا عربيًا');
        $this->assertSame('noindex, follow', $verdict->robots());
    }

    /** @return list<array{0: string}> */
    public static function paidAssetPaths(): array
    {
        return [
            ['/downloads/12/file'],
            ['/files/secret.pptx'],
            ['/secure/entitled'],
            ['/storage/paid/original.zip'],
            ['/media/lesson.pdf'],
            ['/anything/at/all/deck.pptx'],
            ['/reports/sheet.xlsx'],
        ];
    }

    /**
     * حرمة الملف المدفوع: a paid original can never reach a crawler, an LLM
     * index or a feed — regardless of where it sits in the path tree.
     */
    #[DataProvider('paidAssetPaths')]
    public function test_paid_asset_urls_are_always_excluded(string $path): void
    {
        $verdict = $this->policy->forPath($path);

        $this->assertSame(IndexabilityVerdict::EXCLUDED, $verdict->verdict);
        $this->assertSame('IDX-PAID-ASSET', $verdict->rule);
    }

    /** @return list<array{0: string}> */
    public static function filteredPaths(): array
    {
        return [
            ['/stages/primary?orderby=price'],
            ['/stages/primary?th_subject=math'],
            ['/subjects/science?filter_format=pdf'],
            ['/stages/primary?min_price=100&max_price=500'],
            ['/stages/primary?rating_filter=4'],
            ['/products/x?add-to-cart=12'],
            ['/stages/primary?sort=newest'],
        ];
    }

    #[DataProvider('filteredPaths')]
    public function test_filtered_and_sorted_urls_are_never_indexable(string $path): void
    {
        $verdict = $this->policy->forPath($path);

        $this->assertSame(IndexabilityVerdict::EXCLUDED, $verdict->verdict);
        $this->assertSame('IDX-FACET', $verdict->rule);
    }

    public function test_clean_public_paths_are_eligible(): void
    {
        foreach (['/', '/stages', '/stages/primary', '/products/my-slug'] as $path) {
            $this->assertTrue($this->policy->forPath($path)->isEligible(), $path);
        }
    }

    public function test_absolute_urls_are_judged_by_their_path(): void
    {
        $this->assertFalse(
            $this->policy->forPath('https://example.test/checkout')->isEligible(),
        );
        $this->assertTrue(
            $this->policy->forPath('https://example.test/products/a')->isEligible(),
        );
    }

    public function test_unpublished_and_retired_products_are_excluded(): void
    {
        $draft = new Product(['slug' => 'a', 'status' => 'draft']);
        $retired = new Product(['slug' => 'b', 'status' => 'retired']);

        $this->assertSame('IDX-UNPUBLISHED', $this->policy->forProduct($draft)->rule);
        $this->assertSame('IDX-RETIRED', $this->policy->forProduct($retired)->rule);
    }

    public function test_a_future_dated_product_is_monitored_not_excluded(): void
    {
        $scheduled = new Product([
            'slug' => 'c',
            'status' => 'published',
            'published_at' => now()->addWeek(),
        ]);

        $verdict = $this->policy->forProduct($scheduled);

        $this->assertSame(IndexabilityVerdict::MONITOR, $verdict->verdict);
        $this->assertFalse($verdict->isEligible(), 'المراقبة ليست أهلية');
    }

    public function test_a_thin_collection_is_monitored_below_three_products(): void
    {
        $this->assertSame(
            IndexabilityVerdict::MONITOR,
            $this->policy->forCollection('/stages/primary', 2)->verdict,
        );
        $this->assertTrue(
            $this->policy->forCollection('/stages/primary', 3)->isEligible(),
        );
    }
}
