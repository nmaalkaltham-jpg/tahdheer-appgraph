<?php

namespace App\Domain\Reviews;

use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxRegistry;

/**
 * Wires the review aggregate to the transactional outbox (MIP §6): every
 * review.approved event recomputes that product's aggregate. The consumer is
 * idempotent — AggregateRebuilder reads the current approved set, so a retry
 * writes the identical row.
 *
 * Bootstrap: call ReviewOutboxSubscriber::register() once wherever outbox
 * consumers are wired (AppServiceProvider::boot is the natural site; that file
 * is coordinator-owned, so this class only exposes the single line — the same
 * contract SearchOutboxSubscriber follows).
 */
final class ReviewOutboxSubscriber
{
    public const EVENTS = ['review.approved'];

    public static function register(): void
    {
        foreach (self::EVENTS as $eventType) {
            OutboxRegistry::register($eventType, function (OutboxEvent $event): void {
                $productId = $event->payload['productId'] ?? null;
                if ($productId === null) {
                    return;
                }

                app(AggregateRebuilder::class)->rebuild((int) $productId);
            });
        }
    }
}
