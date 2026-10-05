<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Contracts;

use App\Domain\ContentIntelligence\ExtractionContext;
use App\Domain\ContentIntelligence\ExtractionOutcome;

/**
 * مستخرج قادر على معالجة الملف كتدفّق.
 *
 * هذا هو المسار الأفضل للملفات الكبيرة جدًا عندما لا تكون
 * materialization أو القراءة الكاملة في الذاكرة ضرورية.
 */
interface StreamAwareExtractor
{
    /**
     * @param  resource  $stream
     */
    public function extractStream(
        $stream,
        string $mime,
        ExtractionContext $context,
    ): ExtractionOutcome;
}
