<?php

namespace App\Domain\Outbox;

/**
 * Simple consumer registry: event_type → list of callables.
 * Consumers must be idempotent — they may be retried after partial failure.
 */
final class OutboxRegistry
{
    /** @var array<string, array<int, callable(OutboxEvent): void>> */
    private static array $consumers = [];

    /** @param callable(OutboxEvent): void $consumer */
    public static function register(string $eventType, callable $consumer): void
    {
        self::$consumers[$eventType][] = $consumer;
    }

    /** @return array<int, callable(OutboxEvent): void> */
    public static function consumersFor(string $eventType): array
    {
        return self::$consumers[$eventType] ?? [];
    }

    public static function flush(): void
    {
        self::$consumers = [];
    }
}
