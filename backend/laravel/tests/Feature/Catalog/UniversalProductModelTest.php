<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\AttributeDefinition;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\TaxClass;
use App\Domain\Catalog\ProductCapabilities;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مصفوفة أنواع المنتجات (§١٥٢) على النموذج الكونيّ.
 *
 * النموذج القديم كان عمودًا واحدًا: `products.fulfillment_type` نصُّه
 * `digital` في الصفوف الستّة والعشرين جميعًا. فلم يكن المتجر «رقميًّا أوّلًا»
 * بل رقميًّا حصرًا — بنيةً لا سياسةً. كلّ اختبارٍ هنا يصف نوعًا كان يستحيل
 * تمثيله قبل هذه الهجرة، فيثبت أنّ «الرقميّ أوّلًا لا يعني الرقميّ وحده».
 *
 * الحزمة تعمل على Postgres (قاعدة `tahdheer_test` المعزولة)، فقيود CHECK
 * حاضرةٌ فعلًا هنا وتُختبَر رفضًا لا ادّعاءً.
 */
class UniversalProductModelTest extends TestCase
{
    use RefreshDatabase;

    private function group(string $slug, string $nature): ProductGroup
    {
        return ProductGroup::create([
            'slug' => $slug, 'title' => $slug, 'product_nature' => $nature, 'status' => 'published',
        ]);
    }

    private function variant(ProductGroup $g, string $sku, array $extra = []): ProductVariant
    {
        return ProductVariant::create($extra + ['product_group_id' => $g->id, 'sku' => $sku, 'slug' => $sku, 'status' => 'published']);
    }

    private function offer(ProductVariant $v, array $extra = []): ProductOffer
    {
        return ProductOffer::create($extra + ['product_variant_id' => $v->id, 'regular_minor' => 5000, 'status' => 'active']);
    }

    private function fulfil(ProductOffer $o, string ...$strategies): void
    {
        foreach ($strategies as $i => $s) {
            OfferFulfillment::create(['product_offer_id' => $o->id, 'strategy' => $s, 'position' => $i]);
        }
    }

    public function test_digital_download_needs_no_shipping_address(): void
    {
        $o = $this->offer($this->variant($this->group('digital', 'DIGITAL'), 'D-1'));
        $this->fulfil($o, 'DIGITAL_DOWNLOAD');

        $this->assertFalse($o->fresh()->requiresShippingAddress());
    }

    public function test_physical_product_requires_a_shipping_address(): void
    {
        $g = $this->group('physical', 'PHYSICAL');
        $v = $this->variant($g, 'P-1', ['weight_grams' => 1400, 'length_mm' => 297, 'width_mm' => 210, 'height_mm' => 20]);
        $o = $this->offer($v, ['inventory_strategy' => 'TRACKED']);
        $this->fulfil($o, 'PHYSICAL_SHIPMENT');

        $this->assertTrue($o->fresh()->requiresShippingAddress());
        // §٢٧ — الوحدة عمودٌ صريح لا لاحقةٌ في نصّ.
        $this->assertSame(1400, $v->weight_grams);
    }

    public function test_a_hybrid_product_carries_both_strategies_at_once(): void
    {
        $g = $this->group('hybrid', 'HYBRID');
        $o = $this->offer($this->variant($g, 'H-1'));
        $this->fulfil($o, 'DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT');

        $this->assertSame(
            ['DIGITAL_DOWNLOAD', 'PHYSICAL_SHIPMENT'],
            $g->fresh()->load('variants.offers.fulfillments')->fulfillmentStrategies(),
        );
        // الطلب ينقسم: نصفٌ يُنزَّل فورًا ونصفٌ يُشحن (§١٠ §٣٤).
        $this->assertTrue($o->fresh()->requiresShippingAddress());
    }

    public function test_subscription_carries_a_billing_interval(): void
    {
        $o = $this->offer($this->variant($this->group('sub', 'ACCESS_BASED'), 'S-1'), [
            'selling_model' => 'SUBSCRIPTION',
            'billing_interval_count' => 1,
            'billing_interval_unit' => 'MONTH',
            'trial_days' => 14,
        ]);
        $this->fulfil($o, 'ACCOUNT_ENTITLEMENT');

        $this->assertSame('SUBSCRIPTION', $o->selling_model);
        $this->assertSame('MONTH', $o->billing_interval_unit);
    }

