<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence;

use App\Domain\ContentIntelligence\Models\AssetExtraction;

/**
 * ماذا فعلت المحاولة — استخرجت أم تخطّت؟
 *
 * التمييز هو برهان قِصَر الدارة بالبصمة: تشغيلٌ ثانٍ على بايتاتٍ لم تتغيّر
 * يجب أن يُعيد `skipped` ولا يقرأ الملفّ أصلًا. بدون هذا التمييز يبدو
 * التشغيلان متماثلين، والفرق كلّه في ما لم يحدث.
 */
final readonly class ExtractionAttempt
{
    public const EXTRACTED = 'extracted';

    public const SKIPPED = 'skipped';

    private function __construct(public string $action, public AssetExtraction $row) {}

    public static function extracted(AssetExtraction $row): self
    {
        return new self(self::EXTRACTED, $row);
    }

    public static function skipped(AssetExtraction $row): self
    {
        return new self(self::SKIPPED, $row);
    }

    public function wasSkipped(): bool
    {
        return $this->action === self::SKIPPED;
    }
}
