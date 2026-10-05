<?php

namespace App\Console\Commands;

use App\Domain\Search\Indexing\Indexer;
use Illuminate\Console\Command;

class SearchReindexCommand extends Command
{
    protected $signature = 'search:reindex {--rollback : Point back to the previous index generation}';

    protected $description = 'Build products_v{generation+1} in Meilisearch, push settings and documents, then atomically swap (or --rollback one generation).';

    public function handle(Indexer $indexer): int
    {
        try {
            if ($this->option('rollback')) {
                $out = $indexer->rollback();
                $this->info("Rolled back to {$out['index']} (generation {$out['generation']}).");
            } else {
                $out = $indexer->reindex();
                $this->info("Built {$out['index']} with {$out['documents']} documents and swapped.");
            }
        } catch (\Throwable $e) {
            $this->error('Reindex failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
