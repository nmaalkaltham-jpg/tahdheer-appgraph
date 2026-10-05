<?php

declare(strict_types=1);

namespace App\Domain\Cases;

use App\Domain\Finance\Reconciliation;
use App\Domain\Risk\FraudGraph;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * يحوّل ما تكشفه الكاشفات إلى قضايا (§١٩ §٥٤).
 *
 * ─── لماذا أمرٌ مجدول لا أثرٌ جانبيّ للقراءة ───
 *
 * `Reconciliation::run` و`FraudGraph::clusters` **قراءتان محضتان**، وثمّة
 * اختبارٌ يُثبت أنّهما لا تكتبان. وفتحُ قضيّةٍ من داخل نداء `GET` يعني أنّ
 * فتح شاشةٍ يُنشئ صفوفًا — فيختلف الجواب باختلاف من نظر ومتى.
 *
 * والفصل يجعل الكشف قابلًا للتشغيل بلا خوف، والفتح فعلًا واحدًا مجدولًا
 * له سجلّه.
 *
 * ─── لماذا المفاتيح مستقرّة ───
 *
 * المفتاح يشتقّ من **الكيان** لا من اللحظة: `recon:amount:{payment}` يبقى
 * نفسه في كلّ مسح. فالمسح كلّ ساعةٍ لا يفتح أربعًا وعشرين قضيّة عن العيب
 * نفسه — يُضيف إلى خيط القضيّة القائمة أنّ الشرط ما يزال قائمًا.
 *
 * ─── لماذا لا يُغلق شيءٌ آليًّا ───
 *
 * زوالُ الشرط من نتيجة المسح لا يعني أنّ العيب عولج: قد يكون خرج من نافذة
 * التحليل، أو تغيّرت عتبة. والإغلاق الآليّ يمحو أثر عيبٍ ماليّ لم ينظر فيه
 * أحد. الإغلاق قرار إنسان برمزٍ مكتوب.
 */
final class CaseScanner
{
    /** حدثٌ ميّت في صندوق الصادر: أثرٌ لم يقع ولن يقع بلا تدخّل. */
    private const DEAD_EVENT_ALERT = 1;

    /** عمليّاتٌ مجهولة النتيجة يتجاوز عددُها هذا: التوفيق نفسه لا يعمل. */
    private const UNCERTAIN_ALERT = 5;

    public function __construct(
        private readonly CaseService $cases,
        private readonly Reconciliation $reconciliation,
        private readonly FraudGraph $fraud,
    ) {}

    /**
     * @return array{opened: int, recurring: int, byKind: array<string, int>}
     */
    public function scan(): array
    {
        $opened = 0;
        $recurring = 0;
        $byKind = ['INCIDENT' => 0, 'FRAUD' => 0];

        foreach ($this->findings() as $finding) {
            [, $isNew] = $this->cases->open(
                kind: $finding['kind'],
                title: $finding['title'],
                reasonCodes: $finding['reasonCodes'],
                severity: $finding['severity'],
                dedupeKey: $finding['dedupeKey'],
                subjectType: $finding['subjectType'],
                subjectId: $finding['subjectId'],
                customerId: $finding['customerId'] ?? null,
            );

            if ($isNew) {
                $opened++;
                $byKind[$finding['kind']]++;
            } else {
                $recurring++;
            }
        }

        return ['opened' => $opened, 'recurring' => $recurring, 'byKind' => $byKind];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findings(): array
    {
        $out = [];
        $recon = $this->reconciliation->run();

        /*
         * فروق المال حرجة: كلّ صفٍّ هنا مالٌ لا يطابق دفترًا. وتركُها في
         * قائمةٍ تُقرأ عند الرغبة يعني أنّها تُقرأ حين يشتكي أحد.
         */
        foreach ($recon['amountMismatch'] as $row) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'CRITICAL',
                'title' => 'فرق مبلغ على الطلب '.$row['orderNumber'].': '.$row['differenceMinor'].' هللة',
                'reasonCodes' => ['AMOUNT_MISMATCH'],
                'dedupeKey' => 'recon:amount:'.$row['paymentId'],
                'subjectType' => 'payment',
                'subjectId' => $row['paymentId'],
            ];
        }

