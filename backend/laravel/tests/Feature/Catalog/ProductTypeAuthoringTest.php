<?php

namespace Tests\Feature\Catalog;

use App\Domain\Admin\AdminRole;
use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductCapabilities;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * نوعُ المنتج يُختار عند الإنشاء، ويُكتب، ويُقرأ.
 *
 * ما تُثبته هذه الاختبارات ليس أنّ العمود يقبل قيمة — ذاك يثبته
 * `UniversalProductModelTest` منذ الترحيل — بل أنّ **مسار كتابةٍ** صار موجودًا.
 * كان `product_nature` بلا كاتبٍ في التطبيق كلّه: تقرؤه أربعةُ أنظمة ولا
 * يكتبه أحد، فتبقى قيمتُه على ما ردمه ترحيلٌ مرّةً واحدة.
 */
class ProductTypeAuthoringTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function asProductManager(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
    }

    /** @return array{0:int,1:ProductGroup} */
    private function createProduct(array $overrides = []): array
    {
        $response = $this->postJson('/api/v1/admin/products', array_merge([
            'slug' => 'nature-'.bin2hex(random_bytes(4)),
            'title' => 'منتج اختباريّ',
            'productNature' => 'DIGITAL',
        ], $overrides));

        $response->assertCreated();
        $id = (int) $response->json('data.id');

        return [$id, ProductGroup::query()->where('legacy_product_id', $id)->firstOrFail()];
    }

    public function test_creation_refuses_a_product_with_no_type(): void
    {
        $this->asProductManager();

        $this->postJson('/api/v1/admin/products', ['slug' => 'no-type', 'title' => 'بلا نوع'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['productNature']);
    }

    public function test_creation_writes_a_group_variant_offer_and_fulfillment(): void
    {
        $this->asProductManager();
        [$id, $group] = $this->createProduct(['productNature' => 'PHYSICAL']);

        $this->assertSame('PHYSICAL', $group->product_nature);

        $variant = ProductVariant::query()->where('product_group_id', $group->id)->firstOrFail();
        $this->assertSame($id, (int) $variant->legacy_product_id);

        $offer = ProductOffer::query()->where('product_variant_id', $variant->id)->firstOrFail();
        /* الماديّ يُتتبَّع لا يُعدّ لا نهائيًّا: نسخةٌ ماديّة تُباع مرّةً واحدة. */
        $this->assertSame('TRACKED', $offer->inventory_strategy);

        $this->assertSame(
            ['PHYSICAL_SHIPMENT'],
            OfferFulfillment::query()->where('product_offer_id', $offer->id)->pluck('strategy')->all(),
        );
    }

    public function test_every_nature_is_creatable_end_to_end(): void
    {
        $this->asProductManager();

        foreach (ProductCapabilities::NATURES as $nature) {
            /*
             * الهجين والمركَّب لا اقتراح افتراضيّ لهما: الأوّل يوجب عالمين،
             * والثاني تحكمه مكوّناته. فيُمرَّر لهما اختيارٌ صريح.
             */
            $strategies = match ($nature) {
                'HYBRID' => ['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT'],
                'COMPOSITE' => ['DIGITAL_DOWNLOAD'],
                default => [],
            };

            [, $group] = $this->createProduct([
                'productNature' => $nature,
                'fulfillmentStrategies' => $strategies,
            ]);

            $this->assertSame($nature, $group->product_nature, "الطبيعة {$nature} لم تُكتب");
        }
    }

    public function test_a_hybrid_with_only_digital_delivery_is_refused(): void
    {
        $this->asProductManager();

        $this->postJson('/api/v1/admin/products', [
            'slug' => 'half-hybrid',
            'title' => 'هجين ناقص',
            'productNature' => 'HYBRID',
            'fulfillmentStrategies' => ['DIGITAL_DOWNLOAD'],
        ])->assertStatus(422)->assertJsonValidationErrors(['fulfillmentStrategies']);
    }

    public function test_a_strategy_that_does_not_fit_the_nature_is_refused(): void
    {
        $this->asProductManager();

        $this->postJson('/api/v1/admin/products', [
            'slug' => 'digital-shipped',
            'title' => 'رقميّ يُشحن',
            'productNature' => 'DIGITAL',
            'fulfillmentStrategies' => ['PHYSICAL_SHIPMENT'],
        ])->assertStatus(422)->assertJsonValidationErrors(['fulfillmentStrategies']);
    }

    public function test_changing_the_type_rewrites_delivery_and_inventory(): void
    {
        $this->asProductManager();
        [$id, $group] = $this->createProduct(['productNature' => 'DIGITAL']);

        $this->patchJson("/api/v1/admin/products/{$id}", [
            'productNature' => 'PHYSICAL',
        ])->assertOk();

        $group->refresh();
        $this->assertSame('PHYSICAL', $group->product_nature);

        $offer = ProductOffer::query()
            ->whereIn('product_variant_id', ProductVariant::query()
                ->where('product_group_id', $group->id)->select('id'))
            ->firstOrFail();

        $this->assertSame('TRACKED', $offer->inventory_strategy);
        $this->assertSame(
            ['PHYSICAL_SHIPMENT'],
            OfferFulfillment::query()->where('product_offer_id', $offer->id)->pluck('strategy')->all(),
            'تبديل الطبيعة يجب أن يُعيد كتابة التسليم — وإلّا بقي الماديّ يُسلَّم تنزيلًا',
        );
    }

    public function test_the_legacy_column_follows_the_new_nature(): void
    {
        $this->asProductManager();
        [$id] = $this->createProduct(['productNature' => 'PHYSICAL']);

        $this->assertSame('physical', Product::query()->whereKey($id)->value('fulfillment_type'));
    }

    public function test_editing_the_title_keeps_the_group_in_step(): void
    {
        $this->asProductManager();
        [$id, $group] = $this->createProduct();

        $this->patchJson("/api/v1/admin/products/{$id}", ['title' => 'عنوانٌ محرَّر'])->assertOk();

        $this->assertSame('عنوانٌ محرَّر', $group->refresh()->title,
            'المجموعة تُقرأ في البحث — تركُها بلا مزامنة يُبقي وثيقةً بعنوانٍ بائت');
    }

    public function test_the_type_registry_is_published_for_the_console(): void
    {
        $this->asProductManager();

        $response = $this->getJson('/api/v1/admin/product-types')->assertOk();

        // عددٌ أدنى لا مساواة: إضافةُ نوعٍ مشروع كانت تُسقط الاختبار.
        $this->assertGreaterThanOrEqual(6, count($response->json('data.natures')));
        $this->assertGreaterThanOrEqual(19, count($response->json('data.strategies')));

        $natures = collect($response->json('data.natures'))->keyBy('value');
        $this->assertTrue($natures['PHYSICAL']['requiresPhysicalAttributes']);
        $this->assertFalse($natures['PHYSICAL']['requiresDeliverableFile']);
        $this->assertTrue($natures['DIGITAL']['requiresDeliverableFile']);

        /* الصدق: استراتيجيّةٌ بلا منفّذ تُعلن ذلك قبل البيع لا بعده. */
        $strategies = collect($response->json('data.strategies'))->keyBy('value');
        $this->assertTrue($strategies['DIGITAL_DOWNLOAD']['executable']);
        $this->assertFalse($strategies['APPOINTMENT']['executable']);
    }

    public function test_the_publish_gate_asks_a_physical_product_for_weight_not_a_file(): void
    {
        $this->asProductManager();
        [$id] = $this->createProduct(['productNature' => 'PHYSICAL']);

        $gate = $this->patchJson("/api/v1/admin/products/{$id}/publish")->assertStatus(422);
        $keys = collect($gate->json('readiness.items'))->pluck('key')->all();

        $this->assertContains('weight', $keys, 'الماديّ يُشحن بوزنه — فالوزن بندُ نشرٍ له');
        $this->assertNotContains('file', $keys,
            'الماديّ لا ملفّ له بطبيعته — مطالبتُه بملفٍّ كانت تمنع نشره إلى الأبد');
    }

    public function test_the_publish_gate_still_asks_a_digital_product_for_a_file(): void
    {
        $this->asProductManager();
        [$id] = $this->createProduct(['productNature' => 'DIGITAL']);

        $gate = $this->patchJson("/api/v1/admin/products/{$id}/publish")->assertStatus(422);
        $keys = collect($gate->json('readiness.items'))->pluck('key')->all();

        $this->assertSame(['title', 'classification', 'price', 'file', 'image'], $keys,
            'الرقميّ يرى القائمة نفسها بالترتيب نفسه — لا تبديل بلا سبب');
    }

    public function test_weight_can_actually_be_written_through_the_variant_surface(): void
    {
        $this->asProductManager();
        [$id, $group] = $this->createProduct(['productNature' => 'PHYSICAL']);

        $variant = ProductVariant::query()->where('product_group_id', $group->id)->firstOrFail();

        $this->patchJson("/api/v1/admin/products/{$id}/variants/{$variant->id}", [
            'weightGrams' => 850,
            'lengthMm' => 300,
        ])->assertOk();

        $variant->refresh();
        $this->assertSame(850, (int) $variant->weight_grams,
            'بندُ نشرٍ لا مدخلَ له هو مطالبةٌ بما لا يُستطاع');
        $this->assertSame(300, (int) $variant->length_mm);
    }

    public function test_a_subscription_without_an_interval_is_refused_by_field_not_by_blanket(): void
    {
        $this->asProductManager();
        [$id, $group] = $this->createProduct(['productNature' => 'ACCESS_BASED']);
        $variant = ProductVariant::query()->where('product_group_id', $group->id)->firstOrFail();

        /* مرآةُ القيد: الناقص يُرفض باسم حقله لا بخطأ خادم. */
        $this->patchJson("/api/v1/admin/products/{$id}/variants/{$variant->id}", [
            'sellingModel' => 'SUBSCRIPTION',
        ])->assertStatus(422)->assertJsonValidationErrors(['billingIntervalUnit']);

        /* والتامّ يمرّ — وكان الرفض قبل اليوم صلبًا مهما اكتمل. */
        $this->patchJson("/api/v1/admin/products/{$id}/variants/{$variant->id}", [
            'sellingModel' => 'SUBSCRIPTION',
            'billingIntervalUnit' => 'MONTH',
            'billingIntervalCount' => 1,
        ])->assertOk();

        $this->assertSame('SUBSCRIPTION', ProductOffer::query()
            ->where('product_variant_id', $variant->id)->value('selling_model'));
    }

    public function test_changing_the_type_is_refused_when_an_offer_is_already_sold(): void
    {
        $this->asProductManager();
        [$id, $group] = $this->createProduct(['productNature' => 'DIGITAL']);

        $variant = ProductVariant::query()->where('product_group_id', $group->id)->firstOrFail();
        $offer = ProductOffer::query()->where('product_variant_id', $variant->id)->firstOrFail();

        $customer = Customer::create(['email' => uniqid().'@t.local', 'name' => 'زبون']);
        $order = Order::create([
            'number' => 'TD-'.uniqid(), 'customer_id' => $customer->id, 'email' => $customer->email,
            'status' => 'PAID', 'total_minor' => 5000,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $id,
            'product_variant_id' => $variant->id, 'product_offer_id' => $offer->id,
            'title_snapshot' => 'منتج', 'price_snapshot_minor' => 5000, 'quantity' => 1,
        ]);

        /*
         * التسليم يُشتقّ حيًّا من `offer_fulfillments` وقت التنفيذ. فإعادة
         * كتابتها على عرضٍ مباع تُعيد توجيه طلبٍ مدفوعٍ بأثرٍ رجعيّ: مشترٍ
         * دفع ثمن تنزيلٍ رقميّ يُطلب منه عنوانُ شحن فجأةً.
         */
        $this->patchJson("/api/v1/admin/products/{$id}", ['productNature' => 'PHYSICAL'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['productNature']);

        $this->assertSame('DIGITAL', $group->refresh()->product_nature);
        $this->assertSame(
            ['DIGITAL_DOWNLOAD'],
            OfferFulfillment::query()->where('product_offer_id', $offer->id)->pluck('strategy')->all(),
        );
    }

    public function test_a_slug_that_collides_with_an_existing_group_is_422_not_500(): void
    {
        $this->asProductManager();
        [, $group] = $this->createProduct();

        /*
         * القاعدة ٣٨ — `products.slug` يُفحص ضدّ جدوله وحده، والكتابة تُدرج
         * القيمة نفسها في `product_groups.slug` الفريد. فالتصادم كان يسقط
         * على ٢٣٥٠٥ ويصير ٥٠٠ بلا اسم حقل.
         */
        $this->postJson('/api/v1/admin/products', [
            'slug' => $group->slug, 'title' => 'آخر', 'productNature' => 'DIGITAL',
        ])->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }
}
