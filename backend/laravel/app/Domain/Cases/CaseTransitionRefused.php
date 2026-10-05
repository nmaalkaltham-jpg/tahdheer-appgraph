<?php

declare(strict_types=1);

namespace App\Domain\Cases;

use RuntimeException;

/** انتقالٌ لا تسمح به آلة الحالات — يُرفض ومعه ما كان مسموحًا. */
final class CaseTransitionRefused extends RuntimeException
{
    /** @param list<string> $allowed */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array $allowed,
    ) {
        parent::__construct(
            'لا انتقال من «'.$from.'» إلى «'.$to.'». المسموح: '
            .($allowed === [] ? 'لا شيء — الحالة نهائيّة' : implode('، ', $allowed))
        );
    }
}
