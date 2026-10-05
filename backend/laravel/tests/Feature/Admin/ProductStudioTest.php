<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Catalog\CoverageEngine;
use App\Domain\Catalog\ProductLayout;
use App\Domain\SEO\MetadataService;
use App\Domain\Writing\NullWritingAssistant;
use App\Domain\Writing\WritingAssistantUnavailable;
use App\Models\ProductMedium;
use App\Models\ProductSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Commerce\CommerceFixtures;
use Tests\TestCase;

/**
 * فجوات استوديو المنتج: الملفّات، الوسائط، SEO، التخطيط، التغطية، المساعد.
 *
 * كلها كانت واجهة بلا خلف أو حالة جلسة تضيع مع إعادة التحميل.
 */
class ProductStudioTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    // ───── الوسائط والغلاف ─────

    /** الغلاف واحد دائمًا — لا اثنان ولا صفر. */
    public function test_only_one_medium_can_be_the_cover(): void
    {
        $product = $this->makeProduct('TD-M-1', 4000);

        $a = ProductMedium::query()->create([
            'product_id' => $product->id, 'url' => '/api/v1/media/a.svg',
            'alt_ar' => 'أ', 'position' => 0, 'is_cover' => true,
        ]);
        $b = ProductMedium::query()->create([
            'product_id' => $product->id, 'url' => '/api/v1/media/b.svg',
            'alt_ar' => 'ب', 'position' => 1, 'is_cover' => false,
        ]);

        // ترقية الثانية تنزع الأولى — كما يفعل المتحكّم داخل معاملته.
        DB::transaction(function () use ($product, $b): void {
            $b->forceFill(['is_cover' => true])->save();
            ProductMedium::query()->where('product_id', $product->id)
                ->where('id', '!=', $b->id)->update(['is_cover' => false]);
        });

        $this->assertFalse((bool) $a->refresh()->is_cover);
        $this->assertTrue((bool) $b->refresh()->is_cover);
        $this->assertSame(1, $product->media()->where('is_cover', true)->count());
        // والعلاقة تختار بالقصد لا بالترتيب.
        $this->assertSame($b->id, $product->refresh()->cover?->id);
    }

    /** النصّ البديل يصل حتى سمة alt في الـAPI العامّة. */
    public function test_alt_text_reaches_the_public_product_contract(): void
    {
        $product = $this->makeProduct('TD-M-2', 4000);
        ProductMedium::query()->create([
            'product_id' => $product->id, 'url' => '/api/v1/media/x.svg',
            'alt_ar' => 'غلاف تحضير الرياضيات', 'width' => 1200, 'height' => 900,
            'position' => 0, 'is_cover' => true,
        ]);

        $row = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))
            ->firstWhere('sku', 'TD-M-2');

        $this->assertNotNull($row['image'] ?? null);
        $this->assertSame('غلاف تحضير الرياضيات', $row['image']['alt']);
    }

    // ───── SEO ─────

    /** المحفوظ يصل الخدمة القائمة ويظهر في الناتج الفعلي. */
    public function test_authored_seo_flows_through_the_existing_metadata_service(): void
    {
        $product = $this->makeProduct('TD-S-1', 4000);
        ProductSeo::query()->create([
            'product_id' => $product->id,
            'seo_title' => 'عنوان محرَّر يدويًّا',
            'seo_desc' => 'وصف محرَّر يدويًّا للاختبار.',
        ]);

        $meta = app(MetadataService::class)->forProduct($product->refresh()->load('seo'));

        $this->assertStringContainsString('عنوان محرَّر', json_encode($meta, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('وصف محرَّر', json_encode($meta, JSON_UNESCAPED_UNICODE));
    }

    // ───── التخطيط ─────

    public function test_layout_defaults_include_every_required_section(): void
    {
        $layout = ProductLayout::defaults();
        $widgets = array_column($layout['sections'], 'widget');

        foreach (ProductLayout::REQUIRED as $required) {
            $this->assertContains($required, $widgets);
        }
        foreach ($widgets as $w) {
            $this->assertArrayHasKey($w, ProductLayout::WIDGETS, "ودجة مجهولة: {$w}");
        }
    }

    /** ودجة مجهولة تُسقَط، ومكرّرة تُسقَط، وإلزامية لا تُخفى. */
    public function test_layout_normalisation_refuses_unknown_duplicate_and_hidden_required(): void
    {
        $normalized = ProductLayout::normalize([
            'sections' => [
                ['widget' => 'evil<script>'],
                ['widget' => 'faqs'],
                ['widget' => 'faqs'],
                ['widget' => 'purchase', 'visible' => false],
            ],
        ]);

        $widgets = array_column($normalized['sections'], 'widget');

        $this->assertNotContains('evil<script>', $widgets);
        $this->assertSame(1, count(array_keys($widgets, 'faqs', true)), 'المكرّر يُسقَط');

        $purchase = collect($normalized['sections'])->firstWhere('widget', 'purchase');
        $this->assertTrue($purchase['visible'], 'قسم الشراء إلزامي ولا يُخفى');

        // والإلزامي الناقص يُعاد.
        $this->assertContains('title', $widgets);
    }

    public function test_layout_survives_a_save_and_a_fresh_read(): void
    {
        $product = $this->makeProduct('TD-L-1', 4000);

        DB::table('product_layouts')->insert([
            'product_id' => $product->id,
            'draft_layout' => json_encode(ProductLayout::normalize([
                'sections' => [['widget' => 'reviews'], ['widget' => 'gallery']],
            ]), JSON_UNESCAPED_UNICODE),
            'version' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('product_layouts')->where('product_id', $product->id)->first();
        $stored = json_decode((string) $row->draft_layout, true);

        $this->assertSame('reviews', $stored['sections'][0]['widget']);
        $this->assertSame(2, (int) $row->version);
        $this->assertNull($row->published_layout, 'المسوّدة لا تتسرّب إلى المنشور');
    }

    /** كل ودجة في منطقة معروفة — بلا منطقة لا تُصيَّر أبدًا. */
    public function test_every_widget_declares_a_known_zone(): void
    {
        foreach (ProductLayout::WIDGETS as $widget => $meta) {
            $this->assertArrayHasKey('zone', $meta, "الودجة {$widget} بلا منطقة");
            $this->assertArrayHasKey($meta['zone'], ProductLayout::ZONES, "منطقة مجهولة: {$meta['zone']}");
            $this->assertNotSame('', trim($meta['label']));
        }
    }

    /** المسوّدة لا تصل عميلًا: التعديل غير المكتمل ليس منشورًا. */
    public function test_the_public_product_exposes_the_published_layout_only(): void
    {
        $product = $this->makeProduct('TD-L-2', 4000);

        $draftOnly = ProductLayout::normalize(['sections' => [['widget' => 'reviews']]]);
        DB::table('product_layouts')->insert([
            'product_id' => $product->id,
            'draft_layout' => json_encode($draftOnly, JSON_UNESCAPED_UNICODE),
            'version' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // صفحة المنتج هي المستهلك: القائمة تستعمل بطاقة مختصرة بلا تخطيط.
        $detail = "/api/v1/products/{$product->slug}";

        // غياب التخطيط يعني «استخدم الافتراضي»، لا «لا تعرض شيئًا».
        $this->assertNull(
            $this->getJson($detail)->assertOk()->json('data.layout'),
            'المسوّدة تسرّبت إلى الواجهة العامّة',
        );

        DB::table('product_layouts')->where('product_id', $product->id)->update([
            'published_layout' => json_encode($draftOnly, JSON_UNESCAPED_UNICODE),
            'published_version' => 3,
        ]);

        $published = $this->getJson($detail)->assertOk()->json('data.layout');

        $this->assertIsArray($published);
        $this->assertSame('reviews', $published[0]['widget']);
        // والإلزامي حاضر حتى وإن لم يُرسله المحرّر.
        $this->assertContains('purchase', array_column($published, 'widget'));
    }

    // ───── التغطية ─────

    /** بلا مرحلة أو مادة لا حكم — بيانات ناقصة، لا درجة مخترَعة. */
    public function test_coverage_reports_insufficient_data_instead_of_guessing(): void
    {
        $product = $this->makeProduct('TD-C-1', 4000);
        $product->forceFill(['stage_id' => null, 'subject_id' => null])->save();

        $result = app(CoverageEngine::class)->forProduct($product->refresh());

        $this->assertSame(CoverageEngine::INSUFFICIENT, $result['status']);
        $this->assertContains('MISSING_REQUIRED_STAGE', $result['reasonCodes']);
        $this->assertContains('MISSING_REQUIRED_SUBJECT', $result['reasonCodes']);
        // ولا رقم مئوي في أي مكان.
        $this->assertArrayNotHasKey('score', $result);
        $this->assertNull($result['siblings'], 'لا حساب جيران بلا بُعد حاصر');
    }

    public function test_coverage_is_deterministic_and_explains_itself(): void
    {
        $product = $this->makeProduct('TD-C-2', 4000);
        $engine = app(CoverageEngine::class);

        $first = $engine->forProduct($product->refresh());
        $second = $engine->forProduct($product->refresh());

        $this->assertEquals($first, $second, 'نفس المدخل ⇒ نفس الحكم');
        $this->assertContains($first['status'], [
            CoverageEngine::COVERED, CoverageEngine::PARTIAL,
            CoverageEngine::NOT_COVERED, CoverageEngine::INSUFFICIENT,
        ]);
        foreach ($first['dimensions'] as $key => $d) {
            $this->assertArrayHasKey('present', $d, "البُعد {$key} بلا تفسير");
            $this->assertArrayHasKey('blocking', $d);
        }
    }

    // ───── المساعد الكتابي ─────

    /** غير مهيَّأ ⇒ رفض صريح، لا نصّ مُلفَّق. */
    public function test_an_unconfigured_writing_assistant_refuses_instead_of_inventing(): void
    {
        $assistant = new NullWritingAssistant;

        $this->assertFalse($assistant->isConfigured());
        $this->assertSame('none', $assistant->name());

        try {
            $assistant->suggest('title', []);
            $this->fail('المساعد غير المهيَّأ يجب أن يرفض.');
        } catch (WritingAssistantUnavailable $e) {
            $this->assertSame(WritingAssistantUnavailable::NOT_CONFIGURED, $e->reason);
        }
    }
}
