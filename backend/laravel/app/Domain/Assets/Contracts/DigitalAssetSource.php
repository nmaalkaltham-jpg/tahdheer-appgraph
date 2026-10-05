<?php

namespace App\Domain\Assets\Contracts;

use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\Sources\SourceHealth;
use App\Domain\Assets\Sources\SourceMetadata;

/**
 * عقد مصدر الأصل الرقميّ (§١٢) — «Google Drive محوّل مصدر لا نطاق تجاريّ».
 *
 * `StorageProvider` يجيب: أين تعيش البايتات الآن؟
 * هذا العقد يجيب سؤالًا آخر: من أين جاءت، وهل ما تزال هناك، وهل تغيّرت؟
 * الخلط بينهما هو ما يجعل منطق Drive يتسرّب إلى كيان المنتج (§١١).
 *
 * كلّ محوّل يتعامل مع معرّف المزوّد هويّةً دائمة، لا اسم الملفّ (§١٣): الاسم
 * يتغيّر بنقرة فيبدو الملفّ مفقودًا وهو في مكانه.
 *
 * ولا ترجع أيّ عمليّة هنا سرًّا: لا رمز OAuth، ولا اعتماد خدمة، ولا بنية
 * مجلّدات، ولا صلاحيات مشرف (§١٥).
 */
interface DigitalAssetSource
{
    /** المعرّف المستقرّ المحفوظ في `asset_sources.provider`. */
    public function provider(): string;

    /**
     * قراءةٌ خفيفة: هل المعرّف يشير إلى شيء، وما وصفه المُعلَن؟
     * لا تنزيل ولا استيراد — هذه أرخص عمليّة في العقد.
     *
     * @throws SourceUnavailable
     */
    public function inspect(string $externalId): SourceMetadata;

    /**
     * صلاحية المصدر للاستعمال التجاريّ: موجود، ومقروء، ونوعه مقبول،
     * وحجمه ضمن الحدّ. تُستدعى قبل النشر لا بعد الشراء (§١٥).
     *
     * @return list<string> أسبابُ الرفض بالعربية. مصفوفة فارغة = صالح.
     */
    public function validate(string $externalId): array;

    /**
     * جلب البايتات إلى تخزيننا مرّةً واحدة (§١٤ IMPORT_ONCE).
     * تُعيد مفتاح التخزين الداخليّ وتجزئته.
     *
     * @return array{key: string, sha256: string, size_bytes: int}
     *
     * @throws SourceUnavailable
     */
    public function import(string $externalId): array;

    /**
     * إعادة الجلب حين تتغيّر المراجعة (§١٤ MIRROR_SYNC).
     * تُعيد null إن لم يتغيّر شيء — «لا تغيير» ليست فشلًا.
     *
     * @return array{key: string, sha256: string, size_bytes: int}|null
     *
     * @throws SourceUnavailable
     */
    public function sync(string $externalId, ?string $sinceRevision): ?array;

    /** @throws SourceUnavailable */
    public function getMetadata(string $externalId): SourceMetadata;

    /**
     * هل اعتمادُنا ما يزال يقرأ هذا الملفّ تحديدًا؟
     * تُميّز «سُحبت الصلاحية» عن «حُذف الملفّ» — وهما حالتان مختلفتان في §١٦.
     */
    public function verifyAccess(string $externalId): bool;

    /**
     * مراجعة المصدر الحاليّة. null يعني: المزوّد لا يُصدر مراجعات
     * (التخزين الداخليّ مثلًا)، لا أنّها مجهولة.
     *
     * @throws SourceUnavailable
     */
    public function getRevision(string $externalId): ?string;

    /**
     * تجزئة المحتوى حين يوفّرها المزوّد بصدق. null يعني غير متاحة —
     * ولا تُختلق من حجمٍ أو تاريخ (§١٥٨).
     *
     * @throws SourceUnavailable
     */
    public function getChecksum(string $externalId): ?string;

    /**
     * تدفّق قراءةٍ مأذونٌ له، للوسيط الخارجيّ (§١٤ EXTERNAL_ACCESS_BROKER).
     * الإذن يسبق الفتح دائمًا: لا يُفتح تدفّقٌ لمن لا يملك استحقاقًا.
     *
     * @return resource
     *
     * @throws SourceUnavailable
     */
    public function openSecureStream(string $externalId);

    /** صحّة المحوّل نفسه — لا صحّة ملفٍّ بعينه. */
    public function health(): SourceHealth;
}
