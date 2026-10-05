<?php

namespace Database\Seeders;

use App\Domain\Cart\Models\Coupon;
use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Database\Seeder;

/**
 * DEV FIXTURES ONLY — demo products and a demo coupon for exercising the
 * commerce flow locally. Every title is explicitly labelled تجريبي so it
 * can never be mistaken for real catalog content. Never runs in production
 * (checked at the DatabaseSeeder call site and here).
 */
class DevCommerceSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $plain = Product::query()->firstOrCreate(
            ['sku' => 'DEV-COMMERCE-001'],
            [
                'slug' => 'dev-commerce-001',
                'title' => '[تجريبي للتطوير] منتج رقمي بسعر ثابت',
                'status' => 'published',
                'fulfillment_type' => 'digital',
                'published_at' => now(),
            ],
        );
        ProductPrice::query()->firstOrCreate(
            ['product_id' => $plain->id],
            ['regular_minor' => 40000, 'currency' => 'SAR'],
        );

        $onSale = Product::query()->firstOrCreate(
            ['sku' => 'DEV-COMMERCE-002'],
            [
                'slug' => 'dev-commerce-002',
                'title' => '[تجريبي للتطوير] منتج رقمي عليه تخفيض',
                'status' => 'published',
                'fulfillment_type' => 'digital',
                'published_at' => now(),
            ],
        );
        ProductPrice::query()->firstOrCreate(
            ['product_id' => $onSale->id],
            [
                'regular_minor' => 30000,
                'sale_minor' => 25000,
                'sale_from' => now()->subDay(),
                'sale_to' => now()->addMonth(),
                'currency' => 'SAR',
            ],
        );

        Coupon::query()->firstOrCreate(
            ['code' => 'DEV15'],
            ['type' => 'percent', 'value' => 15],
        );
    }
}
