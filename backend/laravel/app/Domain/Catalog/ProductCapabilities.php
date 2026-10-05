<?php

namespace App\Domain\Catalog;

/**
 * قدرات المنتج — المصدر الواحد لما يعنيه نوعُ المنتج (§٦ §٧ §٨ §٢٨).
 *
 * ─── العيب الذي يزيله ───
 *
 * كانت معرفةُ النوع مبعثرةً في ستّة عشر موضعًا يقرّر كلٌّ منها بنفسه:
 * `in_array($nature, ['PHYSICAL','HYBRID'])` في محرّك الجودة، و
 * `where('fulfillment_type','digital')` في السلّة، و`match` في مُسقِط البحث،
 * وقائمةُ استراتيجيّاتٍ مكتوبةٌ بيدها في كلّ منفّذ، وأخرى في لوحة الإدارة.
 * فإضافةُ نوعٍ واحد كانت تعني جراحةً في ستّة عشر ملفًّا، ونسيانُ واحدٍ منها
 * لا يُسقط شيئًا بل يُنتج سلوكًا صامتًا خاطئًا: منتجٌ ماديّ يمرّ من بوّابة
 * النشر ثمّ يُرفض عند الشحن لأنّه بلا وزن.
 *
 * ─── العقد ───
 *
 * هذا الصنف يجيب أسئلةَ النوع كلّها، ولا يجيبها أحدٌ غيره. من احتاج أن يعرف
 * «هل يحتاج هذا المنتج عنوان شحن» أو «أيّ استراتيجيّات تليق بطبيعته» أو «ما
 * الحقول التي يوجبها نشرُه» يسأل هنا. ومن كتب الجواب في موضعه أعاد العيب.
 *
 * ─── لماذا المفردات مكرَّرةٌ هنا وفي الترحيل ───
 *
 * الترحيل يملك الحقيقة: القيود في قاعدة البيانات هي ما يرفض فعلًا. لكنّ
 * ثوابته `private` داخل صنفٍ مجهول، فلا تُقرأ من التطبيق. فالنسخة هنا
 * **مرآةٌ مُلزَمة** لا نسخةٌ حرّة: القائمتان تُقارَنان
 * حرفًا حرفًا ويُسقط البناء عند أوّل اختلاف. والتكرار المحروس أصدق من قراءةٍ
 * انعكاسيّة هشّة تنكسر بصمتٍ حين يتغيّر شكل الترحيل.
 *
 * ─── وما لا يقرّره هذا الصنف ───
 *
 * لا يقرّر أيّ منفّذٍ ينفّذ استراتيجيّةً — ذاك `ExecutorRegistry`، ويُبنى من
 * المنفّذين المسجَّلين فعلًا لا من قائمةٍ مكتوبة. الخلطُ بينهما كان سيجعل
 * هذا الصنف يزعم تنفيذًا غير موجود.
 */
final class ProductCapabilities
{
    /** §٦ — مرآةُ `NATURES` في ترحيل النموذج الكونيّ. */
    public const NATURES = ['DIGITAL', 'PHYSICAL', 'SERVICE', 'HYBRID', 'ACCESS_BASED', 'COMPOSITE'];

    /** §٧ — مرآةُ `SELLING_MODELS`. مستقلّ عن الطبيعة تمامًا. */
    public const SELLING_MODELS = [
        'ONE_TIME', 'SUBSCRIPTION', 'RECURRING', 'PREORDER', 'BACKORDER', 'RENTAL',
        'RESERVATION', 'BOOKING', 'QUOTE_REQUIRED', 'PAY_WHAT_YOU_WANT', 'FREE',
        'LICENSE_PURCHASE', 'MEMBERSHIP',
    ];

    /** §٢٨ — مرآةُ `INVENTORY_STRATEGIES`. */
    public const INVENTORY_STRATEGIES = [
        'UNLIMITED', 'TRACKED', 'LICENSE_POOL', 'SEAT_CAPACITY', 'TIME_CAPACITY',
        'MADE_TO_ORDER', 'PREORDER', 'BACKORDER', 'EXTERNAL',
    ];

