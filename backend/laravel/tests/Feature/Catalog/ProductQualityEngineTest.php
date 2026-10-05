<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\AttributeDefinition;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\BundleComponent;
use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductContentSection;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductRelationship;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\TaxClass;
use App\Domain\Catalog\ProductQualityEngine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * جودة بيانات المنتج والحزم والعلاقات (§٣٦ §٥٧ §٦٠ §٦٢ §١٥٧).
 *
 * الغرض إثبات أنّ البطاقة **تسمّي** الناقص ولا تعدّه، وأنّ الأبعاد تبقى
 * منفصلة: منتجٌ كامل المحتوى بلا إعداد تسليمٍ غير قابلٍ للبيع، ومتوسّطٌ
 * واحد كان سيُظهره «جاهزًا تقريبًا».
 */
class ProductQualityEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): ProductQualityEngine
    {
        return $this->app->make(ProductQualityEngine::class);
    }

    private function group(array $attrs = []): ProductGroup
    {
        $n = uniqid();

        return ProductGroup::create($attrs + [
            'slug' => "g-{$n}", 'title' => 'مجموعة', 'product_nature' => 'DIGITAL', 'status' => 'draft',
        ]);
    }

    private function variant(ProductGroup $g, array $attrs = []): ProductVariant
    {
        $n = uniqid();

        return ProductVariant::create($attrs + [
            'product_group_id' => $g->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}", 'status' => 'draft',
        ]);
    }

    private function sellableOffer(ProductVariant $v, string $strategy = 'DIGITAL_DOWNLOAD'): ProductOffer
    {
        $tax = TaxClass::query()->firstOrCreate(
            ['code' => 'SA_VAT_STANDARD'],
            ['name' => 'ض.ق.م', 'rate_bps' => 1500, 'jurisdiction' => 'SA'],
        );
        $offer = ProductOffer::create([
            'product_variant_id' => $v->id, 'regular_minor' => 5000,
            'status' => 'active', 'tax_class_id' => $tax->id,
        ]);
        OfferFulfillment::create(['product_offer_id' => $offer->id, 'strategy' => $strategy]);

        return $offer;
    }

    public function test_an_empty_group_names_what_it_lacks_instead_of_counting(): void
    {
        $card = $this->engine()->score($this->group());

        // «٣ حقول ناقصة» تُرسل المشغّل يبحث؛ الاسم يُرسله يُصلح.
        $this->assertContains('لا صيغة واحدة — لا شيء يُباع', $card->missingIn('IDENTITY'));
        $this->assertContains('لا صيغة — لا شيء يُسلَّم', $card->missingIn('FULFILLMENT'));
        $this->assertFalse($card->canPublish());
    }

    public function test_dimensions_stay_separate_so_one_does_not_mask_another(): void
    {
        $group = $this->group();
        $this->variant($group); // صيغةٌ بلا عرض

        foreach (['SUMMARY' => 'ملخّص', 'INCLUDED_ITEMS' => 'المحتويات', 'DELIVERY' => 'التسليم'] as $kind => $heading) {
            ProductContentSection::create([
                'product_group_id' => $group->id, 'kind' => $kind, 'heading' => $heading, 'body' => 'نصّ',
            ]);
        }
        $group->update(['short_desc' => 'وصف مختصر']);

        $card = $this->engine()->score($group->fresh());

        /*
         * المحتوى كامل والتسليم معدوم. المتوسّط كان سيقول «جاهز تقريبًا»
         * عن منتجٍ لا يمكن بيعه أصلًا (§١٥٧).
         */
        $this->assertSame(100, $card->score('CONTENT'));
        $this->assertNotEmpty($card->missingIn('FULFILLMENT'));
        $this->assertFalse($card->canPublish());
    }

    public function test_a_complete_digital_product_may_publish(): void
    {
        $group = $this->group();
        $this->sellableOffer($this->variant($group));

        $card = $this->engine()->score($group->fresh());

        $this->assertTrue($card->canPublish());
        $this->assertSame([], $card->publicationBlockers());
    }

    public function test_a_physical_variant_without_a_weight_is_named_as_shipping_blocking(): void
    {
        $group = $this->group(['product_nature' => 'PHYSICAL']);
        $variant = $this->variant($group); // بلا وزن
        $this->sellableOffer($variant, 'PHYSICAL_SHIPMENT');

        $card = $this->engine()->score($group->fresh());

        // الرسالة تُطابق ما يقوله منفّذ الشحن فعلًا عند التنفيذ.
        $this->assertStringContainsString('بلا وزن', implode(' ', $card->missingIn('SPECIFICATION')));
    }

    public function test_curriculum_is_only_demanded_once_the_product_claims_to_be_educational(): void
    {
        // منتجٌ غير تعليميّ لا يُطالَب بمنهج — وإلّا صارت كلّ بطاقةٍ حمراء فتُهمَل.
        $plain = $this->engine()->score($this->group());
        $this->assertSame([], $plain->missingIn('CLASSIFICATION'));
    }

    public function test_an_ai_suggested_attribute_counts_against_provenance(): void
    {
        $group = $this->group();
        $def = AttributeDefinition::create([
            'key' => 'topic-'.uniqid(), 'name' => 'الموضوع', 'data_type' => 'TEXT',
        ]);
        AttributeValue::create([
            'attribute_definition_id' => $def->id, 'product_group_id' => $group->id,
            'text_value' => 'كسور', 'source' => 'AI_SUGGESTION', 'confidence' => 70,
        ]);

        $card = $this->engine()->score($group->fresh());

        // §١٦٠ «صفر ترقيةٍ غير مُتحقَّقة» يحتاج قياسًا — وهذا هو.
        $this->assertSame(0, $card->score('PROVENANCE'));
        $this->assertStringContainsString('AI_SUGGESTION', implode(' ', $card->missingIn('PROVENANCE')));
    }

    // ── الحزم والعلاقات ──────────────────────────────────────────────

    public function test_a_bundle_points_at_variants_rather_than_copying_their_facts(): void
    {
        $bundle = $this->group(['product_nature' => 'COMPOSITE', 'bundle_kind' => 'FIXED']);
        $componentGroup = $this->group();
        $component = $this->variant($componentGroup, ['title' => 'المكوّن الأصلي']);

        BundleComponent::create([
            'bundle_product_group_id' => $bundle->id,
            'component_variant_id' => $component->id,
            'quantity' => 2,
        ]);

        // تعديل المكوّن يظهر في الحزمة فورًا — لا نسخةَ عنوانٍ تتقادم (§٣٦).
        $component->update(['title' => 'العنوان المُصحَّح']);

        $linked = BundleComponent::query()->where('bundle_product_group_id', $bundle->id)->firstOrFail();
        $this->assertSame('العنوان المُصحَّح', ProductVariant::query()->find($linked->component_variant_id)->title);
        $this->assertSame(2, $linked->quantity);
    }

    public function test_a_product_cannot_be_related_to_itself(): void
    {
        $group = $this->group();

        // حلقةٌ تُغرق كلّ عارضٍ يتبع العلاقات.
        $this->expectException(QueryException::class);
        ProductRelationship::create([
            'from_product_group_id' => $group->id,
            'to_product_group_id' => $group->id,
            'relation_type' => 'RELATED_TO',
        ]);
    }

    public function test_every_relationship_records_where_it_came_from(): void
    {
        $a = $this->group();
        $b = $this->group();

        $human = ProductRelationship::create([
            'from_product_group_id' => $a->id, 'to_product_group_id' => $b->id,
            'relation_type' => 'NEXT_IN_CURRICULUM', 'source' => 'VERIFIED_HUMAN',
        ]);
        $guess = ProductRelationship::create([
            'from_product_group_id' => $a->id, 'to_product_group_id' => $b->id,
            'relation_type' => 'CROSS_SELL', 'source' => 'AI_SUGGESTION', 'confidence' => 55,
        ]);

        /*
         * §٥٧ — «التالي في المنهج» أثبتها معلّم، و«اشترِ أيضًا» اقترحها
         * نموذج. بلا المصدر يستحيل مراجعة التوصيات أو التراجع عن دفعةٍ خاطئة.
         */
        $this->assertSame('VERIFIED_HUMAN', $human->source);
        $this->assertSame('AI_SUGGESTION', $guess->source);
        $this->assertSame(55, $guess->confidence);
    }

    public function test_an_empty_content_section_is_refused(): void
    {
        $group = $this->group();

        // عنوانٌ معلّق في الصفحة وقسمٌ فارغ في البيانات المبنيَّة.
        $this->expectException(QueryException::class);
        ProductContentSection::create([
            'product_group_id' => $group->id, 'kind' => 'SUMMARY', 'heading' => 'ملخّص',
        ]);
    }
}
