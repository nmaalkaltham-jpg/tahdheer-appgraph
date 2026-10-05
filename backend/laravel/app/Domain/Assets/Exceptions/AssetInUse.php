<?php

namespace App\Domain\Assets\Exceptions;

use RuntimeException;

/**
 * Reference counting (MIP §4.3): a referenced asset is never overwritten or
 * deleted in place — archive first, then attach the replacement.
 */
class AssetInUse extends RuntimeException
{
    public function __construct(public readonly int $assetId, public readonly int $usageCount)
    {
        parent::__construct(
            "Asset {$assetId} is referenced by {$usageCount} product file(s); archive it before replacing."
        );
    }
}