    public function test_service_product_is_scheduled_not_shipped(): void
    {
        $o = $this->offer($this->variant($this->group('service', 'SERVICE'), 'SV-1'), ['inventory_strategy' => 'TIME_CAPACITY']);
        $this->fulfil($o, 'APPOINTMENT');

        $this->assertFalse($o->fresh()->requiresShippingAddress());
        $this->assertContains('APPOINTMENT', ProductCapabilities::SCHEDULED);
    }

    public function test_one_group_holds_many_variants_with_independent_offers(): void
    {
        $g = $this->group('planner', 'HYBRID');

        $a4 = $this->offer($this->variant($g, 'PL-A4-PDF', ['position' => 0]), ['regular_minor' => 3000]);
        $a5 = $this->offer($this->variant($g, 'PL-A5-PDF', ['position' => 1]), ['regular_minor' => 2500]);
        $print = $this->offer($this->variant($g, 'PL-A4-PRINT', ['position' => 2]), ['regular_minor' => 9000, 'inventory_strategy' => 'TRACKED']);

        $this->fulfil($a4, 'DIGITAL_DOWNLOAD');
        $this->fulfil($a5, 'DIGITAL_DOWNLOAD');
        $this->fulfil($print, 'PHYSICAL_SHIPMENT');

        $this->assertCount(3, $g->fresh()->variants);
        $this->assertFalse($a4->fresh()->requiresShippingAddress());
        $this->assertTrue($print->fresh()->requiresShippingAddress());
        // ثلاثة أثمانٍ مختلفة تحت منتجٍ واحد — مستحيلٌ في `product_prices`.
        $this->assertSame([3000, 2500, 9000], [$a4->regular_minor, $a5->regular_minor, $print->regular_minor]);
    }

    public function test_variant_title_falls_back_to_the_group(): void
    {
        $g = $this->group('fallback', 'DIGITAL');
        $bare = $this->variant($g, 'F-1');
        $named = $this->variant($g, 'F-2', ['title' => 'نسخة A5']);

        $this->assertSame('fallback', $bare->displayTitle());
        $this->assertSame('نسخة A5', $named->displayTitle());
    }

    public function test_sale_price_applies_only_inside_its_window(): void
    {
        $o = $this->offer($this->variant($this->group('sale', 'DIGITAL'), 'SA-1'), [
            'regular_minor' => 10000, 'sale_minor' => 6000,
            'sale_from' => now()->subDay(), 'sale_to' => now()->addDay(),
        ]);

        $this->assertSame(6000, $o->effectivePriceMinor());
        $this->assertSame(10000, $o->effectivePriceMinor(now()->addWeek()));
        $this->assertSame(10000, $o->effectivePriceMinor(now()->subWeek()));
    }

    public function test_measurements_keep_number_and_unit_apart(): void
    {
        $def = AttributeDefinition::where('key', 'pages_count')->firstOrFail();
        $g = $this->group('measured', 'DIGITAL');

        $value = AttributeValue::create([
            'attribute_definition_id' => $def->id, 'product_group_id' => $g->id,
            'int_value' => 120, 'unit' => 'page', 'source' => 'VERIFIED_HUMAN',
        ]);

        $this->assertSame('INTEGER', $def->data_type);
        $this->assertSame('int_value', $def->valueColumn());
        $this->assertSame(120, $value->typedValue());   // رقمٌ يُفرز، لا نصّ
        $this->assertSame('page', $value->unit);
        $this->assertTrue($def->sortable);
    }

    public function test_ai_suggestions_are_not_canonical_facts(): void
    {
        $def = AttributeDefinition::where('key', 'resource_type')->firstOrFail();
        $g = $this->group('provenance', 'DIGITAL');

        $human = AttributeValue::create([
            'attribute_definition_id' => $def->id, 'product_group_id' => $g->id,
            'text_value' => 'تحضير', 'source' => 'VERIFIED_HUMAN',
        ]);
        $guess = AttributeValue::create([
            'attribute_definition_id' => $def->id, 'product_variant_id' => $this->variant($g, 'PV-1')->id,
            'text_value' => 'اختبارات', 'source' => 'AI_SUGGESTION', 'confidence' => 61,
        ]);

        $this->assertTrue($human->isCanonical());
        $this->assertFalse($guess->isCanonical()); // §١٦٠ صفر ترقيةٍ غير مُتحقَّقة
    }

