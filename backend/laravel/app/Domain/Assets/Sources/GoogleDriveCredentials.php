<?php

namespace App\Domain\Assets\Sources;

use App\Domain\Assets\Exceptions\SourceNotConfigured;

/**
 * مصدر رمز الوصول إلى Drive — مفصولٌ عن المحوّل عمدًا (§١٥).
 *
 * الفصل يبقي الاعتماد في موضعٍ واحد: المحوّل يطلب رمزًا ولا يعرف من أين
 * جاء، فلا يتسرّب إلى رسالة خطأ ولا سطر سجلّ. وتبديلُ رمزٍ ثابت بتدفّق
 * تجديدٍ لاحقًا لا يمسّ المحوّل.
 */
interface GoogleDriveCredentials
{
    /** @throws SourceNotConfigured */
    public function accessToken(): string;

    /** مرجعٌ للحساب يصلح للعرض والتسجيل — ليس سرًّا. */
    public function accountRef(): ?string;

    public function isConfigured(): bool;
}