        /*
         * §١١ — مالٌ على طلبٍ لم يُموَّل: رسمٌ متأخّر بعد إلغاء، أو رسمٌ
         * رُفض تمويلُه. المال موجود عند المزوّد ولا يُقابله تسليم — يُردّ
         * أو يُعاد فتح الطلب بقرارٍ بشريّ، ولا يُترك يتيمًا.
         */
        foreach ($recon['orphanCapture'] as $row) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'CRITICAL',
                'title' => 'تحصيلٌ على طلبٍ غير مموَّل '.$row['orderNumber'].' ('.$row['orderStatus'].')',
                'reasonCodes' => ['ORPHAN_CAPTURE'],
                'dedupeKey' => 'recon:orphan-capture:'.$row['paymentId'],
                'subjectType' => 'payment',
                'subjectId' => $row['paymentId'],
            ];
        }

        foreach ($recon['refundLedgerMismatch'] as $row) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'CRITICAL',
                'title' => 'دفتر الاسترداد يخالف الاستردادات على الطلب '.$row['orderNumber'].': '.$row['ledgerMinor'].' مقابل '.$row['settledMinor'].' هللة',
                'reasonCodes' => ['REFUND_LEDGER_MISMATCH'],
                'dedupeKey' => 'recon:refund-ledger:'.$row['orderId'],
                'subjectType' => 'order',
                'subjectId' => $row['orderId'],
            ];
        }

        // مالٌ وصل ولم يُسلَّم شيء مقابله.
        foreach ($recon['paidWithoutFulfillment'] as $row) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'CRITICAL',
                'title' => 'طلبٌ مدفوع بلا تنفيذ '.$row['orderNumber'],
                'reasonCodes' => ['PAID_WITHOUT_FULFILLMENT'],
                'dedupeKey' => 'recon:paid-unfulfilled:'.$row['orderId'],
                'subjectType' => 'order',
                'subjectId' => $row['orderId'],
            ];
        }

        // استردادٌ من لوحة المزوّد: سُجِّل، وقرار الاستحقاق ينتظر مشغّلًا.
        foreach ($recon['providerRefunds'] as $row) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'HIGH',
                'title' => 'استردادٌ من لوحة المزوّد على الطلب '.$row['orderNumber'].': '.$row['amountMinor'].' هللة — قرّر مصير الاستحقاق',
                'reasonCodes' => ['PROVIDER_REFUND_RECORDED'],
                'dedupeKey' => 'recon:provider-refund:'.$row['refundId'],
                'subjectType' => 'refund',
                'subjectId' => $row['refundId'],
            ];
        }

        foreach ($recon['duplicateCapture'] as $row) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'CRITICAL',
                'title' => 'تحصيلان على الطلب '.$row['orderId'],
                'reasonCodes' => ['DUPLICATE_CAPTURE'],
                'dedupeKey' => 'recon:dupe-capture:'.$row['orderId'],
                'subjectType' => 'order',
                'subjectId' => $row['orderId'],
            ];
        }

        // منتجٌ سُلّم بلا إذنٍ تجاريّ — والملفّ الذي نُزّل لا يعود.
        foreach ($recon['unauthorizedEntitlements'] as $row) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'CRITICAL',
                'title' => 'استحقاق بلا إذن على الطلب '.$row['orderNumber'],
                'reasonCodes' => ['UNAUTHORIZED_ENTITLEMENT'],
                'dedupeKey' => 'recon:unauth-entitlement:'.$row['entitlementId'],
                'subjectType' => 'entitlement',
                'subjectId' => $row['entitlementId'],
                'customerId' => $row['customerId'],
            ];
        }

        // استردادٌ لم يُحسم: مالٌ محجوز قد يكون خرج ولا يعرف الدفتر.
        foreach ($recon['refundUnresolved'] as $row) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'HIGH',
                'title' => 'استرداد غير محسوم على الطلب '.$row['orderNumber'].' ('.$row['status'].'): '.$row['amountMinor'].' هللة',
                'reasonCodes' => ['REFUND_UNRESOLVED'],
                'dedupeKey' => 'recon:refund-unresolved:'.$row['refundId'],
                'subjectType' => 'refund',
                'subjectId' => $row['refundId'],
            ];
        }

        foreach ($recon['refundExceedsCapture'] as $row) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'CRITICAL',
                'title' => 'استرداد يتجاوز التحصيل على '.$row['orderNumber'],
                'reasonCodes' => ['REFUND_EXCEEDS_CAPTURE'],
                'dedupeKey' => 'recon:over-refund:'.$row['orderId'],
                'subjectType' => 'order',
                'subjectId' => $row['orderId'],
            ];
        }

        /*
         * الاحتيال **تحذير** لا حرج: إشارات ضعيفة تتقاطع مع سلوكٍ مشروع،
         * ورفعُها إلى «حرج» يجعل الحرج بلا معنى فيُتجاهَل حين يكون حقيقيًّا.
         */
        $clusters = $this->fraud->clusters();

        foreach ($clusters['refundAbuse'] as $row) {
            $out[] = [
                'kind' => 'FRAUD',
                'severity' => 'WARNING',
                'title' => 'نسبة استرداد مرتفعة للعميل '.$row['customerId'],
                'reasonCodes' => $row['reasonCodes'],
                'dedupeKey' => 'fraud:refund-rate:'.$row['customerId'],
                'subjectType' => 'customer',
                'subjectId' => $row['customerId'],
                'customerId' => $row['customerId'],
            ];
        }

        foreach ($clusters['downloadAbuse'] as $row) {
            $out[] = [
                'kind' => 'FRAUD',
                'severity' => 'WARNING',
                'title' => 'انتشار تنزيل: استحقاق '.$row['entitlementId'].' من '.$row['distinctIps'].' عنوانًا',
                'reasonCodes' => $row['reasonCodes'],
                'dedupeKey' => 'fraud:download-spread:'.$row['entitlementId'],
                'subjectType' => 'entitlement',
                'subjectId' => $row['entitlementId'],
                'customerId' => $row['customerId'],
            ];
        }

        /*
         * فروق التسوية تصل **مبنيّة**: لكلٍّ رمزٌ وحدّةٌ وفعلُ إصلاحٍ مقترح.
         * فلا يُعاد بناء المعنى هنا — البناء في `SettlementReconciler` وحده.
         */
        foreach ($recon['settlement']['findings'] as $finding) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => $finding['severity'],
                'title' => $finding['title'],
                'reasonCodes' => [$finding['code'], 'REPAIR:'.$finding['repairAction']],
                'dedupeKey' => $finding['dedupeKey'],
                'subjectType' => 'settlement',
                'subjectId' => $finding['evidence']['paymentId'] ?? null,
            ];
        }

        /*
         * نجاحٌ عند المزوّد وفشلٌ عندنا: المال تحرّك والأثر لم يقع. أخطر ما
         * في الدفتر، ويُكشف من التسليمات لا من المدفوعات إذ لا دفعة تُنشأ.
         */
        foreach ($recon['providerSuccessLocalFailure'] as $row) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'CRITICAL',
                'title' => 'نجاح عند المزوّد وفشل عندنا: '.($row['chargeId'] ?? '—'),
                'reasonCodes' => ['PROVIDER_SUCCESS_LOCAL_FAILURE', 'REPAIR:REPLAY_WEBHOOK'],
                'dedupeKey' => 'recon:provider-success:'.$row['deliveryId'],
                'subjectType' => 'webhook_delivery',
                'subjectId' => $row['deliveryId'],
            ];
        }

        return array_merge($out, $this->systemFindings());
    }

    /**
     * أعطال التشغيل التي لا تُصلح نفسها.
     *
     * ─── لماذا هذه بالذات ───
     *
     * شاشة التشغيل تعرضها منذ بُنيت، وعرضُها يفترض أنّ أحدًا ينظر. والقضيّة
     * تقلب الافتراض: تُسند، وتُتابَع، وتُغلق بسببٍ مكتوب.
     *
     * ─── ولماذا عتبةٌ لا صفر ───
     *
     * عمليّةٌ واحدة مجهولة النتيجة أمرٌ عاديّ يحسمه التوفيق في دقائق. وخمسٌ
     * معلّقة معًا تعني أنّ التوفيق نفسه لا يعمل — وهو عطلٌ لا يكشفه شيءٌ آخر.
     *
     * @return list<array<string, mixed>>
     */
    public const AWAITING_PAYMENT_HOURS = 24;

    private function systemFindings(): array
    {
        $out = [];

        $dead = (int) DB::table('outbox_events')->where('status', 'DEAD')->count();

        if ($dead >= self::DEAD_EVENT_ALERT) {
            /*
             * المفتاح لا يحمل العدد: عدٌّ متغيّر يفتح قضيّةً جديدة كلّما مات
             * حدثٌ آخر. القضيّة واحدة عن الحالة، والعدد في عنوانها.
             */
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'HIGH',
                'title' => 'أحداث ميّتة في صندوق الصادر: '.$dead,
                'reasonCodes' => ['DEAD_OUTBOX_EVENTS'],
                'dedupeKey' => 'system:outbox-dead',
                'subjectType' => null,
                'subjectId' => null,
            ];
        }

        $uncertain = (int) DB::table('operation_records')->where('status', 'UNCERTAIN')->count();

        if ($uncertain >= self::UNCERTAIN_ALERT) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'HIGH',
                'title' => 'عمليّات مجهولة النتيجة لم يحسمها التوفيق: '.$uncertain,
                'reasonCodes' => ['RECONCILIATION_STALLED'],
                'dedupeKey' => 'system:operations-uncertain',
                'subjectType' => null,
                'subjectId' => null,
            ];
        }

        /*
         * مهامٌّ فاشلة: الطابور يعدّها ولا يفعل بها شيئًا. الصندوق الصادر له
         * مسارُ موتٍ وإعادة، والطابور لم يكن له إلّا عدّاد.
         */
        $failedJobs = Schema::hasTable('failed_jobs')
            ? (int) DB::table('failed_jobs')->count()
            : 0;

        if ($failedJobs > 0) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'HIGH',
                'title' => 'مهامّ فاشلة في الطابور بلا إعادة: '.$failedJobs,
                'reasonCodes' => ['FAILED_JOBS'],
                'dedupeKey' => 'system:failed-jobs',
                'subjectType' => null,
                'subjectId' => null,
            ];
        }

        /*
         * طلباتٌ تنتظر الدفع منذ يوم: نيّةٌ فشلت ولم يُعِدها أحد، أو مزوّدٌ لم
         * يُرسل خطّافه قطّ. لا يُصلحها المسح — يجعلها مرئيّة لعينٍ بشريّة.
         */
        $stuck = (int) DB::table('orders')
            ->where('status', 'AWAITING_PAYMENT')
            ->where('created_at', '<', now()->subHours(self::AWAITING_PAYMENT_HOURS))
            ->count();

        if ($stuck > 0) {
            $out[] = [
                'kind' => 'INCIDENT',
                'severity' => 'WARNING',
                'title' => 'طلبات تنتظر الدفع منذ أكثر من '.self::AWAITING_PAYMENT_HOURS.' ساعة: '.$stuck,
                'reasonCodes' => ['STUCK_AWAITING_PAYMENT'],
                'dedupeKey' => 'system:orders-awaiting-payment',
                'subjectType' => null,
                'subjectId' => null,
            ];
        }

        /*
         * لا قضيّة مجمَّعة عن الخطّافات الفاشلة.
         *
         * `PROVIDER_SUCCESS_LOCAL_FAILURE` يفتح قضيّةً **لكلّ تسليم** بمرجعه
         * وفعل إصلاحه. والمجمَّعة فوقها تعني قضيّتين عن الشرط الواحد — وهو
         * الضجيج نفسه الذي وُجد الدمج ليمنعه. الأخصّ يغلب.
         */

        return $out;
    }
}
