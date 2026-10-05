<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence;

use RuntimeException;

/**
 * الأصل يتجاوز الحد الأقصى المسموح لمرحلة ingestion/extraction.
 */
final class AssetTooLarge extends RuntimeException
{
    public function __construct(
        public readonly int $actualBytes,
        public readonly int $maximumBytes,
    ) {
        parent::__construct(
            sprintf(
                'Asset exceeds extraction source limit: %d > %d bytes.',
                $actualBytes,
                $maximumBytes,
            ),
        );
    }
}
