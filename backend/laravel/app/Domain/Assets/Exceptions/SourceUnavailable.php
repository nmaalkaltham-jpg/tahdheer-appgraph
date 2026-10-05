<?php

namespace App\Domain\Assets\Exceptions;

use RuntimeException;

/** المصدر الخارجيّ تعذّر بلوغه. الرسالة تُعرض كما هي — لا بديل صامت. */
class SourceUnavailable extends RuntimeException
{
    public function __construct(public readonly string $provider, string $reason)
    {
        parent::__construct("مصدر «{$provider}» غير متاح: {$reason}");
    }
}
