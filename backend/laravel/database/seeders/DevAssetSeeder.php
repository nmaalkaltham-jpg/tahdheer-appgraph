<?php

namespace Database\Seeders;

use App\Domain\Assets\AssetImporter;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * DEV FIXTURES ONLY — writes REAL bytes to the configured asset storage and
 * links them to the demo products, so the whole download loop (grant → signed
 * URL → stream) can be proven end to end without any external service. Every
 * file is explicitly labelled تجريبي and never runs in production.
 */
class DevAssetSeeder extends Seeder
{
    /** Demo products (from DevCommerceSeeder) → the customer-facing filename. */
    private const FILES = [
        'DEV-COMMERCE-001' => 'ملف تجريبي للتطوير — نموذج تحضير.txt',
        'DEV-COMMERCE-002' => 'ملف تجريبي للتطوير — نموذج أوراق عمل.txt',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        /** @var AssetImporter $importer */
        $importer = app(AssetImporter::class);

        $stagingDir = storage_path('app/private/dev-asset-staging');
        File::ensureDirectoryExists($stagingDir);

        try {
            foreach (self::FILES as $sku => $label) {
                $product = Product::query()->where('sku', $sku)->first();
                if ($product === null) {
                    continue;
                }

                $path = $stagingDir.'/'.$label;
                File::put($path, $this->body($sku, $label));

                $importer->import(
                    product: $product,
                    sourcePath: $path,
                    role: 'main',
                    customerLabel: $label,
                    visibility: 'paid_private',
                );
            }
        } finally {
            File::deleteDirectory($stagingDir);
        }
    }

    /** Real, deterministic bytes — the same content always dedupes to one asset. */
    private function body(string $sku, string $label): string
    {
        return implode("\n", [
            'المتجر: التحاضير الحديثة',
            'ملف تطوير تجريبي — ليس محتوى حقيقيًا للبيع.',
            'المنتج: '.$sku,
            'الاسم المعروض: '.$label,
            'الغرض: إثبات مسار التحميل الآمن من طرفه إلى طرفه.',
            '',
        ]);
    }
}
