<?php

namespace App\Domain\Assets;

use App\Domain\Assets\Models\Asset;

/** What an import actually did — reported verbatim, never embellished. */
final class AssetImportResult
{
    public function __construct(
        public readonly Asset $asset,
        public readonly bool $deduplicated,
        public readonly bool $linkCreated,
        public readonly ?int $archivedAssetId = null,
    ) {}
}
