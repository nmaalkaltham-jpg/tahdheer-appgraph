<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminAuditEntry;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductsTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function asProductManager(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
    }

    public function test_empty_catalogue_is_an_explicit_empty_list_not_a_placeholder(): void
    {
        $this->asProductManager();

        $this->getJson('/api/v1/admin/products')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.page', 1);
    }

    public function test_list_filters_by_status_subject_and_free_text(): void
    {
        $c = $this->curriculum();
        $this->makeProduct('TD-A-1', ['status' => 'draft', 'subject_id' => $c['subject']->id]);
        $this->makeProduct('TD-A-2', ['status' => 'published', 'title' => 'تحضير علوم متقدّم']);
        $this->asProductManager();

        $this->getJson('/api/v1/admin/products?status=published')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'TD-A-2');

        $this->getJson('/api/v1/admin/products?subjectId='.$c['subject']->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'TD-A-1');

        $this->getJson('/api/v1/admin/products?'.http_build_query(['q' => 'علوم']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'TD-A-2');

        $this->getJson('/api/v1/admin/products?status=retired')
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_rows_carry_camelcase_keys_and_a_derived_readiness_summary(): void
    {
        $this->makeReadyProduct('TD-B-1');
        $this->asProductManager();

        $response = $this->getJson('/api/v1/admin/products')->assertOk();

        $row = $response->json('data.0');
        $this->assertSame('TD-B-1', $row['sku']);
        $this->assertSame(40000, $row['priceMinor']);          // from ProductCardResource
        $this->assertSame('draft', $row['status']);
        $this->assertTrue($row['readiness']['ready']);
        // The score is counted from the checks, never stored.
        $this->assertSame(count($row['readiness']['items']), $row['readiness']['completedCount']);
        $this->assertSame([], $row['readiness']['missing']);
    }

    /**
     * الرمز يُشتقّ حين يُترك — «اسم · سعر · ملف · نشر».
     *
     * الرمز معرّف داخلي لا يراه المشتري، واشتراط كتابته يدويًّا يجعل إضافة
     * منتجٍ حقلًا رابعًا بلا مقابل. ومن يملك اصطلاح ترقيم يكتبه فيُحترم.
     */
    public function test_the_sku_is_derived_when_omitted_and_kept_when_given(): void
    {
        $this->asProductManager();

        $derived = $this->postJson('/api/v1/admin/products', [
            'productNature' => 'DIGITAL',
            'slug' => 'بلا-رمز', 'title' => 'منتج بلا رمز',
        ])->assertStatus(201)->json('data.sku');

        $this->assertMatchesRegularExpression('/^TD-\d{4}$/', (string) $derived);

        // واثنان متتاليان لا يتصادمان.
        $second = $this->postJson('/api/v1/admin/products', [
            'productNature' => 'DIGITAL',
            'slug' => 'بلا-رمز-٢', 'title' => 'منتج آخر',
        ])->assertStatus(201)->json('data.sku');

        $this->assertNotSame($derived, $second);

        // والمكتوب يدويًّا يبقى كما كُتب.
        $this->assertSame('MY-OWN-CODE', $this->postJson('/api/v1/admin/products', [
            'productNature' => 'DIGITAL',
            'sku' => 'MY-OWN-CODE', 'slug' => 'برمز', 'title' => 'منتج برمز',
        ])->assertStatus(201)->json('data.sku'));
    }

    /**
     * سلسلةٌ تكسر العنوان لا تُخزَّن.
     *
     * الرابط يُركَّب في عناوين المتجر كما هو، فـ`#` تقطع المسار عند موضعها
     * و`?` تبدأ استعلامًا و`%` تكسر فكّ الترميز. القاعدة القديمة كانت تمنع
     * المسافة والشرطة المائلة وحدهما — فتمرّ هذه كلّها وتُنتج منتجًا لا يصله
     * رابطه.
     */
    public function test_a_slug_that_would_break_its_own_url_is_refused(): void
    {
        $this->asProductManager();

        foreach (['خصم%50', 'ملف#1', 'أ?ب', 'أ&ب', 'أ+ب', 'أ\\ب'] as $index => $bad) {
            $this->postJson('/api/v1/admin/products', [
                'productNature' => 'DIGITAL',
                'sku' => 'TD-S-'.$index, 'slug' => $bad, 'title' => 'عنوان',
            ])->assertStatus(422);
        }

        // والعربية والشرطات تمرّ — وهي المقصودة.
        $this->postJson('/api/v1/admin/products', [
            'productNature' => 'DIGITAL',
            'sku' => 'TD-S-OK', 'slug' => 'تحضير-الرياضيات-رابع-ابتدائي', 'title' => 'عنوان',
        ])->assertStatus(201);
    }

    /**
     * السحب — الاتجاه المعاكس للنشر.
     *
     * `retired` حالةٌ مصمَّمة بالكامل (القائمة تُرشِّح بها، وسياسة الفهرسة لها
     * حكمٌ خاصّ) ولم يكن في المنتج مسارٌ يبلغها: ملفٌّ نُشر بالخطأ لا يُنزَل.
     */
    public function test_a_published_product_can_be_retired_and_leaves_the_public_catalogue(): void
    {
        $c = $this->curriculum();
        $this->asProductManager();

        $id = $this->postJson('/api/v1/admin/products', [
            'productNature' => 'DIGITAL',
            'sku' => 'TD-W-1', 'slug' => 'td-w-1', 'title' => 'ملف للسحب',
        ])->assertStatus(201)->json('data.id');

        Product::query()->whereKey($id)->update(['status' => 'published', 'published_at' => now()]);
        $this->getJson('/api/v1/products/td-w-1')->assertOk();

        $this->patchJson("/api/v1/admin/products/{$id}/withdraw", ['to' => 'retired'])
            ->assertOk()
            ->assertJsonPath('data.status', 'retired');

        // المسحوب يخرج من كل سطح عامّ فورًا.
        $this->getJson('/api/v1/products/td-w-1')->assertNotFound();
    }

    /** `draft` تعيده إلى التحرير، والنشر ثانيةً يمرّ بالبوابة من جديد. */
    public function test_withdrawing_to_draft_sends_it_back_through_the_readiness_gate(): void
    {
        $this->asProductManager();

        $id = $this->postJson('/api/v1/admin/products', [
            'productNature' => 'DIGITAL',
            'sku' => 'TD-W-2', 'slug' => 'td-w-2', 'title' => 'ملف ناقص',
        ])->assertStatus(201)->json('data.id');

        Product::query()->whereKey($id)->update(['status' => 'published', 'published_at' => now()]);

        $this->patchJson("/api/v1/admin/products/{$id}/withdraw", ['to' => 'draft'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->patchJson("/api/v1/admin/products/{$id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('code', 'READINESS_INCOMPLETE');
    }

    /** لا باب خلفيّ إلى النشر: `published` غير مقبولة على هذا المسار. */
    public function test_withdraw_refuses_to_publish(): void
    {
        $this->asProductManager();

        $id = $this->postJson('/api/v1/admin/products', [
            'productNature' => 'DIGITAL',
            'sku' => 'TD-W-3', 'slug' => 'td-w-3', 'title' => 'ملف',
        ])->assertStatus(201)->json('data.id');

        $this->patchJson("/api/v1/admin/products/{$id}/withdraw", ['to' => 'published'])
            ->assertStatus(422);

        $this->assertSame('draft', Product::query()->whereKey($id)->value('status'));
    }

    public function test_create_stores_minor_units_classification_and_facts_as_a_draft(): void
    {
        $c = $this->curriculum();
        $this->asProductManager();

        $response = $this->postJson('/api/v1/admin/products', [
            'productNature' => 'DIGITAL',
            'sku' => 'TD-C-1',
            'slug' => 'td-c-1',
            'title' => 'تحضير رياضيات الصف الرابع',
            'shortDesc' => 'وصف مختصر',
            'stageId' => $c['stage']->id,
            'gradeId' => $c['grade']->id,
            'subjectId' => $c['subject']->id,
            'semesterId' => $c['semester']->id,
            'regularMinor' => 45000,
            'saleMinor' => 30000,
            'facts' => [
                ['key' => 'slides_count', 'intValue' => 42],
                ['key' => 'format_note', 'textValue' => 'قابل للتعديل'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.sku', 'TD-C-1')
            ->assertJsonPath('data.status', 'draft')   // never published on create
            ->assertJsonPath('data.regularMinor', 45000)
            ->assertJsonPath('data.priceMinor', 30000) // active sale, resolved by PricingService
            ->assertJsonPath('data.onSale', true);

        $this->assertDatabaseHas('product_prices', ['regular_minor' => 45000, 'sale_minor' => 30000]);
        $this->assertDatabaseHas('product_facts', ['key' => 'slides_count', 'int_value' => 42]);
        $this->assertDatabaseHas('admin_audit_log', ['action' => 'product.created', 'entity_type' => 'product']);
    }

    public function test_update_rewrites_price_and_syncs_facts_and_records_the_previous_price(): void
    {
        $product = $this->makeReadyProduct('TD-D-1');
        $this->asProductManager();

        $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'title' => 'عنوان محدَّث',
            'regularMinor' => 52000,
            'facts' => [['key' => 'pages_count', 'intValue' => 12]],
        ])->assertOk()->assertJsonPath('data.title', 'عنوان محدَّث')->assertJsonPath('data.regularMinor', 52000);

        $this->assertDatabaseHas('product_prices', ['product_id' => $product->id, 'regular_minor' => 52000]);

        $audit = AdminAuditEntry::query()->where('action', 'product.updated')->firstOrFail();
        $this->assertSame(40000, $audit->before['regularMinor']);
        $this->assertSame(52000, $audit->after['regularMinor']);
    }

    /**
     * انحدار: نافذة التخفيض تعود باللحظة نفسها التي أُرسلت.
     *
     * العمودان بلا منطقة زمنية، والقراءة تَسِمهما بـ`app.timezone`. فحين كانت
     * الكتابة تُبقي إزاحة الوارد كما هي، كان جدار ساعتها يُخزَّن ثم يُقرأ
     * كأنّه بتوقيت التطبيق — انزياحٌ صامت بمقدار الفرق (ثلاث ساعات بتوقيت
     * الرياض). ولأن `PricingService` تقارن الحدّين بـ`now()` المَوسومة صحيحًا،
     * كان التخفيض يبدأ وينتهي قبل موعده.
     */
    public function test_the_sale_window_survives_the_round_trip_as_the_same_instant(): void
    {
        $product = $this->makeReadyProduct('TD-TZ-1');
        $this->asProductManager();

        // منتصف ليل ١ سبتمبر بتوقيت الرياض، مُرسَلًا بإزاحته كما يرسله المحرّر.
        $from = '2026-08-31T21:00:00.000Z';
        $to = '2026-12-31T20:59:00.000Z';

        $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'regularMinor' => 52000,
            'saleMinor' => 39000,
            'saleFrom' => $from,
            'saleTo' => $to,
        ])->assertOk();

        $price = $product->fresh()->price;

        $this->assertTrue(
            $price->sale_from->equalTo(Carbon::parse($from)),
            'بداية التخفيض المقروءة ليست اللحظة المُرسَلة: '.$price->sale_from->toIso8601String(),
        );
        $this->assertTrue(
            $price->sale_to->equalTo(Carbon::parse($to)),
            'نهاية التخفيض المقروءة ليست اللحظة المُرسَلة: '.$price->sale_to->toIso8601String(),
        );

        // والحدّ كما يقرأه محرّك التسعير: منتصف ليل بتوقيت الرياض لا التاسعة مساءً.
        $this->assertSame(
            '2026-09-01 00:00:00',
            $price->sale_from->copy()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i:s'),
        );
    }

    public function test_publish_is_blocked_with_422_listing_the_real_missing_readiness_items(): void
    {
        // Title only: classification, price, file and image are all absent.
        $product = $this->makeProduct('TD-E-1');
        $this->asProductManager();

        $response = $this->patchJson("/api/v1/admin/products/{$product->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('code', 'READINESS_INCOMPLETE')
            ->assertJsonPath('readiness.ready', false)
            ->assertJsonPath('readiness.completedCount', 1)
            ->assertJsonPath('readiness.totalCount', 5)
            ->assertJsonPath('readiness.missing', ['classification', 'price', 'file', 'image']);

        $this->assertStringContainsString('التصنيف التعليمي', (string) $response->json('message'));
        $this->assertSame('draft', $product->fresh()->status);
        $this->assertNull($product->fresh()->published_at);
    }

    public function test_the_missing_list_shrinks_as_real_rows_appear(): void
    {
        $c = $this->curriculum();
        $product = $this->makeProduct('TD-E-2', [
            'stage_id' => $c['stage']->id, 'grade_id' => $c['grade']->id,
            'subject_id' => $c['subject']->id, 'semester_id' => $c['semester']->id,
        ]);
        $this->asProductManager();

        $this->patchJson("/api/v1/admin/products/{$product->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('readiness.missing', ['price', 'file', 'image'])
            ->assertJsonPath('readiness.completedCount', 2);
    }

    public function test_publish_succeeds_only_at_one_hundred_percent_and_is_audited(): void
    {
        $product = $this->makeReadyProduct('TD-F-1');
        $this->asProductManager();

        $this->patchJson("/api/v1/admin/products/{$product->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('readiness.ready', true);

        $fresh = $product->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertNotNull($fresh->published_at);

        $audit = AdminAuditEntry::query()->where('action', 'product.published')->firstOrFail();
        $this->assertSame('draft', $audit->before['status']);
        $this->assertSame('published', $audit->after['status']);
    }

    public function test_duplicate_sku_is_rejected_with_an_arabic_message(): void
    {
        $this->makeProduct('TD-G-1');
        $this->asProductManager();

        $this->postJson('/api/v1/admin/products', ['productNature' => 'DIGITAL', 'sku' => 'TD-G-1', 'slug' => 'other', 'title' => 'عنوان'])
            ->assertStatus(422)
            ->assertJsonPath('errors.sku.0', 'القيمة مستخدمة من قبل.');

        $this->assertSame(1, Product::query()->count());
    }
}
