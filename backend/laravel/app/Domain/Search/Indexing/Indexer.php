<?php

namespace App\Domain\Search\Indexing;

use App\Domain\Search\Models\SearchIndexState;
use App\Domain\Search\Providers\MeilisearchProvider;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Versioned index lifecycle (MIP §6.1): a full reindex builds
 * products_v{generation+1} beside the live index, pushes settings and
 * documents, then atomically swaps by updating search_index_state (the
 * application's primary-index pointer — Meilisearch CE has no alias API).
 * Rollback = point the state row back one generation.
 */
class Indexer
{
    private const CHUNK = 200;

    public function __construct(private readonly MeilisearchProvider $provider) {}

    /**
     * @return array{index: string, generation: int, documents: int}
     */
    public function reindex(): array
    {
        $state = SearchIndexState::forEngine('meilisearch');

        /*
         * ادّعاء البناء تحويلٌ ذرّي: صفّ واحد يتحوّل إلى «building» فقط إذا
         * لم يكن فيه بناء حيّ. قفل ShouldBeUnique وحده لا يكفي — مدّته تنتهي
         * بينما محاولة إعادة قد تكون ما زالت تعمل، فيتسابق جيلان على نفس
         * uid ونفس صفّ الحالة. البناء المعلَّق أكثر من مهلة المهمّة (600ث)
         * يُعتبر ميّتًا ويُستولى عليه — عامل قُتل لا يحرّر شيئًا بنفسه.
         */
        $claimed = SearchIndexState::query()
            ->whereKey($state->id)
            ->where(function ($q) {
                $q->where('status', '!=', 'building')
                    ->orWhere('updated_at', '<', now()->subSeconds(660));
            })
            ->update(['status' => 'building', 'updated_at' => now()]);

        if ($claimed === 0) {
            throw new \RuntimeException('بناء فهرس آخر جارٍ بالفعل — لن يُبدأ جيل ثانٍ يسابقه.');
        }

        $state->refresh();
        $generation = (int) $state->generation + 1;
        $uid = 'products_v'.$generation;

        try {
            $this->provider->createIndex($uid);
            $this->provider->pushSettings($uid);

            $documents = 0;
            Product::query()->published()
                ->with(['price', 'seo', 'stage', 'grade', 'subject', 'semester'])
                ->chunkById(self::CHUNK, function ($products) use ($uid, &$documents) {
                    $batch = $products->map(fn (Product $p) => $this->provider->documentFor($p))->all();
                    $this->provider->pushDocuments($uid, $batch);
                    $documents += count($batch);
                });

            // Atomic swap: readers resolve the index name from this row.
            $state->update([
                'index_name' => $uid,
                'generation' => $generation,
                'last_built_at' => now(),
                'status' => 'ready',
            ]);
        } catch (\Throwable $e) {
            $state->update(['status' => 'failed']);
            throw $e;
        }

        // Retire the grandparent generation; keep the parent for rollback.
        if ($generation >= 3) {
            $this->provider->deleteIndex('products_v'.($generation - 2));
        }

        return ['index' => $uid, 'generation' => $generation, 'documents' => $documents];
    }

    /**
     * @return array{index: string, generation: int}
     */
    public function rollback(): array
    {
        $state = SearchIndexState::forEngine('meilisearch');

        if ((int) $state->generation <= 1) {
            throw new \RuntimeException('لا يوجد جيل فهرس سابق للرجوع إليه.');
        }

        $generation = (int) $state->generation - 1;
        $state->update([
            'index_name' => 'products_v'.$generation,
            'generation' => $generation,
            'status' => 'ready',
        ]);

        Log::warning('search.index_rollback', ['to' => 'products_v'.$generation]);

        return ['index' => 'products_v'.$generation, 'generation' => $generation];
    }

    /** Incremental sync: upsert when published, remove otherwise. */
    public function syncProduct(Product $product): void
    {
        $uid = $this->provider->indexName();

        if ($product->status === 'published') {
            $this->provider->pushDocuments($uid, [$this->provider->documentFor($product)]);
        } else {
            $this->provider->deleteDocument($uid, $product->id);
        }
    }
}