    /** §٨ — مرآةُ `FULFILLMENT_STRATEGIES`. */
    public const FULFILLMENT_STRATEGIES = [
        'DIGITAL_DOWNLOAD', 'CLOUD_FILE_DELIVERY', 'GOOGLE_DRIVE_DELIVERY',
        'OBJECT_STORAGE_DELIVERY', 'EXTERNAL_SECURE_LINK', 'LICENSE_KEY',
        'ACCOUNT_ENTITLEMENT', 'SAAS_PROVISIONING', 'API_PROVISIONING', 'STREAMING_ACCESS',
        'PHYSICAL_SHIPMENT', 'LOCAL_PICKUP', 'STORE_PICKUP', 'COURIER_DELIVERY',
        'FREIGHT', 'DROPSHIP', 'APPOINTMENT', 'BOOKING', 'MANUAL_SERVICE',
    ];

    /**
     * استراتيجيّاتٌ تُسلَّم بلا لمسٍ بشريّ فور ثبوت الدفع.
     *
     * تعيش هنا لا في `OfferFulfillment` لأنّ السؤال سؤالُ قدرةٍ لا سؤالُ صفّ
     * في جدول. والثوابت هناك تُبقى وتُحوَّل إلى هذه (توافقٌ للخلف).
     */
    public const INSTANT = [
        'DIGITAL_DOWNLOAD', 'CLOUD_FILE_DELIVERY', 'GOOGLE_DRIVE_DELIVERY',
        'OBJECT_STORAGE_DELIVERY', 'EXTERNAL_SECURE_LINK', 'LICENSE_KEY',
        'ACCOUNT_ENTITLEMENT', 'STREAMING_ACCESS',
    ];

    /** ما يوجب عنوان شحنٍ ماديّ من الزبون. */
    public const SHIPPED = ['PHYSICAL_SHIPMENT', 'COURIER_DELIVERY', 'FREIGHT', 'DROPSHIP'];

    /** ما يوجب موعدًا أو حجزًا. */
    public const SCHEDULED = ['APPOINTMENT', 'BOOKING'];

    /** ما يوجب حضور الزبون إلى موضعٍ بعينه — لا عنوان ولا موعد. */
    public const PICKUP = ['LOCAL_PICKUP', 'STORE_PICKUP'];

    /**
     * ما يُسلَّم بعملٍ بشريّ مقصود. `MANUAL_SERVICE` ليس نقصًا بل نوعٌ قائم:
     * خدمةٌ يؤدّيها إنسان، فتبقى الوحدة معلّقةً حتّى يُغلقها مشغّل.
     */
    public const MANUAL = ['MANUAL_SERVICE', 'SAAS_PROVISIONING', 'API_PROVISIONING'];

    /**
     * §٦ — ما يليق بكلّ طبيعة.
     *
     * الطبيعةُ تُضيّق ولا تُحدّد: `HYBRID` يقبل الرقميّ والماديّ معًا لأنّ
     * ذلك تعريفه، و`COMPOSITE` يقبل كلّ شيء لأنّ مكوّناته هي التي تحكم.
     * والتضييق يمنع الخطأ الصامت: منتجٌ رقميّ استراتيجيّتُه شحنةٌ ماديّة كان
     * سيُقبل ثمّ يطلب عنوانًا لا سبب له.
     */
    private const NATURE_STRATEGIES = [
        'DIGITAL' => [
            'DIGITAL_DOWNLOAD', 'CLOUD_FILE_DELIVERY', 'GOOGLE_DRIVE_DELIVERY',
            'OBJECT_STORAGE_DELIVERY', 'EXTERNAL_SECURE_LINK', 'LICENSE_KEY', 'STREAMING_ACCESS',
        ],
        'PHYSICAL' => [
            'PHYSICAL_SHIPMENT', 'COURIER_DELIVERY', 'FREIGHT', 'DROPSHIP',
            'LOCAL_PICKUP', 'STORE_PICKUP',
        ],
        'SERVICE' => ['APPOINTMENT', 'BOOKING', 'MANUAL_SERVICE'],
        'ACCESS_BASED' => [
            'ACCOUNT_ENTITLEMENT', 'SAAS_PROVISIONING', 'API_PROVISIONING',
            'STREAMING_ACCESS', 'LICENSE_KEY',
        ],
        // الهجين = رقميّ + ماديّ. وهو الوحيد الذي يوجب أكثر من واحدة (أدناه).
        'HYBRID' => [
            'DIGITAL_DOWNLOAD', 'CLOUD_FILE_DELIVERY', 'GOOGLE_DRIVE_DELIVERY',
            'OBJECT_STORAGE_DELIVERY', 'EXTERNAL_SECURE_LINK', 'LICENSE_KEY', 'STREAMING_ACCESS',
            'PHYSICAL_SHIPMENT', 'COURIER_DELIVERY', 'FREIGHT', 'DROPSHIP',
            'LOCAL_PICKUP', 'STORE_PICKUP',
        ],
        // المركَّب تحكمه مكوّناته، فلا يُضيَّق هنا.
        'COMPOSITE' => self::FULFILLMENT_STRATEGIES,
    ];

