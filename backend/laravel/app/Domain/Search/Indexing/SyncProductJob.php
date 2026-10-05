<?php

namespace App\Domain\Search\Indexing;

use App\Domain\Search\ProviderResolver;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queueable incremental index sync, dispatched by the outbox consumers for
 * product.published / product.updated. Idempotent: syncing the same product
 * twice writes the same document. A pg-only deployment is a no-op — the PG
 * fallback reads the authoritative tables directly.
 */
class SyncProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** ثلاث محاولاتٍ متلاصقة كانت تستنفد نفسها أثناء إعادة تشغيل Meilisearch. */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function __construct(public readonly int $productId) {}

    public function failed(\Throwable $e): void
    {
        // الصندوق الصادر عُلِّم «معالَجًا» عند الإرسال؛ هذا السطر هو الأثر الوحيد للمنتج الذي لم يُفهرَس.
        Log::error('search.sync_product_failed', ['product_id' => $this->productId, 'error' => $e->getMessage()]);
    }

    public function handle(ProviderResolver $resolver, Indexer $indexer): void
    {
        if ($resolver->configuredName() !== 'meilisearch') {
            return;
        }

        $product = Product::query()->find($this->productId);
        if ($product !== null) {
            $indexer->syncProduct($product);
        }
    }
}
