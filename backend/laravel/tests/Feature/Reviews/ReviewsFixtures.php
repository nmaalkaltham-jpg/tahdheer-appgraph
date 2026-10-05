<?php

namespace Tests\Feature\Reviews;

use App\Domain\Orders\OrderToken;
use App\Domain\Reviews\Models\Review;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;

/**
 * World-building for the reviews suite. Everything here creates REAL rows in
 * the real tables — no mocks, so a test that says «verified purchase» is
 * proving it against the same schema production reads.
 */
trait ReviewsFixtures
{
    protected function makeProduct(string $sku = 'TD-R1'): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'slug' => strtolower($sku),
            'title' => 'تحضير مادة لغتي — '.$sku,
            'status' => 'published',
            'fulfillment_type' => 'digital',
            'format' => 'pptx',
            'published_at' => now()->subDay(),
        ]);
    }

    protected function makeCustomer(string $email = 'noura@example.com', string $name = 'نورة العتيبي'): Customer
    {
        return Customer::query()->create(['email' => $email, 'name' => $name]);
    }

    /**
     * A paid order for one product, plus the raw guest token its holder would
     * have received once at checkout.
     *
     * @return array{order: Order, item: OrderItem, token: string}
     */
    protected function makePaidOrder(
        Customer $customer,
        Product $product,
        string $status = 'PAID',
        ?string $versionLabel = null,
    ): array {
        $number = 'TD-'.str_pad((string) (Order::query()->count() + 1), 5, '0', STR_PAD_LEFT);
        $token = OrderToken::make($number, $customer->email);

        $order = Order::query()->create([
            'number' => $number,
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => $status,
            'total_minor' => 2500,
            // الحالة والمبلغ المستردّ يتّفقان — قيدُ المحرّك يرفض «مستردّ» بلا مبلغ.
            'refunded_total_minor' => $status === 'REFUNDED' ? 2500 : 0,
            'totals_gross_minor' => 2500,
            'order_key_hash' => OrderToken::hash($token),
            'paid_at' => $status === 'PAID' ? now() : null,
        ]);

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'price_snapshot_minor' => 2500,
            'version_label' => $versionLabel,
        ]);

        return ['order' => $order, 'item' => $item, 'token' => $token];
    }

    /** An account with a token but NO purchase of $product. */
    protected function makeStranger(Product $other, string $email = 'faisal@example.com'): array
    {
        $customer = $this->makeCustomer($email, 'فيصل الدوسري');

        return ['customer' => $customer] + $this->makePaidOrder($customer, $other);
    }

    protected function grantEntitlement(Customer $customer, Product $product, array $order, string $status = 'ACTIVE'): Entitlement
    {
        return Entitlement::query()->create([
            'customer_id' => $customer->id,
            'order_id' => $order['order']->id,
            'order_item_id' => $order['item']->id,
            'product_id' => $product->id,
            'granted_at' => now(),
            'status' => $status,
        ]);
    }

    /**
     * A known approved distribution, one distinct account per review (the
     * unique constraint demands it). Bulk-inserted so a 212-review golden set
     * stays a fast test rather than 600 round trips.
     *
     * @param  array<int, int>  $byStar  star => how many reviews
     */
    protected function seedApproved(Product $product, array $byStar, string $status = Review::APPROVED): void
    {
        $now = now();
        $customers = [];
        $seed = Customer::query()->count();
        $total = array_sum($byStar);

        for ($i = 1; $i <= $total; $i++) {
            $customers[] = [
                'email' => "reviewer{$seed}n{$i}@example.com",
                'name' => "معلمة رقم {$i}",
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Customer::query()->insert($customers);

        $ids = Customer::query()
            ->whereIn('email', array_column($customers, 'email'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $rows = [];
        $n = 0;
        foreach ($byStar as $star => $howMany) {
            for ($i = 0; $i < $howMany; $i++) {
                $rows[] = [
                    'product_id' => $product->id,
                    'customer_id' => $ids[$n],
                    'rating' => $star,
                    'body' => 'تقييم موثّق رقم '.($n + 1),
                    'status' => $status,
                    'created_at' => $now->copy()->subMinutes($n),
                    'updated_at' => $now,
                ];
                $n++;
            }
        }
        Review::query()->insert($rows);
    }
}
