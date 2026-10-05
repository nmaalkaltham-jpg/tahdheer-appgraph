<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\I18n\LanguageEligibility;
use App\Domain\I18n\Locale;
use App\Domain\I18n\ProductTranslation;
use App\Domain\SEO\MetadataService;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commerce\CommerceFixtures;
use Tests\TestCase;

/**
 * طبقة اللغات: الأهلية، والترجمة المحفوظة، وhreflang.
 *
 * الخطر الذي تحرسه هذه الاختبارات: إعلان لغةٍ للعالم قبل أن تكون لها صفحة.
 */
class LanguageEligibilityTest extends TestCase
{
    use CommerceFixtures;
    use RefreshDatabase;

    private function engine(): LanguageEligibility
    {
        // نسخة جديدة لكل حالة: المحرّك يذكّر سجلّ اللغات داخل الطلب.
        return new LanguageEligibility;
    }

    /** اللغة المسجَّلة وغير المفعّلة تُقال «محجوبة» بسبب مُسمّى، لا «غير مكتملة». */
    public function test_an_inactive_locale_is_blocked_by_the_store_not_by_the_editor(): void
    {
        $product = $this->makeProduct('TD-I18N-1', 4000);
        $en = Locale::query()->findOrFail('en');

        $state = $this->engine()->forLocale($product, $en);

        $this->assertSame(LanguageEligibility::BLOCKED, $state['status']);
        $this->assertFalse($state['canPublish']);
        $this->assertContains('locale_active', $state['missing']);

        $reason = collect($state['requirements'])->firstWhere('key', 'locale_active');
        $this->assertSame('store', $reason['scope'], 'ليس عيبًا في عمل المحرّر');
        $this->assertNotNull($reason['detail'], 'الحجب بلا سبب مكتوب شارة لا تفسير');
    }

    /** تفعيل اللغة لا يكفي: الواجهة يجب أن تخدم المسار. */
    public function test_activating_a_locale_without_frontend_routes_still_blocks(): void
    {
        $product = $this->makeProduct('TD-I18N-2', 4000);
        $en = Locale::query()->findOrFail('en');
        $en->forceFill(['is_active' => true, 'is_routable' => false])->save();

        $state = $this->engine()->forLocale($product, $en);

        $this->assertSame(LanguageEligibility::BLOCKED, $state['status']);
        $this->assertContains('locale_routable', $state['missing']);
        $this->assertNotContains('locale_active', $state['missing']);
    }

    /** لغة موصولة بلا ترجمة: ناقصة محتوى — والمطلوب مُسمّى بالاسم. */
    public function test_a_routable_locale_without_a_translation_is_incomplete(): void
    {
        $product = $this->makeProduct('TD-I18N-3', 4000);
        $product->forceFill(['status' => 'published'])->save();
        $this->enableEnglish();

        $state = $this->engine()->forLocale($product->refresh(), Locale::query()->findOrFail('en'));

        $this->assertSame(LanguageEligibility::INCOMPLETE, $state['status']);
        $this->assertFalse($state['translationExists']);
        $this->assertContains('translation_exists', $state['missing']);
        $this->assertContains('title', $state['missing']);
        $this->assertContains('slug', $state['missing']);
    }

    /** الترجمة المكتملة وغير المنشورة: جاهزة — لا «منشورة». */
    public function test_a_complete_unpublished_translation_is_ready_not_published(): void
    {
        $product = $this->publishedProductWithEnglish('TD-I18N-4', publishTranslation: false);

        $state = $this->engine()->forLocale($product, Locale::query()->findOrFail('en'));

        $this->assertSame(LanguageEligibility::READY, $state['status']);
        $this->assertTrue($state['canPublish']);
        $this->assertFalse($state['hreflangEmitted'], 'غير المنشور لا يُعلَن');
    }

    // ───── hreflang ─────

    /** لا نظير ⇒ لا hreflang: رابط وحيد ليس بديلًا. */
    public function test_no_alternates_are_emitted_for_a_single_language_product(): void
    {
        $product = $this->makeProduct('TD-I18N-5', 4000);
        $product->forceFill(['status' => 'published'])->save();

        $this->assertSame([], $this->engine()->alternatesFor($product->refresh()));
    }

