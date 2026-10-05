<?php

namespace App\Domain\Downloads;

use RuntimeException;

/**
 * حرمة المحتوى المدفوع — raised when code attempts to build a public URL for a
 * paid_private asset. Paid originals are reachable through a valid download
 * grant and nothing else; this exception exists so the attempt fails loudly
 * instead of leaking.
 */
class PaidContentViolation extends RuntimeException
{
    public function __construct(public readonly int $assetId)
    {
        parent::__construct("Asset {$assetId} is paid_private and has no public URL.");
    }
}
