<?php

namespace Tests\Feature\Reviews;

use App\Domain\Privacy\PrivacyService;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفة المراجِع ومدينته — «معلّمة علوم، الرياض».
 *
 * سطرٌ يُنشر تحت الاسم، فيخضع لقاعدتين معًا: يُعرض حين يُكتب ويختفي حين
 * لا يُكتب (لا دورَ مخترَع)، ويُمحى مع محو بيانات صاحبه (مهنةٌ ومدينةٌ
 * منشورتان تحت تقييمٍ مؤرَّخ تدلّان على شخص).
 */
class ReviewAuthorAttributionTest extends TestCase
{
    use RefreshDatabase, ReviewsFixtures;

    public function test_a_reviewer_may_publish_a_role_and_a_city_with_the_review(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product, 'PAID');

        $this->withHeader('X-Order-Token', $order['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", [
                'rating' => 5,
                'body' => 'التحضير مرتّب ومطابق للكتاب درسًا بدرس.',
                'authorRole' => 'معلّمة علوم',
                'authorCity' => 'الرياض',
            ])->assertCreated();

        $review = Review::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('معلّمة علوم', $review->author_role);
        $this->assertSame('الرياض', $review->author_city);

        app(ReviewService::class)->approve($review);

        $item = $this->getJson("/api/v1/products/{$product->id}/reviews")
            ->assertOk()->json('items.0');

        $this->assertSame('معلّمة علوم', $item['authorRole']);
        $this->assertSame('الرياض', $item['authorCity']);
    }

    public function test_a_review_without_attribution_omits_both_keys(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product, 'PAID');

        $this->withHeader('X-Order-Token', $order['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", [
                'rating' => 4,
                'body' => 'ملف جيّد وسهل التعديل على Word.',
            ])->assertCreated();

        app(ReviewService::class)->approve(Review::query()->firstOrFail());

        $item = $this->getJson("/api/v1/products/{$product->id}/reviews")
            ->assertOk()->json('items.0');

        $this->assertArrayNotHasKey('authorRole', $item, 'دورٌ مخترَع لمراجِعٍ لم يكتبه');
        $this->assertArrayNotHasKey('authorCity', $item);
    }

    public function test_an_over_long_role_is_refused_not_silently_truncated(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product, 'PAID');

        $this->withHeader('X-Order-Token', $order['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", [
                'rating' => 5,
                'body' => 'نصٌّ صالح للتقييم.',
                'authorRole' => str_repeat('ا', 121),
            ])->assertStatus(422)->assertJsonValidationErrors('authorRole');
    }

    public function test_erasing_a_customer_clears_the_published_role_and_city(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product, 'PAID');

        $this->withHeader('X-Order-Token', $order['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", [
                'rating' => 5,
                'body' => 'اعتمدناه لمعلّمي العلوم عندنا.',
                'authorRole' => 'وكيل شؤون تعليمية',
                'authorCity' => 'أبها',
            ])->assertCreated();

        $result = app(PrivacyService::class)->erase($customer);
        $this->assertTrue($result['anonymized']);

        $review = Review::query()->firstOrFail();
        $this->assertNull($review->author_role, 'صفةٌ نجت من المحو تُبقي صاحبها معرَّفًا');
        $this->assertNull($review->author_city);
        /* والنصّ يبقى: رأيٌ عن منتج لا بياناتُ شخص. */
        $this->assertSame('اعتمدناه لمعلّمي العلوم عندنا.', $review->body);
    }

    public function test_the_data_export_includes_what_the_reviewer_published(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product, 'PAID');

        $this->withHeader('X-Order-Token', $order['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", [
                'rating' => 5,
                'body' => 'وفّر عليّ ليلتين من التحضير.',
                'authorRole' => 'معلّمة علوم',
                'authorCity' => 'جدة',
            ])->assertCreated();

        $export = app(PrivacyService::class)->export($customer);

        $this->assertSame('معلّمة علوم', $export['reviews'][0]->author_role);
        $this->assertSame('جدة', $export['reviews'][0]->author_city);
    }

    public function test_an_erased_name_is_not_chopped_into_a_plausible_person(): void
    {
        $product = $this->makeProduct();
        $customer = $this->makeCustomer();
        $order = $this->makePaidOrder($customer, $product, 'PAID');

        $this->withHeader('X-Order-Token', $order['token'])
            ->postJson("/api/v1/products/{$product->id}/reviews", [
                'rating' => 5,
                'body' => 'رأيٌ يبقى منشورًا بعد محو صاحبه.',
            ])->assertCreated();

        app(ReviewService::class)->approve(Review::query()->firstOrFail());
        app(PrivacyService::class)->erase($customer);

        $item = $this->getJson("/api/v1/products/{$product->id}/reviews")
            ->assertOk()->json('items.0');

        $this->assertSame(
            'مشترٍ موثّق',
            $item['authorName'],
            'اسمٌ مطموس قُصّ إلى اسمٍ يشبه اسمًا — تُظهر الصفحة شخصًا حيث تقول البيانات إنّه مُحي',
        );
        $this->assertStringNotContainsString('[', $item['authorName']);
    }
}
