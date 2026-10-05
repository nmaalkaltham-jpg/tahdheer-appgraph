<?php

namespace Tests\Feature\Storage;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AssetsImportDryRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_writes_nothing(): void
    {
        $product = Product::create(['sku' => 'S-dry', 'slug' => 'p-dry', 'title' => 'م', 'status' => 'published', 'fulfillment_type' => 'digital']);
        $path = tempnam(sys_get_temp_dir(), 'dry');
        file_put_contents($path, 'pdf-bytes');

        $exit = Artisan::call('assets:import', ['product' => (string) $product->id, 'file' => $path, '--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('تجربة جافّة', Artisan::output());
        $this->assertDatabaseCount('assets', 0);
        $this->assertDatabaseCount('product_files', 0);
    }
}
