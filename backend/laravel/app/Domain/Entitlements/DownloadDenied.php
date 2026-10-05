<?php

namespace App\Domain\Entitlements;

use Exception;

class DownloadDenied extends Exception
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Download denied: {$reason}");
    }
}
