<?php

namespace Tests\Feature\Search;

use App\Domain\Admin\AdminRole;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxProcessor;
use App\Domain\Outbox\OutboxRegistry;
use App\Domain\Search\Indexing\ProductIndexSync;
use App\Domain\Search\Indexing\SearchOutboxSubscriber;
use App\Domain\Search\Indexing\SyncProductJob;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * الطرف المنتِج لسلسلة الفهرسة.
 *
 * `IndexingTest` يثبت أنّ المستهلك يعمل — ويكتب صفّ الصندوق بيده. فكان
 * نصف السلسلة مبرهنًا ونصفها غائبًا: لا مسار كتابةٍ في التطبيق يكتب
 * `product.published` ولا `product.updated`، فالمستهلك ينتظر حدثًا لا منتج
 * له. هذه الاختبارات تسأل **مسار اللوحة الحقيقيّ** لا الكاتب المباشر.
 */
class ProductIndexProducerTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OutboxRegistry::flush();
    }

    private function asProductManager(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
    }

    /** @return list<string> */
    private function indexEventsFor(Product $product): array
    {
        return OutboxEvent::query()
            ->where('aggregate_type', 'product')
            ->where('aggregate_id', (string) $product->id)
            ->whereIn('event_type', SearchOutboxSubscriber::EVENTS)
            ->orderBy('id')
            ->pluck('event_type')
            ->all();
    }

    public function test_publishing_a_product_writes_the_index_event_the_consumer_waits_for(): void
    {
        $product = $this->makeReadyProduct('TD-IX-1');
        $this->asProductManager();

        $this->patchJson("/api/v1/admin/products/{$product->id}/publish")->assertOk();

        $this->assertSame([ProductIndexSync::PUBLISHED], $this->indexEventsFor($product));
    }

    /**
     * الاتّجاه الذي كان غيابه يُبقي المسحوب معروضًا.
     *
     * `Indexer::syncProduct` وحده يحذف الوثيقة، ولم يكن أحد ينادِه على
     * السحب — فيبقى المنتج المتقاعد قابلًا للبحث بعد سحبه.
     */
    public function test_withdrawing_a_product_writes_the_event_that_deletes_its_document(): void
    {
        $product = $this->makeReadyProduct('TD-IX-2');
        $this->asProductManager();
        $this->patchJson("/api/v1/admin/products/{$product->id}/publish")->assertOk();

        $this->patchJson("/api/v1/admin/products/{$product->id}/withdraw", ['to' => 'retired'])->assertOk();

        $this->assertSame(
            [ProductIndexSync::PUBLISHED, ProductIndexSync::WITHDRAWN],
            $this->indexEventsFor($product),
        );
    }

    /**
     * تعديلان متتاليان ⇒ حدثان. وهو ما أسقط أوّل اشتقاقٍ للمفتاح.
     *
     * كان المفتاح يُشتقّ من `updated_at`، و`Model::setUpdatedAt` يقصّ القيمة
     * إلى الثانية في الذاكرة أيضًا — فتصحيحُ سعرٍ بعد سعرٍ في الثانية نفسها
     * يحمل المفتاح ذاته فيُبتلَع، ويبقى السعر الأوّل في الفهرس بلا خطأ.
     * (مقيس هنا: حدثٌ واحد لتعديلين.) وهذا الاختبار يحرس الاتّجاه الصحيح.
     */
    public function test_two_successive_price_edits_produce_two_index_events(): void
    {
        $product = $this->makeReadyProduct('TD-IX-3');
        $this->asProductManager();

        $this->patchJson("/api/v1/admin/products/{$product->id}", ['regularMinor' => 51000])->assertOk();
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['regularMinor' => 52000])->assertOk();

        $this->assertSame(
            [ProductIndexSync::UPDATED, ProductIndexSync::UPDATED],
            $this->indexEventsFor($product),
        );
    }

    /** السلسلة كاملةً: نشرٌ من اللوحة ⇒ صفّ صندوق ⇒ مهمّة مزامنة مصفوفة. */
    public function test_the_published_event_reaches_the_sync_job_through_the_processor(): void
    {
        Queue::fake();
        SearchOutboxSubscriber::register();
        $product = $this->makeReadyProduct('TD-IX-4');
        $this->asProductManager();

        $this->patchJson("/api/v1/admin/products/{$product->id}/publish")->assertOk();
        /*
         * النشر يكتب حدث العنوان أيضًا (`url.published`)، والمعالج يستوفي كلّ
         * المستحقّ. فالعدد المؤكَّد هنا هو ما وصل **مهمّة المزامنة**، لا عدد
         * الصفوف المعالَجة — وربطُ التأكيد بالثاني يجعله يكسر كلّما أُضيف
         * مستهلكٌ لا علاقة له بالفهرسة.
         */
        (new OutboxProcessor)->process();

        Queue::assertPushed(SyncProductJob::class, 1);
        Queue::assertPushed(
            SyncProductJob::class,
            fn (SyncProductJob $job) => $job->productId === $product->id,
        );
    }
}
