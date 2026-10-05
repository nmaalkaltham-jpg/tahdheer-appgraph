<?php

namespace App\Console\Commands;

use App\Domain\Reviews\AggregateRebuilder;
use Illuminate\Console\Command;

/**
 * Manual aggregate rebuild — the repair path when the outbox consumer was
 * unavailable, or after a bulk moderation pass. Recomputes from approved
 * reviews only, so running it twice changes nothing.
 */
class ReviewsRebuildCommand extends Command
{
    protected $signature = 'reviews:rebuild {--product= : Rebuild one product id instead of every product with reviews}';

    protected $description = 'Recompute review_aggregates (avg, count, largest-remainder distribution, recommend %) from approved reviews.';

    public function handle(AggregateRebuilder $rebuilder): int
    {
        $productId = $this->option('product');

        if ($productId !== null) {
            if (! ctype_digit((string) $productId)) {
                $this->error('--product must be a numeric product id.');

                return self::INVALID;
            }

            $aggregate = $rebuilder->rebuild((int) $productId);

            $this->info($aggregate === null
                ? "Product {$productId} has no approved reviews — aggregate removed."
                : "Product {$productId}: {$aggregate->count} approved reviews, average {$aggregate->avg_rating}.");

            return self::SUCCESS;
        }

        $this->info('Rebuilt aggregates for '.$rebuilder->rebuildAll().' product(s) with reviews.');

        return self::SUCCESS;
    }
}
