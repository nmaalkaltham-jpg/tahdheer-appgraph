<?php

declare(strict_types=1);

namespace App\Domain\Ai\Engines;

use App\Domain\Ai\Contracts\AiEngine;
use App\Domain\Ai\Exceptions\AiUnavailable;

/**
 * محرّكٌ يرفض — لا محرّكٌ يصمت.
 *
 * يُربط حين تكون الميزة مطفأةً أو الضبط ناقصًا. الرفض الصريح أفضل من محرّكٍ
 * يردّ نصًّا فارغًا: الفراغ يُكتب في الحقول، والرفض يُعرض للمشرف.
 *
 * ولا سقوطَ إلى مزوّدٍ خارجيّ مدفوع: «لا سقوط صامت إلى واجهات مدفوعة» قاعدةٌ
 * صريحة، وكسرُها يعني فاتورةً لم يأذن بها أحد وبياناتٍ غادرت الخادم.
 */
final class NullAiEngine implements AiEngine
{
    public function __construct(private readonly string $why = 'لم يُهيّأ محرّك.') {}

    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function isHealthy(): bool
    {
        return false;
    }

    public function complete(array $messages, float $temperature, int $maxTokens, float $timeout, bool $structured = false): array
    {
        throw AiUnavailable::notConfigured($this->why);
    }
}
