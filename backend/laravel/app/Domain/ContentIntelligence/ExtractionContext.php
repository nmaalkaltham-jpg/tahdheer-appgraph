<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence;

/**
 * ميزانية وسياسات الاستخراج المشتركة.
 *
 * تستخدمها مستخرجات PDF وOffice والأرشيفات والصور والصوت
 * والفيديو وغيرها لتطبيق الحدود نفسها بصورة مركزية.
 */
final readonly class ExtractionContext
{
    public function __construct(
        public int $assetId,
        public string $sha256,
        public string $declaredMime,
        public string $effectiveMime,
        public int $sizeBytes,
        public int $maxInMemoryBytes,
        public int $maxArchiveEntries,
        public int $maxUnpackedBytes,
        public int $processTimeoutSeconds,
    ) {}
}
