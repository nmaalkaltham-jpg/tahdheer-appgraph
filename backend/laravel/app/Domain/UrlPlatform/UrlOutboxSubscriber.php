<?php

namespace App\Domain\UrlPlatform;

use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxRegistry;
use App\Domain\UrlPlatform\Jobs\RevalidateStorefrontJob;

/**
 * مستهلك أحداث العناوين — يُبطل مخزن المتجر للمسار القديم والجديد معًا.
 *
 * استدعِ `::register()` مرّة حيث تُهيّأ بقيّة المستهلكين (AppServiceProvider).
 */
final class UrlOutboxSubscriber
{
    public const EVENTS = ['url.slug_changed', 'url.published', 'url.archived', 'url.deleted', 'url.redirect_created', 'url.locked', 'url.unlocked'];

    public static function register(): void
    {
        foreach (self::EVENTS as $eventType) {
            OutboxRegistry::register($eventType, function (OutboxEvent $event): void {
                $payload = (array) $event->payload;
                $paths = array_values(array_unique(array_filter([
                    $payload['path'] ?? null,
                    $payload['previousPath'] ?? null,
                    $payload['newPath'] ?? null,
                    $payload['from'] ?? null,
                    $payload['to'] ?? null,
                ], fn ($p) => is_string($p) && $p !== '')));
                if ($paths !== []) {
                    RevalidateStorefrontJob::dispatch($paths, (string) $event->id);
                }
                /*
                 * الروابط الداخليّة تُصلَح قبل الإبطال لا بعده — والإصلاح خامل:
                 * إعادةُ معالجة الحدث لا تجد المسار القديم فلا تكتب شيئًا.
                 */
                if ($event->event_type === 'url.slug_changed' && is_string($payload['previousPath'] ?? null) && is_string($payload['newPath'] ?? null)) {
                    app(InternalLinkRepair::class)->repair($payload['previousPath'], $payload['newPath']);
                }
            });
        }
    }
}
