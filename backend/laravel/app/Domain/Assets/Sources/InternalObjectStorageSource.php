<?php

namespace App\Domain\Assets\Sources;

use App\Domain\Assets\Contracts\DigitalAssetSource;
use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\StorageResolver;

/**
 * تخزيننا مصدرًا لنفسه.
 *
 * المعرّف الخارجيّ هنا هو مفتاح الكائن. ولا مراجعات: التخزين الداخليّ لا
 * يُصدرها، فـ`getRevision` يُعيد null — «لا يوفّرها المزوّد» لا «مجهولة».
 * وهذا ما يجعل `CURRENT` صادقةً لهذه الأصول: null تساوي null.
 *
 * الاستيراد لا عمل له: البايتات عندنا سلفًا، فيُعيد ما هو قائم.
 */
class InternalObjectStorageSource implements DigitalAssetSource
{
    public function __construct(private readonly StorageResolver $storage) {}

    public function provider(): string
    {
        return 'INTERNAL_OBJECT_STORAGE';
    }

    public function inspect(string $externalId): SourceMetadata
    {
        if (! $this->present($externalId)) {
            return SourceMetadata::missing($externalId);
        }

        return new SourceMetadata(externalId: $externalId, revision: null, checksum: null);
    }

    public function validate(string $externalId): array
    {
        return $this->present($externalId)
            ? []
            : ['الكائن غير موجود في التخزين الداخليّ.'];
    }

    public function import(string $externalId): array
    {
        $provider = $this->storage->provider();

        if (! $this->present($externalId)) {
            throw new SourceUnavailable($this->provider(), 'الكائن «غير موجود» عند الاستيراد.');
        }

        return [
            'key' => $externalId,
            'sha256' => $provider->checksum($externalId),
            'size_bytes' => 0, // الحجم يُقرأ من `assets`، لا يُعاد حسابه هنا
        ];
    }

    public function sync(string $externalId, ?string $sinceRevision): ?array
    {
        return null; // لا مصدر خارجيّ يتغيّر
    }

    public function getMetadata(string $externalId): SourceMetadata
    {
        return $this->inspect($externalId);
    }

    public function verifyAccess(string $externalId): bool
    {
        return $this->present($externalId);
    }

    /** «تعذّر السؤال» ليست «غير موجود»: عطل التخزين يُرفع مصدرًا غير متاح لا غيابًا. */
    private function present(string $externalId): bool
    {
        try {
            return $this->storage->provider()->exists($externalId);
        } catch (StorageUnavailable $e) {
            throw new SourceUnavailable($this->provider(), $e->reason);
        }
    }

    public function getRevision(string $externalId): ?string
    {
        return null;
    }

    public function getChecksum(string $externalId): ?string
    {
        return $this->storage->provider()->checksum($externalId);
    }

    public function openSecureStream(string $externalId)
    {
        return $this->storage->provider()->stream($externalId);
    }

    public function health(): SourceHealth
    {
        return new SourceHealth(
            provider: $this->provider(),
            configured: true,
            reachable: true,
            detail: 'التخزين الداخليّ عبر StorageResolver.',
        );
    }
}