    /** الترجمة المنشورة القابلة للفهرسة تُعلَن — بمسارها الحقيقي. */
    public function test_a_published_indexable_translation_is_announced(): void
    {
        $product = $this->publishedProductWithEnglish('TD-I18N-6', publishTranslation: true);

        $alternates = $this->engine()->alternatesFor($product);

        $this->assertCount(2, $alternates);
        $this->assertSame(['ar-SA', 'en'], array_column($alternates, 'hreflang'));
        // الافتراضية بلا بادئة، والثانية بالبادئة المسجَّلة.
        $this->assertSame('/products/'.$product->slug, $alternates[0]['path']);
        $this->assertSame('/en/products/td-i18n-6-en', $alternates[1]['path']);
        $this->assertTrue($alternates[0]['isDefault']);
    }

    /** noindex على الترجمة يمنع إعلانها حتى وهي منشورة. */
    public function test_a_noindex_translation_is_never_announced(): void
    {
        $product = $this->publishedProductWithEnglish('TD-I18N-7', publishTranslation: true);
        ProductTranslation::query()->where('product_id', $product->id)
            ->update(['is_indexable' => false]);

        $this->assertSame([], $this->engine()->alternatesFor($product->refresh()->load('translations')));
    }

    /** سحب المنتج الأصل من النشر يسحب كل إعلاناته اللغوية معه. */
    public function test_unpublishing_the_source_withdraws_every_announcement(): void
    {
        $product = $this->publishedProductWithEnglish('TD-I18N-8', publishTranslation: true);
        $product->forceFill(['status' => 'draft'])->save();

        $this->assertSame([], $this->engine()->alternatesFor($product->refresh()->load('translations')));
    }

    /** وتصل الروابط إلى وسوم الصفحة الفعلية عبر الخدمة القائمة. */
    public function test_alternates_reach_the_page_metadata_as_absolute_urls(): void
    {
        $product = $this->publishedProductWithEnglish('TD-I18N-9', publishTranslation: true);

        $meta = app(MetadataService::class)->forProduct($product->load('seo', 'translations'));

        $this->assertArrayHasKey('alternates', $meta);
        $this->assertCount(2, $meta['alternates']);
        foreach ($meta['alternates'] as $alternate) {
            $this->assertStringStartsWith('http', $alternate['href'], 'hreflang يقبل المطلق فقط');
        }
    }

    // ───── الحفظ ─────

    /** الترجمة تُحفظ في جدول وتنجو من قراءة جديدة. */
    public function test_a_translation_survives_a_fresh_read(): void
    {
        $product = $this->makeProduct('TD-I18N-10', 4000);

        ProductTranslation::query()->create([
            'product_id' => $product->id, 'locale_code' => 'en',
            'title' => 'Mathematics Lesson Preparation', 'slug' => 'math-prep',
        ]);

        $fresh = ProductTranslation::query()
            ->where('product_id', $product->id)->where('locale_code', 'en')->firstOrFail();

        $this->assertSame('Mathematics Lesson Preparation', $fresh->title);
        $this->assertSame('draft', $fresh->status, 'الحفظ ليس نشرًا');
    }

    /** اللغة الافتراضية ليست ترجمة: لا صفّ ثانٍ للنصّ العربي. */
    public function test_the_default_locale_is_measured_from_the_product_row_itself(): void
    {
        $product = $this->makeProduct('TD-I18N-11', 4000);
        $product->forceFill(['status' => 'published'])->save();

        $state = $this->engine()->forLocale($product->refresh(), Locale::query()->findOrFail('ar'));

        $this->assertTrue($state['translationExists']);
        $this->assertSame(LanguageEligibility::PUBLISHED, $state['status']);
        $this->assertSame(0, ProductTranslation::query()->where('product_id', $product->id)->count());
    }
    // ───── مساعدات ─────

    private function enableEnglish(): void
    {
        Locale::query()->where('code', 'en')
            ->update(['is_active' => true, 'is_routable' => true]);
    }

    private function publishedProductWithEnglish(string $sku, bool $publishTranslation): Product
    {
        $product = $this->makeProduct($sku, 4000);
        $product->forceFill(['status' => 'published', 'published_at' => now()])->save();
        $this->enableEnglish();

        ProductTranslation::query()->create([
            'product_id' => $product->id,
            'locale_code' => 'en',
            'title' => 'Lesson Preparation Pack',
            'short_desc' => 'A complete preparation pack.',
            'slug' => strtolower($sku).'-en',
            'status' => $publishTranslation ? 'published' : 'draft',
            'published_at' => $publishTranslation ? now() : null,
            'is_indexable' => true,
        ]);

        return $product->refresh()->load('translations');
    }
}
