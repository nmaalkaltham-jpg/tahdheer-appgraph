<?php

namespace App\Domain\Fulfillment\Executors;

use App\Domain\Assets\AssetSourceResolver;
use App\Domain\Assets\Exceptions\SourceNotConfigured;
use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\Models\AssetSource;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\Assets\SyncGovernor;
use App\Domain\Entitlements\EntitlementService;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Log;

/**
 * تسليم Google Drive — استحقاقٌ بعد إعادة تحقّقٍ حيّة (§١٣ §١٥ §١٦).
 *
 * ─── لماذا منفّذٌ مستقلّ ───
 *
 * كان `GOOGLE_DRIVE_DELIVERY` واحدًا من سبع سلاسل في `DigitalDeliveryExecutor`
 * تسلك المسار نفسه بالضبط. وفحصُ ذلك المنفّذ يقرأ `sync_state` **المخزَّنة**،
 * أي نتيجة آخر مزامنةٍ وقعت — وقد تكون قبل يوم. والفرق بين المصدرين أنّ ملفّ
 * Drive **يملكه غيرنا**: يُحذف بنقرة، ويُسحب إذنه بنقرة، بلا أن يصلنا خبر.
 * فمنتجٌ بِيع صباحًا قد يكون مصدرُه قد ذهب ظهرًا، والحالة المخزَّنة تقول
 * `CURRENT` بثقةٍ كاملة.
 *
 * ولذلك يُعيد هذا المنفّذ التحقّق **لحظة التسليم**، وهو ما تطلبه المواصفة
 * صراحةً: إعادة التحقّق من الأذونات قبل التنفيذ.
 *
 * ─── ولماذا لا يمنع تعذّرُ البلوغ التسليمَ ───
 *
 * التمييز الذي يصنعه `SyncGovernor` يُستعمل هنا لا يُطوى:
 *
 *   الملفّ ذهب / الإذن سُحب → لا يُمنح استحقاق. الوحدة تفشل بسببٍ مكتوب،
 *                             لأنّ المنح كان سيقود إلى تنزيلٍ فاشل وشكوى.
 *   شبكةٌ أو إعدادٌ غائب     → **يُمنح** الاستحقاق ما دامت لنا نسخةٌ مستورَدة.
 *                             نمط `IMPORT_ONCE` يعني أنّ البايتات عندنا، فمنعُ
 *                             التسليم لانقطاعٍ عند Drive يعاقب الزبون على عطلٍ
 *                             لا يمسّ ما اشتراه.
 *
 * ─── والفحص للصيغة المشتراة لا للمنتج كلّه ───
 *
 * المنفّذ الرقميّ العامّ يفحص كلّ ملفّات المنتج، فيمنع تسليم صيغة PDF سليمة
 * لأنّ ملفّ Word — صيغةً أخرى لم تُشترَ — مصدرُه معطوب. وهنا يُضيَّق الفحص
 * إلى ملفّات الصيغة المشتراة، مطابقًا لما يختاره `DownloadDelivery` فعلًا
 * عند التنزيل.
 */
class GoogleDriveDeliveryExecutor implements FulfillmentExecutor, PreflightsExternally
{
    /** @var array<int, string|null> أحكام الفحص الحيّ بحسب معرّف المصدر — تُملأ خارج المعاملة */
    private array $verdicts = [];

