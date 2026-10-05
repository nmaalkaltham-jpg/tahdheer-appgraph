<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عدّادات التشغيل — «ما الذي يحتاج انتباهي الآن؟» في طلبٍ واحد.
 *
 * ─── لماذا طلبٌ واحد لا ثمانية ───
 *
 * الشريط الجانبيّ يعرض شارات، ولوحة الانتباه تعرض الأسطر نفسها. وجلبُ كلٍّ
 * منها على حدة يعني ثمانية نداءات في كلّ تحميل صفحة، وأسوأ من ذلك أنّهما
 * قد يقرآن لحظتين مختلفتين فيقول الشريط «٣» وتقول اللوحة «٤».
 *
 * ─── لماذا لا صفرَ مُختلَق ───
 *
 * كلّ عدّادٍ هنا تجميعٌ حقيقيّ على جدولٍ حقيقيّ. وما لا يوجد جدوله يُعلَن
 * `NOT_CONFIGURED` بسببه ولا يُرَدّ صفرًا: «قِسنا فلم نجد» و«لم نقس» جوابان
 * مختلفان تمامًا، وخلطُهما يجعل عطلًا صامتًا يبدو نظافةً.
 */
class OperationsSummaryController extends Controller
{
    /** طلبات تنتظر فعلًا بشريًّا — لا المكتملة ولا الملغاة. */
    private const ORDERS_NEEDING_ACTION = ['PENDING', 'AWAITING_PAYMENT', 'PROCESSING'];

    /** القضيّة مفتوحةٌ ما لم تُحسم. */
    private const CASES_OPEN = ['NEW', 'ASSIGNED', 'INVESTIGATING', 'WAITING'];

    public function __invoke(): JsonResponse
    {
        $counters = [];
        $notConfigured = [];

        $counters['ordersNeedingAction'] = $this->measure(
            'orders',
            fn () => DB::table('orders')->whereIn('status', self::ORDERS_NEEDING_ACTION)->count(),
            'طلبات تنتظر معالجة',
            '/orders',
        );

        $counters['openCases'] = $this->measure(
            'cases',
            fn () => DB::table('cases')->whereIn('state', self::CASES_OPEN)->count(),
            'قضايا مفتوحة',
            '/operations/cases',
            alert: true,
        );

        $counters['breachedCases'] = $this->measure(
            'cases',
            fn () => DB::table('cases')
                ->whereIn('state', self::CASES_OPEN)
                ->whereNotNull('breached_at')
                ->count(),
            'قضايا تجاوزت مهلتها',
            '/operations/cases',
            alert: true,
        );

        /*
         * الفشل يُقرأ من سجلّ البوّابة لا من عمود الحالة.
         *
         * كان العدّاد يعدّ `payments.state = 'FAILED'`، ولا كاتبَ لهذه القيمة
         * في المشروع كلّه: `OrderFinalizer` يكتب `CAPTURED` و`CheckoutController`
         * يكتب `CREATED`، ولا ثالثَ لهما. أي أنّ شارة «محاولات دفعٍ فاشلة»
         * كانت صفرًا بنيويًّا مهما فشل من مدفوعات — إنذارٌ ميّت يطمئن المشغّل
         * كذبًا. والفشل الحقيقيّ محفوظ في `webhook_deliveries` حيث يكتبه
         * `WebhookIntake`، وهو المصدر الذي تقرؤه `ProviderDiagnostics` أصلًا.
         */
        $counters['failedPayments'] = $this->measure(
            'webhook_deliveries',
            fn () => DB::table('webhook_deliveries')->where('status', 'FAILED')->count(),
            'أحداث دفعٍ فشلت معالجتها',
            '/payments/webhooks',
            alert: true,
        );

        /*
         * حدثٌ وصل من البوّابة ولم يُطابَق بطلب: مالٌ قد يكون حُصّل ولا أحد
         * يعلم لأيّ طلب. يُفرد عن الفشل لأنّ علاجه مختلف — الفشل يُعاد
         * تشغيله، وغير المطابَق يُبحث عن طلبه أوّلًا.
         */
        $counters['unmatchedWebhooks'] = $this->measure(
            'webhook_deliveries',
            fn () => DB::table('webhook_deliveries')->where('status', 'UNMATCHED')->count(),
            'أحداث بوّابةٍ بلا طلبٍ مطابق',
            '/payments/webhooks',
            alert: true,
        );

        /*
         * ─── أخطر رقمٍ في متجرٍ رقميّ ───
         *
         * طلبٌ وصل ماله ولم تُنشأ له صلاحيّة وصول: العميل دفع ولم يستلم.
         * لا شاشةَ في اللوحة كانت تقوله، ولا عدّاد. والغياب يُكتب
         * `whereNotExists` لا بوصلةٍ خارجيّة: الوصلة تضاعف الطلب ذا الصلاحيّتين
         * فيصير العدّ أكبر من عدد الطلبات.
         */
        $counters['undeliveredOrders'] = $this->measure(
            'entitlements',
            fn () => DB::table('orders')
                ->whereIn('status', OrderController::PAID_STATUSES)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('entitlements')
                    ->whereColumn('entitlements.order_id', 'orders.id'))
                ->count(),
            'طلبات مدفوعة بلا صلاحية وصول',
            '/orders?view=undelivered',
            alert: true,
        );

