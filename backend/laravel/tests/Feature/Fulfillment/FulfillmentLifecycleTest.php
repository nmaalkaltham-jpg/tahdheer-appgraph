<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use App\Domain\Catalog\Models\OfferFulfillment;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductOffer;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Entitlements\RevocationPolicy;
use App\Domain\Fulfillment\FulfillmentOrchestrator;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Licensing\Models\LicenseKey;
use App\Domain\Orders\RefundService;
use App\Domain\Outbox\OutboxEvent;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حلقة التنفيذ من طرفيها: تُغلَق حين يُسلَّم كلّ شيء، وتُسحب حين يُردّ المال.
 *
 * كان الطرفان مفتوحين: `isFullyFulfilled` بلا مستدعٍ فلا طلبٍ يبلغ
 * `COMPLETED`، والسحب يعرف الاستحقاق وحده فيبقى مفتاح الرخصة مُسنَدًا بعد
 * ردّ المال.
 */
class FulfillmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: OrderItem, 2: ProductVariant} */
    private function paidOrder(array $strategies, string $nature = 'DIGITAL'): array
    {
        $n = uniqid();

        $product = Product::create([
            'sku' => "SKU-{$n}", 'slug' => "p-{$n}", 'title' => 'منتج',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        $group = ProductGroup::create([
            'slug' => "g-{$n}", 'title' => 'مجموعة', 'product_nature' => $nature,
            'status' => 'published', 'legacy_product_id' => $product->id,
        ]);
        $variant = ProductVariant::create([
            'product_group_id' => $group->id, 'sku' => "V-{$n}", 'slug' => "v-{$n}",
            'status' => 'published', 'legacy_product_id' => $product->id, 'weight_grams' => 500,
        ]);
        $offer = ProductOffer::create([
            'product_variant_id' => $variant->id, 'regular_minor' => 5000, 'status' => 'active',
        ]);
        foreach ($strategies as $i => $strategy) {
            OfferFulfillment::create([
                'product_offer_id' => $offer->id, 'strategy' => $strategy, 'position' => $i,
            ]);
        }

        $customer = Customer::create(['email' => "{$n}@test.local", 'name' => 'زبون']);
        $order = Order::create([
            'number' => "TD-{$n}", 'customer_id' => $customer->id, 'email' => $customer->email,
            'status' => 'PAID', 'total_minor' => 5000, 'paid_at' => now(),
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_variant_id' => $variant->id, 'product_offer_id' => $offer->id,
            'title_snapshot' => 'منتج', 'price_snapshot_minor' => 5000, 'quantity' => 1,
        ]);

        return [$order->fresh('items'), $item, $variant];
    }

    public function test_an_order_with_a_waiting_unit_stays_open(): void
    {
        /* الشحنة بلا عنوان تبقى معلّقة — والطلب معها. */
        [$order] = $this->paidOrder(['PHYSICAL_SHIPMENT'], 'PHYSICAL');
        $orchestrator = app(FulfillmentOrchestrator::class);

        $orchestrator->fulfill($order);

        $this->assertFalse($orchestrator->closeIfDelivered($order->fresh()));
        $this->assertSame('PAID', $order->fresh()->status,
            'طلبٌ لم يصل يجب أن يبقى عملًا مفتوحًا في اللوحة');
    }

    public function test_closing_is_idempotent_and_writes_one_event(): void
    {
        [$order] = $this->paidOrder(['DIGITAL_DOWNLOAD']);
        $orchestrator = app(FulfillmentOrchestrator::class);
        $orchestrator->fulfill($order);

        $this->assertTrue($orchestrator->closeIfDelivered($order->fresh()));
        $this->assertFalse($orchestrator->closeIfDelivered($order->fresh()),
            'الثانية لا تُعيد الإغلاق — الحالة لم تعد PAID');
        $this->assertSame(1, OutboxEvent::query()->where('event_type', 'order.completed')->count());
    }

    public function test_a_refund_revokes_the_licence_key_not_only_the_entitlement(): void
    {
        [$order, $item, $variant] = $this->paidOrder(['LICENSE_KEY']);

        LicenseKey::create([
            'product_variant_id' => $variant->id,
            'key_value' => 'KEY-'.uniqid(),
            'key_fingerprint' => hash('sha256', uniqid()),
            'source' => 'MANUAL',
            'status' => 'AVAILABLE',
        ]);

        app(FulfillmentOrchestrator::class)->fulfill($order);

        $key = LicenseKey::query()->where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame('ASSIGNED', $key->status);

        app(RefundService::class)->refund($order->fresh(), 5000, 'ملف تالف', RevocationPolicy::Immediate);

        $key->refresh();
        $this->assertSame('REVOKED', $key->status,
            'مفتاحٌ يبقى مُسنَدًا بعد ردّ المال هو رخصةٌ مجّانيّة');
        $this->assertNotNull($key->revocation_reason);

        $this->assertSame('REVOKED', FulfillmentUnit::query()
            ->where('order_id', $order->id)->value('status'));
    }

    public function test_a_revoked_licence_key_is_not_returned_to_the_pool(): void
    {
        [$order, $item, $variant] = $this->paidOrder(['LICENSE_KEY']);

        LicenseKey::create([
            'product_variant_id' => $variant->id,
            'key_value' => 'KEY-'.uniqid(),
            'key_fingerprint' => hash('sha256', uniqid()),
            'source' => 'MANUAL',
            'status' => 'AVAILABLE',
        ]);

        app(FulfillmentOrchestrator::class)->fulfill($order);
        app(RefundService::class)->refund($order->fresh(), 5000, 'ردّ', RevocationPolicy::Immediate);

        $this->assertSame(0, LicenseKey::query()
            ->where('product_variant_id', $variant->id)->where('status', 'AVAILABLE')->count(),
            'مفتاحٌ رآه زبونٌ محروق — إعادتُه للمخزون تبيعه لثانٍ فيجده مستعملًا');
    }

    public function test_revocation_writes_one_audited_event(): void
    {
        [$order] = $this->paidOrder(['DIGITAL_DOWNLOAD']);
        app(FulfillmentOrchestrator::class)->fulfill($order);

        $log = app(FulfillmentOrchestrator::class)->revokeAll($order->fresh('items'), 'اختبار');

        // ما في السجلّ يُقرأ: «ليس فارغًا» يمرّ على أيّ نصٍّ كان. والسطر
        // يجب أن يسمّي الاستحقاق الذي سُحب فعلًا.
        $this->assertCount(1, $log);
        $entitlementId = (int) Entitlement::query()->where('order_id', $order->id)->value('id');
        $this->assertStringContainsString('الاستحقاق '.$entitlementId, $log[0]);
        $this->assertSame(1, OutboxEvent::query()
            ->where('event_type', 'order.fulfillment.revoked')->count());
    }

    public function test_a_partial_refund_does_not_revoke_the_whole_order(): void
    {
        [$order, $item, $variant] = $this->paidOrder(['LICENSE_KEY']);

        LicenseKey::create([
            'product_variant_id' => $variant->id,
            'key_value' => 'KEY-'.uniqid(),
            'key_fingerprint' => hash('sha256', uniqid()),
            'source' => 'MANUAL', 'status' => 'AVAILABLE',
        ]);

        app(FulfillmentOrchestrator::class)->fulfill($order);

        /* هللةٌ واحدة من خمسة آلاف — تعويضًا عن تأخيرٍ مثلًا. */
        app(RefundService::class)->refund($order->fresh(), 1, 'تعويض تأخير', RevocationPolicy::Immediate);

        $this->assertSame('ASSIGNED', LicenseKey::query()->where('order_item_id', $item->id)->value('status'),
            'ردُّ هللةٍ لا يُبطل رخصةً دفع الزبون ثمنها كاملًا تقريبًا');
        $this->assertSame('FULFILLED', FulfillmentUnit::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_a_keep_policy_refund_does_not_burn_the_key(): void
    {
        [$order, $item, $variant] = $this->paidOrder(['LICENSE_KEY']);

        LicenseKey::create([
            'product_variant_id' => $variant->id,
            'key_value' => 'KEY-'.uniqid(),
            'key_fingerprint' => hash('sha256', uniqid()),
            'source' => 'MANUAL', 'status' => 'AVAILABLE',
        ]);

        app(FulfillmentOrchestrator::class)->fulfill($order);
        app(RefundService::class)->refund($order->fresh(), 5000, 'ردّ كامل', RevocationPolicy::Keep);

        /*
         * `Keep` تُبقي الاستحقاق صراحةً. وحرقُ المفتاح معها كان يناقضها في
         * المعاملة نفسها: استحقاقٌ حيّ ومفتاحٌ محروق.
         */
        $this->assertSame('ASSIGNED', LicenseKey::query()->where('order_item_id', $item->id)->value('status'));
    }

    public function test_an_open_unit_is_cancelled_on_revocation_so_no_retry_revives_it(): void
    {
        /* شحنةٌ بلا عنوان: الوحدة تبقى معلّقةً — ولا شيء مُنح بعد. */
        [$order] = $this->paidOrder(['PHYSICAL_SHIPMENT'], 'PHYSICAL');
        app(FulfillmentOrchestrator::class)->fulfill($order);

        $this->assertSame('PENDING', FulfillmentUnit::query()->where('order_id', $order->id)->value('status'));

        app(FulfillmentOrchestrator::class)->revokeAll($order->fresh('items'), 'ردّ');

        $this->assertSame('CANCELLED', FulfillmentUnit::query()->where('order_id', $order->id)->value('status'),
            'وحدةٌ مفتوحة على طلبٍ رُدّ ماله يلتقطها جدولُ إعادة المحاولة فتُنشئ شحنةً بعد خروج المال');
    }
}