    /**
     * §٢٧ §٤٠ — ما توجبه الطبيعة من حقول الصيغة قبل النشر.
     *
     * الوزن ليس تزيينًا: `ShipmentExecutor` يقرأه ويُرجع «معلّق» حين يغيب،
     * أي أنّ منتجًا ماديًّا بلا وزن يُباع ولا يُشحن. فالمنع عند النشر أرخص
     * من اكتشافه بعد أخذ المال.
     */
    private const NATURE_REQUIRED_VARIANT_FIELDS = [
        'PHYSICAL' => ['weight_grams'],
        'HYBRID' => ['weight_grams'],
    ];

    /**
     * بنودُ بوّابة النشر لكلّ طبيعة، زيادةً على البنود المشتركة.
     *
     * المشترك (اسمٌ وسعرٌ وصورة) في `ProductReadiness`. وما هنا ما يخصّ
     * الطبيعة وحدها: الرقميّ يوجب ملفًّا يُنزَّل، والماديّ يوجب وزنًا يُشحن به.
     * كانت البوّابة تطلب ملفًّا من كلّ منتج، فيستحيل نشرُ منتجٍ ماديّ أصلًا.
     */
    private const NATURE_PUBLISH_ITEMS = [
        'DIGITAL' => ['file' => 'ملف واحد على الأقل'],
        'ACCESS_BASED' => ['file' => 'ملف واحد على الأقل'],
        'PHYSICAL' => ['weight' => 'وزن الصيغة بالغرامات — الشحن يرفض ما لا وزن له'],
        'HYBRID' => [
            'file' => 'ملف واحد على الأقل',
            'weight' => 'وزن الصيغة بالغرامات — الشحن يرفض ما لا وزن له',
        ],
        'SERVICE' => [],
        'COMPOSITE' => [],
    ];

    /**
     * §٢٣ — نماذج البيع التي توجب دورة فوترة.
     *
     * مرآةُ القيد `product_offers_recurring_requires_interval`: ما تمنعه
     * قاعدة البيانات يمنعه التحقّق، وإلّا صار الرفض ٥٠٠ بلا اسم حقل بدل
     * ٤٢٢ يفهمه المشغّل.
     */
    public const RECURRING_MODELS = ['SUBSCRIPTION', 'RECURRING', 'MEMBERSHIP'];

    /** §٧ — مرآةُ القيد `product_offers_free_is_zero`. */
    public const ZERO_PRICE_MODELS = ['FREE'];

    /**
     * ترجمةُ العمود القديم `products.fulfillment_type` ← الطبيعة.
     *
     * الطبائع الستّ كلّها ممثَّلة. لو نقصت واحدة لبقي عمودُ المنتجات يقول
     * «رقميّ» عن منتجٍ ليس رقميًّا — وهو يُقرأ في المتجر والبحث والسلّة،
     * فيصير الكذب مرئيًّا للزبون لا مدفونًا في جدول.
     */
    public const LEGACY_NATURE_MAP = [
        'digital' => 'DIGITAL',
        'physical' => 'PHYSICAL',
        'service' => 'SERVICE',
        'hybrid' => 'HYBRID',
        'access' => 'ACCESS_BASED',
        'composite' => 'COMPOSITE',
    ];