        /*
         * ولا وحدةَ تنفيذٍ له: التسليم قد يكون تمّ بصلاحيّةٍ يدويّة، لكنّ
         * غياب وحدة التنفيذ يعني أنّ مُنسّق التنفيذ لم يمرّ على الطلب أصلًا —
         * فلا إعادة محاولة ولا أثر. يُفرد عن سابقه لأنّهما عطلان مختلفان.
         */
        $counters['unfulfilledOrders'] = $this->measure(
            'fulfillment_units',
            fn () => DB::table('orders')
                ->whereIn('status', OrderController::PAID_STATUSES)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fulfillment_units')
                    ->whereColumn('fulfillment_units.order_id', 'orders.id'))
                ->count(),
            'طلبات مدفوعة بلا وحدة تنفيذ',
            '/orders?view=unfulfilled',
            alert: true,
        );

        /*
         * تقييمُ مخاطرةٍ ينتظر بشرًا. `PENDING` هي القيمة التي يكتبها المحرّك
         * للقرارَين `MANUAL_REVIEW` و`DENY` — وقيدُ الجدول نفسه يفرض أنّ
         * أحدهما لا يُكتب بلا نتيجة مراجعة.
         */
        $counters['pendingRiskReviews'] = $this->measure(
            'risk_assessments',
            fn () => DB::table('risk_assessments')->where('review_outcome', 'PENDING')->count(),
            'تقييمات مخاطرة تنتظر مراجعة',
            '/operations/risk',
            alert: true,
        );

        $counters['failedFulfillment'] = $this->measure(
            'fulfillment_units',
            fn () => DB::table('fulfillment_units')->where('status', 'FAILED')->count(),
            'وحدات تنفيذٍ فاشلة',
            '/operations/fulfillment',
            alert: true,
        );

        $counters['pendingFulfillment'] = $this->measure(
            'fulfillment_units',
            fn () => DB::table('fulfillment_units')->whereIn('status', ['PENDING', 'READY'])->count(),
            'وحدات تنفيذٍ معلّقة',
            '/operations/fulfillment',
        );

        /*
         * المنخفض = المتاح (الموجود ناقص المحجوز) عند نقطة إعادة الطلب أو
         * دونها. والنقطة صفرًا تعني «لم تُضبط»، فلا يُحسب الصفّ منخفضًا —
         * وإلّا لَعُدّ كلّ متغيّرٍ بلا سياسة مخزونٍ إنذارًا دائمًا.
         */
        $counters['lowStock'] = $this->measure(
            'stock_levels',
            fn () => DB::table('stock_levels')
                ->where('reorder_point', '>', 0)
                ->whereRaw('(on_hand - reserved) <= reorder_point')
                ->count(),
            'متغيّرات عند نقطة إعادة الطلب',
            '/operations/inventory',
        );

        $counters['outOfStock'] = $this->measure(
            'stock_levels',
            fn () => DB::table('stock_levels')->whereRaw('(on_hand - reserved) <= 0')->count(),
            'متغيّرات نفدت',
            '/operations/inventory',
            alert: true,
        );

        $counters['brokenLinks'] = $this->measure(
            'not_found_hits',
            /*
             * إصابتان فأكثر: زيارةٌ واحدة لرابطٍ مكسور قد تكون خطأً مطبعيًّا
             * من زائرٍ واحد، والتكرار هو ما يدلّ على رابطٍ منشورٍ فعلًا.
             */
            fn () => DB::table('not_found_hits')->where('hits', '>=', 2)->count(),
            'روابط مكسورة متكرّرة',
            '/seo-health',
        );

        $counters['queueFailures'] = $this->measure(
            'failed_jobs',
            fn () => DB::table('failed_jobs')->count(),
            'مهامّ فشلت في الطابور',
            '/system',
            alert: true,
        );

        $counters['uncertainOperations'] = $this->measure(
            'operation_records',
            fn () => DB::table('operation_records')->where('status', 'UNCERTAIN')->count(),
            'عمليّات مجهولة النتيجة',
            '/system',
            alert: true,
        );

        foreach ($counters as $key => $counter) {
            if (($counter['state'] ?? null) === 'NOT_CONFIGURED') {
                $notConfigured[] = ['key' => $key] + $counter;
            }
        }

        return response()->json([
            'generatedAt' => now()->toIso8601String(),
            'counters' => $counters,
            'notConfigured' => $notConfigured,
        ]);
    }

    /**
     * عدّادٌ واحد — أو إعلانُ أنّ مصدره غير مبنيّ.
     *
     * @param  callable():int  $tally
     * @return array<string, mixed>
     */
    private function measure(
        string $table,
        callable $tally,
        string $label,
        string $href,
        bool $alert = false,
    ): array {
        if (! Schema::hasTable($table)) {
            return [
                'label' => $label,
                'state' => 'NOT_CONFIGURED',
                'reason' => "الجدول «{$table}» غير موجود — لا مصدر لهذا العدّاد.",
            ];
        }

        return [
            'label' => $label,
            'state' => 'MEASURED',
            'value' => $tally(),
            'href' => $href,
            'alert' => $alert,
        ];
    }
}
