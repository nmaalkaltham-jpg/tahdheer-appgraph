<?php

declare(strict_types=1);

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\Exceptions\AiUnavailable;

/**
 * عقد محرّك الاستدلال.
 *
 * الواجهة أوّلًا كما في `WritingAssistantProvider` المجاور: ربط البوّابة
 * بـllama.cpp بعينه يجعل تغييره إعادة كتابة، و`isConfigured()` جزءٌ من العقد
 * فحالة «غير مهيّأ» تُبلَّغ ولا تُخفى خلف ردٍّ مُلفَّق.
 */
interface AiEngine
{
    public function name(): string;

    public function isConfigured(): bool;

    /** فحصٌ سريع — يبتلع الأعطال ويردّ `false`، ولا يرمي في مسار القراءة. */
    public function isHealthy(): bool;

    /**
     * إكمالٌ واحد.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, inputTokens: int, outputTokens: int}
     *
     * @throws AiUnavailable
     */
    public function complete(array $messages, float $temperature, int $maxTokens, float $timeout, bool $structured = false): array;
}