    /** تسميةٌ عربيّة لكلّ طبيعة — تُنشَر في العقد فلا تُكتب في الواجهة. */
    public const NATURE_LABELS = [
        'DIGITAL' => 'رقميّ',
        'PHYSICAL' => 'ماديّ',
        'SERVICE' => 'خدمة',
        'HYBRID' => 'هجين — رقميّ وماديّ معًا',
        'ACCESS_BASED' => 'وصولٌ أو عضويّة',
        'COMPOSITE' => 'حزمة مركَّبة',
    ];

    public static function isNature(?string $nature): bool
    {
        return $nature !== null && in_array($nature, self::NATURES, true);
    }

    public static function isStrategy(?string $strategy): bool
    {
        return $strategy !== null && in_array($strategy, self::FULFILLMENT_STRATEGIES, true);
    }

    public static function isSellingModel(?string $model): bool
    {
        return $model !== null && in_array($model, self::SELLING_MODELS, true);
    }

    public static function isInventoryStrategy(?string $strategy): bool
    {
        return $strategy !== null && in_array($strategy, self::INVENTORY_STRATEGIES, true);
    }

    /** @return list<string> */
    public static function strategiesFor(string $nature): array
    {
        return self::NATURE_STRATEGIES[$nature] ?? [];
    }

    public static function allowsStrategy(string $nature, string $strategy): bool
    {
        return in_array($strategy, self::strategiesFor($nature), true);
    }

    /**
     * §١٠ — الهجين يوجب استراتيجيّتين من عالمين.
     *
     * هجينٌ باستراتيجيّةٍ رقميّةٍ وحدها ليس هجينًا بل رقميٌّ سُمّي خطأً،
     * وسيَعِد المتجرُ بشحنةٍ لا تأتي. والفحص هنا لا في المتحكّم لأنّه ثابتٌ
     * من ثوابت النوع لا قاعدةُ نموذج.
     *
     * @param  list<string>  $strategies
     */
    public static function satisfiesNature(string $nature, array $strategies): bool
    {
        if ($strategies === []) {
            return false;
        }

        foreach ($strategies as $strategy) {
            if (! self::allowsStrategy($nature, $strategy)) {
                return false;
            }
        }

        // الهجين وحده يوجب عالمين. وما عداه يكفيه أن تكون كلّ استراتيجيّة لائقة.
        return $nature !== 'HYBRID'
            || (self::hasDigital($strategies) && self::hasPhysical($strategies));
    }

    /** @param list<string> $strategies */
    public static function hasDigital(array $strategies): bool
    {
        return array_intersect($strategies, self::strategiesFor('DIGITAL')) !== [];
    }

    /** @param list<string> $strategies */
    public static function hasPhysical(array $strategies): bool
    {
        return array_intersect($strategies, self::strategiesFor('PHYSICAL')) !== [];
    }

    public static function requiresShipping(string $strategy): bool
    {
        return in_array($strategy, self::SHIPPED, true);
    }

    public static function requiresSchedule(string $strategy): bool
    {
        return in_array($strategy, self::SCHEDULED, true);
    }

    public static function isInstant(string $strategy): bool
    {
        return in_array($strategy, self::INSTANT, true);
    }

    public static function requiresBillingInterval(string $sellingModel): bool
    {
        return in_array($sellingModel, self::RECURRING_MODELS, true);
    }

    public static function requiresZeroPrice(string $sellingModel): bool
    {
        return in_array($sellingModel, self::ZERO_PRICE_MODELS, true);
    }

    /**
     * §٢٧ — هل توجب هذه الطبيعة أبعادًا ماديّة؟
     *
     * يحلّ محلّ `in_array($nature, ['PHYSICAL','HYBRID'])` المكتوبة بيدها في
     * `ProductQualityEngine`. القائمتان كانتا ستفترقان عند أوّل طبيعةٍ جديدة.
     */
    public static function requiresPhysicalAttributes(string $nature): bool
    {
        return self::requiredVariantFields($nature) !== [];
    }

