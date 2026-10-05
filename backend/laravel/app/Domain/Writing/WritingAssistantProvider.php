<?php

declare(strict_types=1);

namespace App\Domain\Writing;

/**
 * واجهة مزوّد المساعدة الكتابية.
 *
 * الواجهة أوّلًا لا المزوّد: ربط الشاشة بمزوّد بعينه يجعل تغييره إعادة كتابة.
 * والأهمّ أن `isConfigured()` جزء من العقد — فحالة «غير مهيَّأ» تُبلَّغ ولا
 * تُخفى خلف ردّ مُلفَّق.
 */
interface WritingAssistantProvider
{
    public function name(): string;

    /** هل يملك ما يكفي للعمل (مفتاح، نموذج، نقطة نهاية)؟ */
    public function isConfigured(): bool;

    /**
     * يقترح نصوصًا. لا يكتب في قاعدة البيانات ولا يقرّر — الاقتراح يُراجَع
     * ويُطبَّق بفعل صريح من المشرف.
     *
     * @param  array<string, mixed>  $context
     * @return list<string>
     *
     * @throws WritingAssistantUnavailable
     */
    public function suggest(string $field, array $context, int $count = 3): array;
}
