<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Domain\Admin\AdminRole;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\ReviewService;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * إدارة المراجعات — الوصلة التي انتظرت حارس الإدارة.
 *
 * الخدمة مكتوبة ومُختبَرة منذ بناء النطاق، والمسارات لم تُنشر لغياب الحارس.
 * النتيجة المقيسة قبل هذا: كل مراجعة يكتبها عميل تدخل `pending` ولا سبيل
 * لاعتمادها — باب مفتوح للكتابة مغلق للنشر.
 */
class ReviewModerationTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::query()->create([
            'sku' => 'TD-1',
            'slug' => 'p-1',
            'title' => 'تحضير الرياضيات',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    private function review(string $status = Review::PENDING, int $rating = 5): Review
    {
        $customer = Customer::query()->create([
            'name' => 'معلّمة',
            'email' => 'teacher'.uniqid().'@example.com',
        ]);

        return Review::query()->create([
            'product_id' => $this->product->id,
            'customer_id' => $customer->id,
            'rating' => $rating,
            'title' => 'ملف ممتاز',
            'body' => 'استفدت منه في الفصل.',
            'status' => $status,
        ]);
    }

    /** @return array<int, mixed> المراجعات كما يراها زائر الصفحة. */
    private function publicItems(): array
    {
        return $this->getJson("/api/v1/products/{$this->product->id}/reviews")
            ->assertOk()->json('items');
    }

    /* ───── الحماية ───── */

    public function test_moderation_requires_authentication(): void
    {
        $review = $this->review();

        $this->getJson('/api/v1/admin/reviews')->assertStatus(401);
        $this->postJson("/api/v1/admin/reviews/{$review->id}/approve")->assertStatus(401);
    }

    /** المحلّل يقرأ التحليلات ولا ينشر محتوى عامًّا. */
    public function test_a_role_without_the_permission_is_refused(): void
    {
        $analyst = $this->makeAdmin(AdminRole::Analytics);

        $this->actingAsAdmin($analyst)->getJson('/api/v1/admin/reviews')->assertStatus(403);
    }

    /* ───── الطابور ───── */

    /** الأقدم أوّلًا: المراجعة التي طال انتظارها أولى بالقرار. */
    public function test_the_queue_shows_pending_oldest_first_with_counts(): void
    {
        $old = $this->review();
        $old->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->review();
        $this->review(Review::APPROVED);

        $body = $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->getJson('/api/v1/admin/reviews')->assertOk()->json();

        $this->assertCount(2, $body['data'], 'الطابور يعرض المنتظِرة وحدها');
        $this->assertSame($old->id, $body['data'][0]['id']);
        $this->assertSame(2, $body['counts']['pending']);
        $this->assertSame(1, $body['counts']['approved']);
        $this->assertSame(3, $body['counts']['all']);
    }

    /* ───── القرار ───── */

    public function test_approving_publishes_the_review_to_the_storefront(): void
    {
        $review = $this->review();

        // قبل الاعتماد لا تظهر للعامّة — وهذا هو العطل الذي كان قائمًا.
        $this->assertSame([], $this->publicItems());

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->postJson("/api/v1/admin/reviews/{$review->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Review::APPROVED);

        $this->assertCount(1, $this->publicItems(), 'الاعتماد لم يُظهر المراجعة للعامّة');
    }

    public function test_rejecting_keeps_it_out_of_the_public_feed(): void
    {
        $review = $this->review(Review::APPROVED);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->postJson("/api/v1/admin/reviews/{$review->id}/reject", ['note' => 'خارج الموضوع'])
            ->assertOk()
            ->assertJsonPath('data.status', Review::REJECTED);

        $this->assertSame([], $this->publicItems());
    }

    /** ردٌّ عامّ تحت مراجعة لم تُنشر يعرض نصف حوار — فالنقطة ترفضه. */
    public function test_a_reply_to_an_unapproved_review_is_refused(): void
    {
        $review = $this->review();

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->postJson("/api/v1/admin/reviews/{$review->id}/reply", ['body' => 'شكرًا لك'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'REVIEW_NOT_APPROVED');
    }

    public function test_a_reply_on_an_approved_review_is_stored_and_returned(): void
    {
        $review = $this->review(Review::APPROVED);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->postJson("/api/v1/admin/reviews/{$review->id}/reply", ['body' => 'شكرًا لملاحظتك'])
            ->assertOk()
            ->assertJsonPath('data.reply', 'شكرًا لملاحظتك');
    }

    /*
     * دُمج من ReviewModerationTransitionTest: الموضوع نفسه على مستوى الخدمة.
     */
    private function pending(): Review
    {
        $n = uniqid();
        $product = Product::create(['sku' => "S-{$n}", 'slug' => "p-{$n}", 'title' => 'م', 'status' => 'published', 'fulfillment_type' => 'digital']);
        $customer = Customer::create(['email' => "{$n}@t.local", 'name' => 'ز']);

        return Review::create([
            'product_id' => $product->id, 'customer_id' => $customer->id,
            'rating' => 4, 'body' => 'جيّد جدًّا وواضح', 'status' => Review::PENDING,
        ]);
    }

    public function test_a_rejected_review_cannot_be_approved_without_a_new_moderation(): void
    {
        $service = app(ReviewService::class);
        $review = $this->pending();
        $service->reject($review, 'مخالف');

        $this->expectException(\DomainException::class);
        $service->approve($review->fresh());
    }

    public function test_moderated_at_is_written_once(): void
    {
        $service = app(ReviewService::class);
        $review = $this->pending();

        $service->approve($review);
        $first = $review->fresh()->moderated_at;
        $this->travel(2)->hours();
        $service->reject($review->fresh(), 'تراجع');

        $this->assertEquals($first, $review->fresh()->moderated_at);
        $this->assertSame(Review::REJECTED, $review->fresh()->status);
    }
}
