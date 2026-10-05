<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Contracts;

use App\Domain\ContentIntelligence\ExtractionContext;
use App\Domain\ContentIntelligence\ExtractionOutcome;

/**
 * مستخرج يعمل على ملف محلي بدل تحميل الأصل كاملًا في RAM.
 *
 * مناسب لـ:
 * PDF / DOCX / XLSX / PPTX / images / audio / video / archives.
 */
interface FileAwareExtractor
{
    public function extractFile(
        string $path,
        string $mime,
        ExtractionContext $context,
    ): ExtractionOutcome;
}
