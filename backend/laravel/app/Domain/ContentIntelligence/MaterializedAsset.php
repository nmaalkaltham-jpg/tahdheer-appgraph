<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence;

/**
 * نسخة محلية مؤقتة وآمنة من الأصل.
 *
 * الملف يُحذف صراحةً عند انتهاء دورة الاستخراج.
 */
final readonly class MaterializedAsset
{
    public function __construct(
        public string $path,
        public int $size,
        public string $sha256,
        public string $detectedMime,
    ) {}

    public function delete(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
