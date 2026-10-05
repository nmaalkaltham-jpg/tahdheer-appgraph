<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Product;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * منتجات العرض ومسار الوسائط العام.
 *
 * السبب الجذري لغياب أغلفة المنتجات لم يكن الواجهة: لا حجم مثبَّت على حاوية
 * الـAPI (فصورة على قرصها تختفي مع أوّل نشر)، والتخزين الكائني داخلي لا
 * يبلغه المتصفّح — فلم يكن هناك مسار عرضٍ أصلًا.
 */
class DemoCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $this->seed(CurriculumSeeder::class);
    }

    public function test_the_seeder_creates_five_complete_published_products(): void
    {
        $this->seed(DemoCatalogSeeder::class);

        $products = Product::query()
            ->where('sku', 'like', 'DEMO-%')
            ->with(['cover', 'price', 'faqs', 'facts', 'files', 'stage', 'grade', 'subject'])
            ->get();

        $this->assertCount(5, $products);

        foreach ($products as $p) {
            $this->assertSame('published', $p->status, "{$p->sku} غير منشور");
            $this->assertNotNull($p->cover, "{$p->sku} بلا غلاف");
            $this->assertNotNull($p->price, "{$p->sku} بلا سعر");
            $this->assertNotEmpty($p->description, "{$p->sku} بلا وصف");
            $this->assertNotNull($p->stage_id, "{$p->sku} بلا مرحلة");
            $this->assertNotNull($p->subject_id, "{$p->sku} بلا مادة");
            // محتويات المنتج: أعداد حقيقية وملفّات بأدوار.
            $this->assertGreaterThanOrEqual(3, $p->facts->count(), "{$p->sku} بلا محتويات");
            $this->assertGreaterThanOrEqual(4, $p->files->count(), "{$p->sku} بلا ملفّات");
            $this->assertGreaterThanOrEqual(2, $p->faqs->count(), "{$p->sku} بلا أسئلة");
        }
    }

    public function test_the_public_products_endpoint_returns_a_cover_for_every_one(): void
    {
        $this->seed(DemoCatalogSeeder::class);

        $data = $this->getJson('/api/v1/products')->assertOk()->json('data');

        $this->assertCount(5, $data);
        foreach ($data as $row) {
            $this->assertArrayHasKey('image', $row, "المنتج {$row['sku']} بلا حقل صورة");
            $this->assertNotEmpty($row['image']['url']);
            $this->assertSame(1200, $row['image']['w']);
            $this->assertNotEmpty($row['image']['alt']);
        }
    }

    /** إعادة التشغيل لا تضاعف — البذرة تُصلح حالة، لا تُنشئ صفوفًا جديدة. */
    public function test_running_the_seeder_twice_is_idempotent(): void
    {
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->assertSame(5, Product::query()->where('sku', 'like', 'DEMO-%')->count());
    }

    public function test_the_media_route_serves_a_stored_cover(): void
    {
        $this->seed(DemoCatalogSeeder::class);

        $url = $this->getJson('/api/v1/products')->json('data.0.image.url');
        $path = parse_url($url, PHP_URL_PATH);

        $response = $this->get($path);

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * الحارس الحقيقي: الأصول المدفوعة في الدلو نفسه.
     *
     * مسارٌ يخدم أيّ مفتاح يجعل تخمين اسم ملف مساويًا لتحميل مجّاني لمنتج
     * مدفوع. البادئة تُفرَض في المتحكّم فلا يستطيع الطالب تسمية شيء خارجها
     * — ولا حتى بـ `..`.
     */
    public function test_the_media_route_cannot_reach_a_paid_original(): void
    {
        $disk = Storage::disk('local');
        $disk->put('private/paid/secret-book.svg', '<svg/>');
        $disk->put('media/demo/ok.svg', '<svg/>');

        // محاولة صعود صريحة
        $this->get('/api/v1/media/../private/paid/secret-book.svg')->assertNotFound();
        // ومُرمَّزة
        $this->get('/api/v1/media/%2e%2e%2fprivate%2fpaid%2fsecret-book.svg')->assertNotFound();
        // ومسار مطلق
        $this->get('/api/v1/media//private/paid/secret-book.svg')->assertNotFound();

        // وما تحت البادئة يُخدَم عاديًّا.
        $this->get('/api/v1/media/demo/ok.svg')->assertOk();
    }

    /** امتداد غير معروف يُرفض بدل تخمين نوعه. */
    public function test_an_unknown_extension_is_refused(): void
    {
        Storage::disk('local')->put('media/demo/x.php', '<?php echo 1;');

        $this->get('/api/v1/media/demo/x.php')->assertNotFound();
    }

    /** الأصول المدفوعة مُعلَّمة خاصّة — لا تتسرّب بتغيير إعداد لاحق. */
    public function test_product_files_point_at_private_assets(): void
    {
        $this->seed(DemoCatalogSeeder::class);

        $product = Product::query()->where('sku', 'like', 'DEMO-%')->with('files.asset')->firstOrFail();

        foreach ($product->files as $file) {
            $this->assertSame('paid_private', $file->asset->visibility);
            $this->assertStringStartsWith('private/', $file->asset->provider_ref);
        }
    }
}
