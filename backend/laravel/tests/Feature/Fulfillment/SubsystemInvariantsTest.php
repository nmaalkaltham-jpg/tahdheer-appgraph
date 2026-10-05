<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\ServiceResource;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Licensing\Models\GrantedLicense;
use App\Domain\Licensing\Models\LicenseKey;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Subscriptions\Models\Subscription;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قيود الأنظمة الأربعة: الإرجاع والرخص والاشتراكات والحجوزات.
 *
 * كلّ اختبارٍ هنا يصف حالةً **تمرّ صامتةً** لو لم يمنعها المحرّك، ثمّ تظهر
 * بعد أسابيع بوصفها خللًا لا يُفسَّر: مالٌ رُدّ بلا مبلغ، ومفتاحٌ اختفى من
 * المخزون بلا مالك، واشتراكٌ نشطٌ لا يُجدَّد، وموعدان لمعلّمٍ واحد.
 */
class SubsystemInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create(['email' => uniqid().'@test.local', 'name' => 'زبون']);
    }

    private function order(?Customer $c = null): Order
    {
        $c ??= $this->customer();

        return Order::create([
            'number' => 'TD-'.uniqid(), 'customer_id' => $c->id, 'email' => $c->email,
            'status' => 'PAID', 'total_minor' => 5000,
        ]);
    }

    private function variant(): ProductVariant
    {
        $n = uniqid();
        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'م', 'product_nature' => 'DIGITAL', 'status' => 'published',
        ]);

        return ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}", 'status' => 'published',
        ]);
    }

    private function resource(): ServiceResource
    {
        return ServiceResource::create([
            'code' => 'R-'.uniqid(), 'name' => 'معلّم', 'kind' => 'PERSON',
        ]);
    }

    private function booking(ServiceResource $r, string $from, string $to, array $extra = []): Booking
    {
        return Booking::create($extra + [
            'number' => 'BK-'.uniqid(),
            'service_resource_id' => $r->id,
            'starts_at' => $from, 'ends_at' => $to,
            'timezone' => 'Asia/Riyadh', 'seats' => 1,
            'status' => 'CONFIRMED',
            'idempotency_key' => uniqid(),
        ]);
    }

    // ── الإرجاع (§٣٣) ────────────────────────────────────────────────

    public function test_a_refunded_return_must_carry_an_amount(): void
    {
        /*
         * «رُدّ الثمن» علمًا منطقيًّا بلا رقمٍ يُطابَق مع الدفتر المحاسبيّ
         * هو بالضبط ما يجعل المطابقة الشهريّة مستحيلة (§١٠٢).
         */
        $this->expectException(QueryException::class);
        ReturnRequest::create([
            'number' => 'RMA-'.uniqid(), 'order_id' => $this->order()->id,
            'status' => 'REFUNDED', 'customer_reason' => 'لم يناسبني',
        ]);
    }

    public function test_a_rejected_return_must_say_why(): void
    {
        $this->expectException(QueryException::class);
        ReturnRequest::create([
            'number' => 'RMA-'.uniqid(), 'order_id' => $this->order()->id,
            'status' => 'REJECTED', 'customer_reason' => 'لم يناسبني',
        ]);
    }

    // ── الرخص (§١٩ §٢٠) ──────────────────────────────────────────────

    public function test_seats_used_can_never_exceed_seats_granted(): void
    {
        $order = $this->order();
        $product = Product::create([
            'sku' => 'S-'.uniqid(), 'slug' => 's-'.uniqid(), 'title' => 'م',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'title_snapshot' => 'م', 'price_snapshot_minor' => 5000,
        ]);

        // رخصةٌ تُخالَف بلا أن يُلاحظ أحد هي رخصةٌ بلا معنى.
        $this->expectException(QueryException::class);
        GrantedLicense::create([
            'order_id' => $order->id, 'order_item_id' => $item->id,
            'customer_id' => $order->customer_id,
            'license_type' => 'TEAM', 'seat_count' => 5, 'seats_used' => 6,
            'terms_version' => 'v1',
        ]);
    }

    public function test_an_assigned_key_must_have_an_owner(): void
    {
        /*
         * مفتاحٌ مُسنَد بلا مالك يختفي من المتاح ولا يصل أحدًا — فينفد
         * المخزون بمفاتيحَ ضائعة لا يعرف أحدٌ أين ذهبت.
         */
        $this->expectException(QueryException::class);
        LicenseKey::create([
            'product_variant_id' => $this->variant()->id,
            'key_value' => 'XXXX', 'key_fingerprint' => str_pad(uniqid(), 64, 'a'),
            'source' => 'PRE_GENERATED', 'status' => 'ASSIGNED',
        ]);
    }

    public function test_a_reserved_key_must_have_an_expiry(): void
    {
        $this->expectException(QueryException::class);
        LicenseKey::create([
            'product_variant_id' => $this->variant()->id,
            'key_value' => 'YYYY', 'key_fingerprint' => str_pad(uniqid(), 64, 'b'),
            'source' => 'PRE_GENERATED', 'status' => 'RESERVED',
        ]);
    }

    public function test_the_same_key_cannot_be_loaded_twice(): void
    {
        $fingerprint = str_pad(uniqid(), 64, 'c');
        $payload = [
            'product_variant_id' => $this->variant()->id,
            'key_value' => 'ZZZZ', 'key_fingerprint' => $fingerprint,
            'source' => 'PRE_GENERATED', 'status' => 'AVAILABLE',
        ];
        LicenseKey::create($payload);

        // البصمة تمنع التكرار بلا كشف المفتاح في فهرسٍ أو رسالة خطأ.
        $this->expectException(QueryException::class);
        LicenseKey::create($payload);
    }

    // ── الاشتراكات (§٢٣) ─────────────────────────────────────────────

    public function test_an_active_subscription_must_have_a_period_end(): void
    {
        /*
         * اشتراكٌ نشطٌ بلا نهاية دورة لا يُجدَّد ولا يُحصَّل: يبقى الوصول
         * مفتوحًا ولا تُطالَب دفعة، وهو تسريبُ إيرادٍ صامت.
         */
        $this->expectException(QueryException::class);
        Subscription::create([
            'number' => 'SUB-'.uniqid(), 'customer_id' => $this->customer()->id,
            'status' => 'ACTIVE', 'interval_count' => 1, 'interval_unit' => 'MONTH',
            'price_minor' => 5000,
        ]);
    }

    public function test_a_trial_must_have_an_end_date(): void
    {
        $this->expectException(QueryException::class);
        Subscription::create([
            'number' => 'SUB-'.uniqid(), 'customer_id' => $this->customer()->id,
            'status' => 'TRIALING', 'interval_count' => 1, 'interval_unit' => 'MONTH',
            'price_minor' => 5000,
        ]);
    }

    public function test_a_period_cannot_end_before_it_starts(): void
    {
        $this->expectException(QueryException::class);
        Subscription::create([
            'number' => 'SUB-'.uniqid(), 'customer_id' => $this->customer()->id,
            'status' => 'ACTIVE', 'interval_count' => 1, 'interval_unit' => 'MONTH',
            'price_minor' => 5000,
            'current_period_start' => now()->addMonth(), 'current_period_end' => now(),
        ]);
    }

    public function test_cancelling_is_recorded_separately_from_ending(): void
    {
        // الإلغاء نيّةٌ تُسجَّل، والانقطاع يقع في موعده — لا يُطويان في حقلٍ واحد.
        $subscription = Subscription::create([
            'number' => 'SUB-'.uniqid(), 'customer_id' => $this->customer()->id,
            'status' => 'ACTIVE', 'interval_count' => 1, 'interval_unit' => 'MONTH',
            'price_minor' => 5000,
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
            'cancel_at' => now()->addMonth(), 'canceled_at' => now(),
        ]);

        // الساعة تُثبَّت: `isToday()` على حافّة منتصف الليل إخفاقٌ لا يتكرّر.
        $this->assertTrue($subscription->canceled_at->isSameDay($subscription->created_at));
        $this->assertTrue($subscription->cancel_at->greaterThan($subscription->canceled_at->copy()->addDays(20)));
        // الوصول باقٍ إلى نهاية المدّة المدفوعة رغم الإلغاء.
        $this->assertSame('ACTIVE', $subscription->status);
    }

    // ── الحجوزات (§٢٥) ───────────────────────────────────────────────

    public function test_one_resource_cannot_be_booked_twice_at_the_same_time(): void
    {
        $resource = $this->resource();
        $this->booking($resource, '2026-10-01 10:00:00+03', '2026-10-01 11:00:00+03');

        /*
         * المنع في المحرّك لا في طبقة الخدمة: «افحص ثمّ أدرج» سباقٌ مفتوح،
         * وحجزان متزامنان يمرّان معًا فيجد المعلّم زبونين في الموعد نفسه.
         */
        $this->expectException(QueryException::class);
        $this->booking($resource, '2026-10-01 10:30:00+03', '2026-10-01 11:30:00+03');
    }

    public function test_back_to_back_bookings_are_allowed(): void
    {
        $resource = $this->resource();
        $this->booking($resource, '2026-10-01 10:00:00+03', '2026-10-01 11:00:00+03');
        $second = $this->booking($resource, '2026-10-01 11:00:00+03', '2026-10-01 12:00:00+03');

        // المدى نصفُ مفتوح، فالنهاية تلامس البداية ولا تتراكب معها.
        $this->assertSame('CONFIRMED', $second->status);
    }

    public function test_a_cancelled_booking_frees_its_slot(): void
    {
        $resource = $this->resource();
        $first = $this->booking($resource, '2026-10-02 10:00:00+03', '2026-10-02 11:00:00+03');
        $first->update(['status' => 'CANCELLED', 'cancellation_reason' => 'اعتذر الزبون']);

        $replacement = $this->booking($resource, '2026-10-02 10:00:00+03', '2026-10-02 11:00:00+03');

        // بلا الاستثناء بقي الموعد الملغى يحجب المورد إلى الأبد.
        $this->assertSame('CONFIRMED', $replacement->status);
    }

    public function test_two_different_resources_may_share_a_timeslot(): void
    {
        $slot = ['2026-10-03 10:00:00+03', '2026-10-03 11:00:00+03'];
        $this->booking($this->resource(), ...$slot);
        $other = $this->booking($this->resource(), ...$slot);

        $this->assertSame('CONFIRMED', $other->status);
    }

    public function test_a_booking_cannot_end_before_it_starts(): void
    {
        $this->expectException(QueryException::class);
        $this->booking($this->resource(), '2026-10-04 11:00:00+03', '2026-10-04 10:00:00+03');
    }

    public function test_a_cancelled_booking_must_say_why(): void
    {
        $booking = $this->booking($this->resource(), '2026-10-05 10:00:00+03', '2026-10-05 11:00:00+03');

        $this->expectException(QueryException::class);
        $booking->update(['status' => 'CANCELLED']);
    }
}
