<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * العيّنات المجّانيّة — السطح العامّ، وحرمة المحتوى المدفوع عند حدّه.
 *
 * هذا المسار يخدم بايتات ملفٍّ لأيّ زائر بلا منحة ولا جلسة. ولذلك تُفحص
 * حدوده الثلاثة كلٌّ على حدة: المرئيّة، وكون الأصل غير مدفوع، وارتباطه
 * بمنتجٍ بدور `sample`. كسرُ أيٍّ منها تسريبُ محتوًى مدفوع.
 */
class PublicSamplesTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'assets';

    protected function setUp(): void
    {
        parent::setUp();
        /*
         * القرص هو `filesystems.assets.local_disk` لا `local`: المزوّد
         * المحلّي يقرأ منه، وتزييف القرص الخطأ يجعل كلّ بايتٍ مفقودًا.
         */
        Storage::fake(self::DISK);
        config([
            'filesystems.default' => 'local',
            'filesystems.assets.provider' => 'local',
            'filesystems.assets.local_disk' => self::DISK,
        ]);
    }

    private function product(string $type = 'تحضير'): Product
    {
        return Product::create([
            'sku' => 'S-'.uniqid(),
            'slug' => 's-'.uniqid(),
            'title' => 'تحضير العلوم — الخامس الابتدائي',
            'resource_type' => $type,
            'status' => 'published',
            'published_at' => now(),
            'fulfillment_type' => 'digital',
        ]);
    }

    /**
     * `assets.sha256` فريد — التخزين مُعنوَن بالمحتوى، فأصلان بالبايتات
     * نفسها أصلٌ واحد. فمحتوى كلّ تركيبةٍ هنا مميّز ما لم يُطلب غيره.
     */
    private function asset(string $visibility, ?string $body = null): Asset
    {
        $body ??= 'sample-bytes-'.uniqid();
        $key = 'assets/'.uniqid().'.pdf';
        Storage::disk(self::DISK)->put($key, $body);

        return Asset::create([
            'provider' => 'local',
            'provider_ref' => $key,
            'original_name' => 'عيّنة.pdf',
            'sha256' => hash('sha256', $body),
            'size_bytes' => strlen($body),
            'mime' => 'application/pdf',
            'visibility' => $visibility,
        ]);
    }

    private function link(Product $product, Asset $asset, string $role): void
    {
        ProductFile::create([
            'product_id' => $product->id,
            'asset_id' => $asset->id,
            'role' => $role,
            'position' => 0,
        ]);
    }

    public function test_the_index_lists_only_products_with_a_public_sample(): void
    {
        $withSample = $this->product();
        $this->link($withSample, $this->asset('public_sample'), 'sample');

        $paidOnly = $this->product();
        $this->link($paidOnly, $this->asset('paid_private'), 'main');

        $this->product(); // بلا ملفّات أصلًا

        $body = $this->getJson('/api/v1/samples')->assertOk()->json();

        $this->assertSame(1, $body['count']);
        $this->assertSame($withSample->id, $body['items'][0]['product']['id']);
        $this->assertSame(['تحضير'], $body['resourceTypes']);
    }

    public function test_the_index_reports_only_the_types_it_actually_shows(): void
    {
        foreach (['تحضير', 'عرض بوربوينت', 'تحضير'] as $type) {
            $product = $this->product($type);
            $this->link($product, $this->asset('public_sample'), 'sample');
        }

        /* نوعٌ بلا عيّنةٍ منشورة لا يظهر عنوانًا فارغًا ينتظر محتوًى. */
        $this->link($this->product('أوراق عمل'), $this->asset('paid_private'), 'main');

        $types = $this->getJson('/api/v1/samples')->assertOk()->json('resourceTypes');

        $this->assertEqualsCanonicalizing(['تحضير', 'عرض بوربوينت'], $types);
    }

    public function test_a_public_sample_is_served_to_anyone(): void
    {
        $product = $this->product();
        $asset = $this->asset('public_sample', 'the-free-sample');
        $this->link($product, $asset, 'sample');

        $response = $this->get("/api/v1/samples/files/{$asset->id}");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('the-free-sample', $response->streamedContent());
    }

    public function test_a_paid_asset_is_never_served_here(): void
    {
        $product = $this->product();
        $asset = $this->asset('paid_private', 'the-paid-file');
        $this->link($product, $asset, 'main');

        $this->get("/api/v1/samples/files/{$asset->id}")->assertNotFound();
    }

    public function test_a_paid_asset_mislabelled_as_a_sample_is_still_refused(): void
    {
        $product = $this->product();
        $asset = $this->asset('paid_private', 'the-paid-file');
        /* تأليفٌ خاطئ: الدور يقول عيّنة والمرئيّة تقول مدفوع. يفوز الأشدّ. */
        $this->link($product, $asset, 'sample');

        $this->get("/api/v1/samples/files/{$asset->id}")->assertNotFound();
    }

    public function test_a_public_asset_that_is_not_a_product_sample_is_refused(): void
    {
        /*
         * أصلٌ عامّ غير مرتبطٍ بمنتجٍ بدور `sample` — وسائط تقييمٍ مثلًا.
         * لولا هذا الشرط لصار المسار خادمَ أصولٍ عامٍّ لكلّ النظام.
         */
        $orphan = $this->asset('public_sample');

        $this->get("/api/v1/samples/files/{$orphan->id}")->assertNotFound();

        $product = $this->product();
        $this->link($product, $orphan, 'companion');

        $this->get("/api/v1/samples/files/{$orphan->id}")->assertNotFound();
    }

    public function test_a_missing_object_is_an_absence_not_a_server_error(): void
    {
        $product = $this->product();
        $asset = $this->asset('public_sample');
        $this->link($product, $asset, 'sample');

        Storage::disk(self::DISK)->delete((string) $asset->provider_ref);

        $this->get("/api/v1/samples/files/{$asset->id}")->assertNotFound();
    }

    public function test_the_product_page_offers_the_sample_on_a_local_provider(): void
    {
        $product = $this->product();
        $asset = $this->asset('public_sample');
        $this->link($product, $asset, 'sample');

        $previews = $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()->json('data.previews');

        $this->assertCount(1, $previews);
        $this->assertStringContainsString("/api/v1/samples/files/{$asset->id}", $previews[0]['url']);
    }
}