    public function preflight(Order $order, OrderItem $item): void
    {
        foreach ($this->sourcesFor($item) as $source) {
            $this->verdicts[$source->id] = $this->revalidate($source);
        }
    }

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AssetSourceResolver $sources,
        private readonly SyncGovernor $governor,
    ) {}

    public function strategies(): array
    {
        return ['GOOGLE_DRIVE_DELIVERY'];
    }

    public function execute(FulfillmentUnit $unit, Order $order, OrderItem $item): FulfillmentOutcome
    {
        if ($order->customer_id === null) {
            return FulfillmentOutcome::failed('لا زبون على الطلب — لا يُمنح استحقاقٌ بلا مالك.');
        }

        $sources = $this->sourcesFor($item);

        if ($sources === []) {
            return FulfillmentOutcome::pending(
                'لا مصدر Drive مربوطٌ بهذا البند — اربط ملفًّا قبل التسليم.'
            );
        }

        foreach ($sources as $source) {
            if (array_key_exists($source->id, $this->verdicts)) {
                $verdict = $this->verdicts[$source->id];
            } else {
                // مستدعٍ لم يُجرِ الفحص المسبق: يُسجَّل، فالنداء هنا داخل معاملة.
                Log::warning('fulfillment.drive.unpreflighted', ['source_id' => $source->id, 'order_id' => $order->id]);
                $verdict = $this->revalidate($source);
            }

            if ($verdict !== null) {
                return FulfillmentOutcome::failed($verdict);
            }
        }

        return FulfillmentOutcome::fulfilled(
            entitlementId: $this->entitlements->grantForItem($order, $item)->id,
        );
    }

    /**
     * يُعيد سبب المنع، أو `null` حين لا مانع.
     *
     * `refresh` يكتب الحالة في السجلّ أيضًا، فما يُكتشف هنا يظهر في لوحة
     * المشغّل فورًا بدل أن يبقى معرفةً لحظيّةً تضيع مع الاستجابة.
     */
    private function revalidate(AssetSource $source): ?string
    {
        try {
            $adapter = $this->sources->for($source->provider);

            if (! $adapter->verifyAccess($source->external_id)) {
                $this->governor->refresh($source, 'purchase_check');

                return 'إذن Drive مسحوبٌ عن الملفّ المشترى — لا يُمنح استحقاقٌ لا يُنزَّل.';
            }

            $meta = $adapter->getMetadata($source->external_id);

            if (! $meta->exists) {
                $this->governor->refresh($source, 'purchase_check');

                return 'ملفّ Drive لم يعد موجودًا بهذا المعرّف — استبدل المصدر.';
            }
        } catch (SourceNotConfigured|SourceUnavailable $e) {
            /*
             * تعذّر بلوغ Drive ليس حكمًا على الملفّ. والنسخة عندنا بحكم
             * `IMPORT_ONCE`، فالتسليم يمضي ويُسجَّل التعذّر ليُعاد التحقّق
             * لاحقًا بالجدولة.
             */
            if ($source->isDeliverable()) {
                return null;
            }

            return 'تعذّر التحقّق من Drive ولا نسخة داخليّة تُسلَّم: '.$e->getMessage();
        }

        /*
         * الحالة المخزَّنة تُحترم بعد الفحص الحيّ: مصدرٌ فشلت مزامنته سابقًا
         * ولا نسخة له لا يُسلَّم وإن كان الملفّ مقروءًا الآن.
         */
        return $source->isDeliverable()
            ? null
            : "مصدر الأصل في حالة {$source->sync_state}: {$source->last_error}";
    }

    /** @return list<AssetSource> */
    private function sourcesFor(OrderItem $item): array
    {
        $files = ProductFile::query()
            ->where('product_id', $item->product_id)
            /*
             * الأدوار التي تُسلَّم وحدها.
             *
             * بلا هذا القيد تُفحص العيّنة المجّانيّة أيضًا: فملفُّ عيّنةٍ
             * حُذف من Drive — وهو لا يُسلَّم لأحد — كان يمنع تسليم طلبٍ مدفوع
             * كاملِ الصلاحية. والمشتري يُحرَم ما اشتراه بسبب ملفٍّ لا يخصّه.
             */
            ->whereIn('role', ProductFile::DELIVERABLE_ROLES)
            ->when(
                $item->product_variant_id !== null,
                /*
                 * الصيغة المشتراة وما هو عامٌّ لكلّ الصيغ. `product_variant_id`
                 * الفارغ يعني «لكلّ الصيغ» لا «بلا صيغة» — وهو العقد نفسه
                 * الذي يحرسه `VariantAuthoring`.
                 */
                fn ($q) => $q->where(fn ($inner) => $inner
                    ->where('product_variant_id', $item->product_variant_id)
                    ->orWhereNull('product_variant_id')),
            )
            ->pluck('asset_id');

        return AssetSource::query()
            ->where('provider', 'GOOGLE_DRIVE')
            ->whereIn('asset_id', $files)
            ->get()
            ->all();
    }

    /**
     * الاستحقاق يُلغى بسياسته، ولا يُمسّ الملفّ عند Drive.
     *
     * سحبُ إذنٍ عند المزوّد فعلٌ في حساب المالك لا في حسابنا: قد يكون الملفّ
     * مشارَكًا مع غير هذا الزبون لأسبابٍ لا نعرفها، وسحبُه يكسر ما لا يخصّنا.
     * والتسليم عندنا يمرّ بالنسخة المستورَدة والرابط الموقَّع، فإلغاء
     * الاستحقاق يقطع الوصول فعلًا.
     */
    public function revoke(FulfillmentUnit $unit, Order $order, OrderItem $item, string $reason): ?string
    {
        return $unit->entitlement_id === null
            ? null
            : "استحقاق Drive {$unit->entitlement_id} يُلغى بسياسة السحب";
    }
}
