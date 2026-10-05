<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStorageProvider;
use Tests\TestCase;

/**
 * المعاينة المجّانيّة — وحرمة المحتوى المدفوع.
 *
 * الشرط مضاعف: الدور `sample` والمرئيّة `public_sample`. تأليفٌ يخالف أحدهما
 * لا يُسرِّب شيئًا، وهو ما تثبّته هذه الاختبارات صراحةً.
 */
class ProductPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function serveEverything(): void
    {
        FakeStorageProvider::install($this->app);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'PV-'.uniqid(),
            'slug' => 'pv-'.uniqid(),
            'title' => 'تحضير العلوم — الخامس الابتدائي',
            'status' => 'published',
            'published_at' => now(),
            'fulfillment_type' => 'digital',
        ]);
    }

    private function attach(Product $product, string $role, string $visibility): void
    {
        $asset = Asset::create([
            'provider' => 'fake',
            'provider_ref' => 'assets/'.uniqid().'.pdf',
            'original_name' => 'عيّنة.pdf',
            'sha256' => str_repeat('a', 64),
            'size_bytes' => 1024,
            'mime' => 'application/pdf',
            'visibility' => $visibility,
        ]);

        ProductFile::create([
            'product_id' => $product->id,
            'asset_id' => $asset->id,
            'role' => $role,
            'position' => 0,
        ]);
    }

    public function test_a_public_sample_is_offered_to_any_visitor(): void
    {
        $this->serveEverything();
        $product = $this->product();
        $this->attach($product, 'sample', 'public_sample');

        $previews = $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()->json('data.previews');

        $this->assertCount(1, $previews);
        $this->assertStringStartsWith(FakeStorageProvider::HOST, $previews[0]['url']);
        $this->assertSame('عيّنة.pdf', $previews[0]['name']);
    }

    public function test_the_paid_deliverable_never_appears_as_a_preview(): void
    {
        $this->serveEverything();
        $product = $this->product();
        $this->attach($product, 'main', 'paid_private');

        $this->assertArrayNotHasKey(
            'previews',
            $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data'),
        );
    }

    public function test_a_paid_asset_mislabelled_as_a_sample_still_does_not_leak(): void
    {
        $this->serveEverything();
        $product = $this->product();
        $this->attach($product, 'sample', 'paid_private');

        $this->assertArrayNotHasKey(
            'previews',
            $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data'),
            'دورٌ خاطئ في التأليف سرّب أصلًا مدفوعًا',
        );
    }
}
