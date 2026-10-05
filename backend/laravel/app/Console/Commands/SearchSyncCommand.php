<?php

namespace App\Console\Commands;

use App\Domain\Search\Indexing\Indexer;
use App\Models\Product;
use Illuminate\Console\Command;

class SearchSyncCommand extends Command
{
    protected $signature = 'search:sync {product : Product id to sync into the live search index}';

    protected $description = 'Incrementally sync one product into the live Meilisearch index (upsert when published, delete otherwise).';

    public function handle(Indexer $indexer): int
    {
        $product = Product::query()->find((int) $this->argument('product'));

        if ($product === null) {
            $this->error('Product not found.');

            return self::FAILURE;
        }

        try {
            $indexer->syncProduct($product);
        } catch (\Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Synced product {$product->id} ({$product->sku}).");

        return self::SUCCESS;
    }
}
