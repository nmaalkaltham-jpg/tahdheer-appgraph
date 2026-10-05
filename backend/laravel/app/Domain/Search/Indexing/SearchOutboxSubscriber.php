<?php

namespace App\Domain\Search\Indexing;

use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxRegistry;

/**
 * Wires the search index to the transactional outbox: every index-affecting
 * product event dispatches the queueable SyncProductJob. Consumers are
 * idempotent (re-syncing writes the same document), and the job re-reads the
 * product at execution time, so a burst of events converges on one truth.
 *
 * `product.withdrawn` is the direction that was missing: `Indexer::syncProduct`
 * deletes the document for anything not `published`, and nothing was calling
 * it — so a withdrawn product stayed searchable. The producer for all three
 * lives in ProductIndexSync; do not write these event types by hand.
 *
 * Bootstrap: call SearchOutboxSubscriber::register() once wherever outbox
 * consumers are wired (AppServiceProvider::boot is the natural site; that
 * file is coordinator-owned, so this class only exposes the single line).
 */
final class SearchOutboxSubscriber
{
    public const EVENTS = [
        ProductIndexSync::PUBLISHED,
        ProductIndexSync::UPDATED,
        ProductIndexSync::WITHDRAWN,
    ];

    public static function register(): void
    {
        foreach (self::EVENTS as $eventType) {
            OutboxRegistry::register($eventType, function (OutboxEvent $event): void {
                SyncProductJob::dispatch((int) $event->aggregate_id);
            });
        }
    }
}
