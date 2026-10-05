<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\ProductCapabilities;
use App\Domain\Fulfillment\Executors\ExecutorRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * سجلّ أنواع المنتج — ما تعرفه الإدارة عن النوع يأتي من الخادم.
 *
 * ─── لماذا نقطة نهايةٍ لا جدولٌ في الواجهة ───
 *
 * البديل أن تحمل اللوحة نسختها من المفردات: ستّ طبائع وتسع عشرة استراتيجيّةً
 * وخريطةَ ما يليق بأيّ. وهي النسخة التي تنحرف أوّلًا لأنّها الأبعد عن
 * الترحيل — تُضاف طبيعةٌ في الخادم فتبقى اللوحة تعرض خمسًا، بلا خطأٍ يشكو.
 * وقد كانت اللوحة تحمل بالفعل خريطةَ تسع عشرة تسميةً مكتوبةً بيدها في لوحة
 * طابور التنفيذ، بلا أيّ رابطٍ نوعيٍّ بالخادم.
 *
 * فما تعرضه اللوحة يُقرأ من هنا: الطبائع وتسمياتها، وما يليق بكلٍّ منها،
 * وما توجبه من حقول، وما تقترحه افتراضًا.
 *
 * ─── وصدقٌ إضافيّ: أيّ استراتيجيّةٍ لها منفّذ فعلًا ───
 *
 * `executable` لا تُقرأ من قائمةٍ مكتوبة بل من `ExecutorRegistry` نفسه —
 * أي من المنفّذين المسجَّلين في الحاوية. فالمشغّل يرى قبل الاختيار أنّ
 * `APPOINTMENT` بلا منفّذ اليوم، ولا يكتشفه بعد أن يبيع موعدًا لا يُنفَّذ.
 */
class ProductTypeController extends Controller
{
    public function __construct(private readonly ExecutorRegistry $executors) {}

    public function index(): JsonResponse
    {
        $executable = $this->executors->supported();

        $strategies = array_map(fn (string $strategy): array => [
            'value' => $strategy,
            'label' => self::STRATEGY_LABELS[$strategy] ?? $strategy,
            'requiresShipping' => ProductCapabilities::requiresShipping($strategy),
            'requiresSchedule' => ProductCapabilities::requiresSchedule($strategy),
            'instant' => ProductCapabilities::isInstant($strategy),
            /* لا منفّذ = تبقى وحدةُ التنفيذ معلّقةً بسببٍ مكتوب. يُقال قبل البيع. */
            'executable' => in_array($strategy, $executable, true),
        ], ProductCapabilities::FULFILLMENT_STRATEGIES);

        $natures = array_map(fn (string $nature): array => [
            'value' => $nature,
            'label' => ProductCapabilities::NATURE_LABELS[$nature],
            'strategies' => ProductCapabilities::strategiesFor($nature),
            'defaultStrategy' => ProductCapabilities::defaultStrategyFor($nature),
            'defaultInventory' => ProductCapabilities::defaultInventoryFor($nature),
            'requiredVariantFields' => ProductCapabilities::requiredVariantFields($nature),
            'requiresPhysicalAttributes' => ProductCapabilities::requiresPhysicalAttributes($nature),
            'requiresDeliverableFile' => ProductCapabilities::requiresDeliverableFile($nature),
            'limitsQuantityToOne' => ProductCapabilities::limitsQuantityToOne($nature),
            /* بنودُ النشر الخاصّة بالطبيعة — اللوحة تعرضها قبل أن يصطدم بها المشغّل. */
            'publishItems' => ProductCapabilities::publishItems($nature),
        ], ProductCapabilities::NATURES);

        return response()->json(['data' => [
            'natures' => $natures,
            'strategies' => $strategies,
            'sellingModels' => ProductCapabilities::SELLING_MODELS,
            'inventoryStrategies' => ProductCapabilities::INVENTORY_STRATEGIES,
            'recurringModels' => ProductCapabilities::RECURRING_MODELS,
        ]]);
    }

    /**
     * تسميات الاستراتيجيّات.
     *
     * تُنشر من الخادم لأنّ اللوحة كانت تحملها نسخةً مكتوبةً بيدها، فأيّ
     * استراتيجيّةٍ جديدة كانت ستظهر للمشغّل برمزها الإنجليزيّ الخام.
     */
    private const STRATEGY_LABELS = [
        'DIGITAL_DOWNLOAD' => 'تنزيل رقميّ',
        'CLOUD_FILE_DELIVERY' => 'تسليم ملفّ سحابيّ',
        'GOOGLE_DRIVE_DELIVERY' => 'تسليم من Google Drive',
        'OBJECT_STORAGE_DELIVERY' => 'تسليم من تخزين الكائنات',
        'EXTERNAL_SECURE_LINK' => 'رابط خارجيّ مؤمَّن',
        'LICENSE_KEY' => 'مفتاح ترخيص',
        'ACCOUNT_ENTITLEMENT' => 'استحقاق في الحساب',
        'SAAS_PROVISIONING' => 'تزويد خدمة',
        'API_PROVISIONING' => 'تزويد عبر واجهة برمجيّة',
        'STREAMING_ACCESS' => 'وصول بثّ',
        'PHYSICAL_SHIPMENT' => 'شحنة ماديّة',
        'LOCAL_PICKUP' => 'استلام محلّيّ',
        'STORE_PICKUP' => 'استلام من المتجر',
        'COURIER_DELIVERY' => 'توصيل بمندوب',
        'FREIGHT' => 'شحن ثقيل',
        'DROPSHIP' => 'شحن من المورّد',
        'APPOINTMENT' => 'موعد',
        'BOOKING' => 'حجز',
        'MANUAL_SERVICE' => 'خدمة يدويّة',
    ];
}
