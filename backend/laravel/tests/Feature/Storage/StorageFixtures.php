<?php

namespace Tests\Feature\Storage;

use App\Domain\Assets\AssetImporter;
use App\Domain\Assets\Models\Asset;
use App\Domain\Orders\OrderToken;
use App\Models\Customer;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Fixtures for the digital-delivery suite: real files on the (faked) asset
 * disk and a real PAID order ledger — no mocking of the pieces under test.
 */
trait StorageFixtures
{
    protected function makeProduct(string $sku): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'slug' => strtolower($sku),
            'title' => 'تحضير مادة لغتي — '.$sku,
            'status' => 'published',
            'fulfillment_type' => 'digital',
            'format' => 'pptx',
            'published_at' => now()->subDay(),
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'regular_minor' => 40000,
            'currency' => 'SAR',
        ]);

        return $product;
    }

    /** Write real bytes to a staging path and ingest them through the importer. */
    protected function importAsset(
        Product $product,
        string $contents,
        string $filename = 'نموذج تحضير.txt',
        string $role = 'main',
        string $visibility = 'paid_private',
        bool $archiveExisting = false,
    ): Asset {
        $dir = storage_path('app/private/testing-staging');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/'.$filename;
        File::put($path, $contents);

        try {
            return app(AssetImporter::class)->import(
                product: $product,
                sourcePath: $path,
                role: $role,
                customerLabel: $filename,
                visibility: $visibility,
                archiveExisting: $archiveExisting,
            )->asset;
        } finally {
            File::delete($path);
            File::deleteDirectory($dir);
        }
    }

    /**
     * A PAID order with one item and an ACTIVE entitlement.
     *
     * @return array{order: Order, token: string, entitlement: Entitlement, customer: Customer}
     */
    protected function paidEntitlement(Product $product, string $email = 'noura@example.com'): array
    {
        $customer = Customer::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'نورة العتيبي'],
        );

        $number = 'TD-'.Str::upper(Str::random(10));
        $token = OrderToken::make($number, $email);

        $order = Order::query()->create([
            'number' => $number,
            'customer_id' => $customer->id,
            'email' => $email,
            'status' => 'PAID',
            'totals_gross_minor' => 40000,
            'total_minor' => 40000,
            'vat_minor' => 5217,
            'order_key_hash' => OrderToken::hash($token),
            'paid_at' => now(),
        ]);

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'price_snapshot_minor' => 40000,
        ]);

        $entitlement = Entitlement::query()->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'granted_at' => now(),
            'status' => 'ACTIVE',
        ]);

        return ['order' => $order, 'token' => $token, 'entitlement' => $entitlement, 'customer' => $customer];
    }

    /** The delivery URL a real customer would receive. */
    protected function grantUrl(string $orderToken, int $entitlementId): string
    {
        return (string) $this->withHeader('X-Order-Token', $orderToken)
            ->postJson("/api/v1/downloads/{$entitlementId}/grant")
            ->assertOk()
            ->json('url');
    }

    protected function rawGrantToken(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return basename($path);
    }
}