    /** @return list<string> */
    public static function requiredVariantFields(string $nature): array
    {
        return self::NATURE_REQUIRED_VARIANT_FIELDS[$nature] ?? [];
    }

    /**
     * هل يوجب هذا النوع ملفًّا قابلًا للتنزيل قبل النشر؟
     *
     * الجواب `false` للماديّ — وهو التصحيح: البوّابة كانت تطلب ملفًّا من كلّ
     * منتج، فلا يُنشَر منتجٌ ماديّ أبدًا مهما اكتمل.
     */
    public static function requiresDeliverableFile(string $nature): bool
    {
        return array_key_exists('file', self::publishItems($nature));
    }

    /** @return array<string, string> مفتاح البند ← تسميته العربيّة. */
    public static function publishItems(string $nature): array
    {
        return self::NATURE_PUBLISH_ITEMS[$nature] ?? [];
    }

    /**
     * ترجمةُ العمود القديم `products.fulfillment_type` إلى طبيعة.
     *
     * الموضع الوحيد لهذه الترجمة. كانت مكتوبةً في مُسقِط البحث و«مخطِّط
     * المدرسة» و«مورد البطاقة» والسلّة، وكلٌّ منها يفترض `digital` عند
     * الغياب — فيصير المجهول رقميًّا بالصمت. هنا يُعاد `null` عند المجهول،
     * ويقرّر النداءُ ما يفعل بجهله (§١٥٨).
     */
    public static function natureOfLegacy(?string $fulfillmentType): ?string
    {
        return $fulfillmentType === null ? null : (self::LEGACY_NATURE_MAP[$fulfillmentType] ?? null);
    }

    /** الاتّجاه المعاكس — لكتابة العمود القديم من الطبيعة الجديدة. */
    public static function legacyOfNature(string $nature): ?string
    {
        $flipped = array_flip(self::LEGACY_NATURE_MAP);

        return $flipped[$nature] ?? null;
    }

    /**
     * أيّ استراتيجيّةٍ تُقترح حين يختار المشغّل طبيعةً ولم يُفصّل بعد.
     *
     * اقتراحٌ لا حكم: الإنشاء يكتبها ويستطيع المشغّل تغييرها. وغيابُ اقتراحٍ
     * للمركَّب مقصود — الحزمة لا تُسلَّم بنفسها بل بمكوّناتها.
     */
    public static function defaultStrategyFor(string $nature): ?string
    {
        return match ($nature) {
            'DIGITAL' => 'DIGITAL_DOWNLOAD',
            'PHYSICAL' => 'PHYSICAL_SHIPMENT',
            'SERVICE' => 'MANUAL_SERVICE',
            'ACCESS_BASED' => 'ACCOUNT_ENTITLEMENT',
            'HYBRID' => null, // الهجين يوجب اختيارًا صريحًا من عالمين
            default => null,
        };
    }

    /**
     * استراتيجيّة المخزون الافتراضيّة للطبيعة.
     *
     * الماديّ `TRACKED` لا `UNLIMITED`: نسخةٌ ماديّة تُباع مرّةً واحدة، و
     * `UNLIMITED` كانت ستبيع ما لا يوجد. والرقميّ `UNLIMITED` لأنّ النسخ
     * لا تنفد فعلًا.
     */
    public static function defaultInventoryFor(string $nature): string
    {
        return match ($nature) {
            'PHYSICAL', 'HYBRID' => 'TRACKED',
            default => 'UNLIMITED',
        };
    }

    /**
     * هل تُحدّ الكميّة بواحد؟
     *
     * كانت `1` ثابتةً في السلّة لكلّ منتج — وهي صحيحةٌ للرقميّ (نسختان من
     * ملفٍّ واحد لا معنى لهما) وخاطئةٌ للماديّ. فصارت قدرةً لا ثابتًا.
     */
    public static function limitsQuantityToOne(string $nature): bool
    {
        return in_array($nature, ['DIGITAL', 'ACCESS_BASED'], true);
    }
}
