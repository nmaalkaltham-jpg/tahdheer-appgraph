<?php

namespace App\Domain\Assets\Sources;

/**
 * وصف المزوّد لملفٍّ عنده (§١٣).
 *
 * كلّ حقلٍ هنا **مُصرَّحٌ به من المزوّد**، لا محسوبٌ عندنا. لذلك يجوز أن
 * يخالف ما نملك، وذلك الخلاف إشارةٌ تُقرأ لا خطأٌ يُصحَّح صامتًا.
 *
 * `revision` و`checksum` يجوز أن يكونا null: «لا يوفّرهما المزوّد» حالةٌ
 * مشروعة تختلف عن «مجهول» وعن «صفر» (§١٥٨).
 */
final readonly class SourceMetadata
{
    public function __construct(
        public string $externalId,
        public ?string $fileName = null,
        public ?string $mimeType = null,
        public ?int $sizeBytes = null,
        public ?string $revision = null,
        public ?string $checksum = null,
        public ?\DateTimeImmutable $modifiedAt = null,
        public ?string $parentId = null,
        public bool $exists = true,
    ) {}

    public static function missing(string $externalId): self
    {
        return new self(externalId: $externalId, exists: false);
    }
}
