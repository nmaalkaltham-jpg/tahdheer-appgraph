<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Domain\Outbox\OutboxEvent;
use App\Domain\Outbox\OutboxRegistry;
use App\Domain\UrlPlatform\Jobs\RevalidateStorefrontJob;

/**
 * مستهلك أحداث التنقّل — الحلقة التي كانت مفقودة بين النشر والزائر.
 *
 * `navigation.published` كان يُكتب في الصندوق الصادر ولا يستهلكه أحد:
 * `OutboxRegistry::register` يُنادى لثلاثة نطاقات وليس التنقّل منها. فكان
 * النشر يُبطل كاش Laravel، ثمّ يتحقّق بقراءة **ذلك الكاش نفسه**، ويُعلن
 * النجاح — بينما المتجر يخدم نسخته المخزّنة حتّى تنقضي مهلتها. «نُشر» كانت
 * تعني «كُتب في القاعدة»، لا «يراه الزائر».
 *
 * استدعِ `::register()` حيث يُهيّأ بقيّة المستهلكين (`AppServiceProvider`).
 */
final class NavigationOutboxSubscriber
{
    public const EVENTS = ['navigation.published', 'navigation.rolled_back'];

    public static function register(): void
    {
        foreach (self::EVENTS as $eventType) {
            OutboxRegistry::register($eventType, static function (OutboxEvent $event): void {
                $payload = (array) $event->payload;
                $location = $payload['location'] ?? null;

                if (! is_string($location) || ! array_key_exists($location, MenuTree::LOCATIONS)) {
                    return;
                }

                /*
                 * بالوسوم وحدها، بلا مسارات.
                 *
                 * القائمة في التخطيط الجذر فتمسّ كلّ صفحة؛ تعدادُ المتجر
                 * مستحيلٌ (عدد الصفحات متغيّر) وإبطالُ الجذر لا يكفي. الوسم
                 * يُبطل المورد نفسه أيًّا كانت الصفحة التي طلبته.
                 *
                 * والخمول من `eventId`: إعادة معالجة الحدث تُبطل الوسم نفسه
                 * مرّةً أخرى، وهو فعلٌ لا أثر له سوى تكرار الجلب.
                 */
                RevalidateStorefrontJob::dispatch(
                    [],
                    (string) $event->id,
                    NavigationRevalidator::tagsFor($location),
                );
            });
        }
    }
}
