<?php

namespace App\Domain\Assets\Sources;

use App\Domain\Assets\Exceptions\SourceNotConfigured;

/**
 * رمزٌ من الإعداد.
 *
 * تجديد الرمز **غير منفَّذ**: هذا التنفيذ يقرأ رمزًا مضبوطًا ولا يعرف كيف
 * يحصل على غيره. حين ينتهي صلاحيته يفشل المحوّل مغلقًا برسالةٍ صريحة، ولا
 * يتظاهر بأنّ الملفّ اختفى. تنفيذُ تدفّق OAuth يحلّ محلّ هذا الصنف وحده.
 */
final class ConfiguredGoogleDriveCredentials implements GoogleDriveCredentials
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.google_drive.access_token')) !== '';
    }

    public function accessToken(): string
    {
        $token = trim((string) config('services.google_drive.access_token'));

        if ($token === '') {
            // لا يُذكر اسم متغيّر يحمل قيمةً — يُذكر مفتاح الإعداد وحده.
            throw new SourceNotConfigured('GOOGLE_DRIVE', 'لا رمز وصول مضبوط في `services.google_drive.access_token`');
        }

        return $token;
    }

    public function accountRef(): ?string
    {
        $ref = trim((string) config('services.google_drive.account_ref'));

        return $ref === '' ? null : $ref;
    }
}
