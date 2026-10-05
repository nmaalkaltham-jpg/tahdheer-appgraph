<?php

declare(strict_types=1);

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

/**
 * خرجٌ لم يجتز التحقّق.
 *
 * يُرمى ولا يُصلَح: نموذجٌ ردّ JSON مبتورًا قد يكون ردّ حقولًا صحيحةً وأخرى
 * مخترعة، وترقيعُ المبتور يكتب المخترع. الفشل هنا أرخص من صفٍّ خاطئ في
 * قاعدة البيانات.
 */
final class AiOutputInvalid extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(
        string $message,
        public readonly array $problems = [],
        public readonly ?string $raw = null,
    ) {
        parent::__construct($message);
    }
}