    public function test_vat_is_extracted_from_a_gross_price_never_added(): void
    {
        $vat = TaxClass::where('code', 'SA_VAT_STANDARD')->firstOrFail();

        $this->assertSame(1500, $vat->rate_bps);
        // ١١٥ ريالًا شاملةً ١٥٪ تحمل ١٥ ريالًا ضريبة، لا ١٧٫٢٥.
        $this->assertSame(1500, $vat->extractFromGrossMinor(11500));
    }

    /* ── القيود: تُختبَر بالرفض، لا بالادّعاء ──────────────────────────── */

    private function assertRejected(string $constraint, callable $insert): void
    {
        try {
            $insert();
        } catch (QueryException $e) {
            $this->assertStringContainsString($constraint, $e->getMessage());

            return;
        }

        $this->fail("القيد {$constraint} لم يرفض صفًّا باطلًا.");
    }

    public function test_an_attribute_value_cannot_fill_two_typed_columns(): void
    {
        $def = AttributeDefinition::where('key', 'pages_count')->firstOrFail();
        $g = $this->group('two-cols', 'DIGITAL');

        $this->assertRejected('attribute_values_exactly_one_value', fn () => DB::table('attribute_values')->insert([
            'attribute_definition_id' => $def->id, 'product_group_id' => $g->id,
            'int_value' => 5, 'text_value' => 'خمسة', // رقمٌ ونصٌّ معًا
            'source' => 'VERIFIED_SYSTEM', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_an_attribute_value_cannot_be_empty(): void
    {
        $def = AttributeDefinition::where('key', 'pages_count')->firstOrFail();
        $g = $this->group('no-cols', 'DIGITAL');

        $this->assertRejected('attribute_values_exactly_one_value', fn () => DB::table('attribute_values')->insert([
            'attribute_definition_id' => $def->id, 'product_group_id' => $g->id,
            'source' => 'VERIFIED_SYSTEM', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_an_attribute_value_belongs_to_exactly_one_owner(): void
    {
        $def = AttributeDefinition::where('key', 'pages_count')->firstOrFail();
        $g = $this->group('two-owners', 'DIGITAL');
        $v = $this->variant($g, 'TO-1');

        $this->assertRejected('attribute_values_single_owner', fn () => DB::table('attribute_values')->insert([
            'attribute_definition_id' => $def->id,
            'product_group_id' => $g->id, 'product_variant_id' => $v->id, // كلاهما
            'int_value' => 5, 'source' => 'VERIFIED_SYSTEM', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_a_sale_price_can_never_reach_the_regular_price(): void
    {
        $v = $this->variant($this->group('bad-sale', 'DIGITAL'), 'BS-1');

        $this->assertRejected('product_offers_sale_below_regular', fn () => DB::table('product_offers')->insert([
            'product_variant_id' => $v->id, 'selling_model' => 'ONE_TIME', 'inventory_strategy' => 'UNLIMITED',
            'regular_minor' => 1000, 'sale_minor' => 1000, 'currency' => 'SAR', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_a_subscription_cannot_exist_without_a_billing_interval(): void
    {
        $v = $this->variant($this->group('bad-sub', 'ACCESS_BASED'), 'BSU-1');

        $this->assertRejected('product_offers_recurring_requires_interval', fn () => DB::table('product_offers')->insert([
            'product_variant_id' => $v->id, 'selling_model' => 'SUBSCRIPTION', 'inventory_strategy' => 'UNLIMITED',
            'regular_minor' => 1000, 'currency' => 'SAR', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_a_free_offer_cannot_carry_a_price(): void
    {
        $v = $this->variant($this->group('bad-free', 'DIGITAL'), 'BF-1');

        $this->assertRejected('product_offers_free_is_zero', fn () => DB::table('product_offers')->insert([
            'product_variant_id' => $v->id, 'selling_model' => 'FREE', 'inventory_strategy' => 'UNLIMITED',
            'regular_minor' => 1000, 'currency' => 'SAR', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }
}
