<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Assets\Contracts\StorageProvider;
use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\StorageResolver;
use Illuminate\Contracts\Foundation\Application;

/**
 * مزوّد تخزينٍ يصدر روابط — كالمزوّد الكائنيّ في الإنتاج، بخلاف المحلّي الذي
 * لا مسار له مُعنوَنًا بالمفتاح بالتصميم.
 *
 * وهو في ملفٍّ مستقلّ لا صنفًا مجهولًا داخل اختبار: الفحص يمرّ على
 * ملفّات الاختبار بأسماء الدوالّ، و`name()`/`put()`/`delete()` تحجب دوالَّ
 * نهائيّة في PHPUnit حين تُعرَّف داخل صنف حالة اختبار. الفصل يُرضي الحارس
 * بحقٍّ لا بتخفيفه.
 */
final class FakeStorageProvider implements StorageProvider
{
    public const HOST = 'https://cdn.test/';

    /** يركّب المزوّد في الحاوية مكان المحلّل الحقيقيّ. */
    public static function install(Application $app): void
    {
        $fake = new self;

        $app->instance(StorageResolver::class, new class($fake) extends StorageResolver
        {
            public function __construct(private readonly StorageProvider $fake) {}

            public function provider(): StorageProvider
            {
                return $this->fake;
            }
        });
    }

    public function name(): string
    {
        return 'fake';
    }

    public function put(string $key, mixed $contents): void {}

    public function temporaryUrl(string $key, int $ttlSeconds, array $context = []): string
    {
        return self::HOST.$key.'?ttl='.$ttlSeconds;
    }

    public function stream(string $key)
    {
        throw new StorageUnavailable('fake provider streams nothing');
    }

    public function exists(string $key): bool
    {
        return true;
    }

    public function delete(string $key): void {}

    public function checksum(string $key): string
    {
        return str_repeat('0', 64);
    }
}
