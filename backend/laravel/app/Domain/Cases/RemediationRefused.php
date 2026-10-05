<?php

declare(strict_types=1);

namespace App\Domain\Cases;

use RuntimeException;

/** فعلُ إصلاحٍ لا يصحّ هنا — يُرفض بسببه المكتوب. */
final class RemediationRefused extends RuntimeException
{
    public function __construct(public readonly string $action, string $reason)
    {
        parent::__construct($reason);
    }
}
