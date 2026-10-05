<?php

declare(strict_types=1);

namespace App\Domain\Writing;

/**
 * المزوّد الافتراضي حين لا يُضبط شيء.
 *
 * يرفض صراحةً ولا يخترع نصًّا. هذا هو الفرق العملي بين «الميزة غير مهيَّأة»
 * و«الميزة تعمل وتنتج كلامًا لا مصدر له» — والثانية تصل صفحة منتج حقيقية.
 */
final class NullWritingAssistant implements WritingAssistantProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function suggest(string $field, array $context, int $count = 3): array
    {
        throw new WritingAssistantUnavailable(
            WritingAssistantUnavailable::NOT_CONFIGURED,
            'المساعد الكتابي غير مهيَّأ. اضبط WRITING_ASSISTANT_PROVIDER ومفتاحه.',
        );
    }
}
