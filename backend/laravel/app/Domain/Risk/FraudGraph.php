<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Orders\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * تحليل العلاقات بين الكيانات لكشف الإساءة (§١٨).
 *
 * ─── ما يبني عليه ───
 *
 * لا جمعَ بياناتٍ جديد: `risk_assessments` يحفظ منذ البداية تجزئة العنوان
 * وبصمة المتصفّح والعميل والاستحقاق. وما كان ناقصًا هو **الربط**: تقييمٌ
 * واحد يُقرأ وحده لا يكشف شيئًا، وعشرون تقييمًا من عنوانٍ واحد لعشرين
 * عميلًا يكشف كلّ شيء.
 *
 * ─── القانون الحاكم (§١٨) ───
 *
 *   قرارُ المخاطرة ≠ الحقيقة الماليّة.
 *
 * هذا الملفّ **يقرأ ولا يكتب**. لا يلمس طلبًا ولا دفعةً ولا استحقاقًا ولا
 * حالة. وشبهةٌ تُغيّر حالة دفعٍ تُفسد الدفتر بإشارةٍ ظنّيّة — ثمّ لا يُعرف
 * أيّ الأرقام حقيقة وأيّها تخمين.
 *
 * ─── ولماذا لا يُحظر تلقائيًّا ───
 *
 * الإشارات الضعيفة تتقاطع مع سلوكٍ مشروع: عائلةٌ خلف عنوانٍ واحد، ومدرسةٌ
 * تشتري لصفٍّ كامل. الحظر الآليّ عليها يطرد زبائن حقيقيّين بصمت. تُعرض
 * الشبهة برموز أسبابها ويقرّر إنسان.
 */
final class FraudGraph
{
    /** نافذة التحليل — الإساءة تتكثّف في أيّام لا في أشهر. */
    private const WINDOW_DAYS = 30;

    /** عملاء من عنوانٍ واحد: أكثر من هذا عنقودٌ يستحقّ النظر. */
    private const SHARED_IP_CUSTOMERS = 3;

    /** نسبة استردادٍ تتجاوزها تُقرأ نمطًا لا حادثة. */
    private const REFUND_RATE_ALERT = 0.5;

    /**
     * @return array<string, mixed>
     */
    public function clusters(int $limit = 50): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        return [
            'windowDays' => self::WINDOW_DAYS,
            'sharedIp' => $this->sharedIp($since, $limit),
            'refundAbuse' => $this->refundAbuse($limit),
            'downloadAbuse' => $this->downloadAbuse($since, $limit),
            'sharedDevice' => $this->sharedDevice($since, $limit),
            /*
             * يُعلَن صراحةً أنّ هذا تحليلٌ لا حكم. من يقرأ الأرقام يجب أن
             * يعرف أنّها اقتراحُ نظرٍ لا قرار.
             */
            'advisory' => true,
        ];
    }

    /**
     * عنوانٌ واحد يخدم عملاء كثيرين.
     *
     * السبب المشروع شائع: عائلة، أو مدرسة، أو شبكةُ شركة. ولذلك يُعرض
     * العنقود ولا يُحظر — ورمز السبب يقول «مشترك» لا «محتال».
     *
     * @return list<array<string, mixed>>
     */
    private function sharedIp(\DateTimeInterface $since, int $limit): array
    {
        return DB::table('risk_assessments')
            ->selectRaw('ip_hash, count(distinct customer_id) as customers, count(*) as assessments')
            ->whereNotNull('ip_hash')
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', $since)
            ->groupBy('ip_hash')
            ->havingRaw('count(distinct customer_id) >= ?', [self::SHARED_IP_CUSTOMERS])
            ->orderByDesc('customers')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                // التجزئة لا العنوان: الكشف لا يتطلّب معرفة العنوان نفسه.
                'ipHash' => mb_substr((string) $r->ip_hash, 0, 12),
                'customers' => (int) $r->customers,
                'assessments' => (int) $r->assessments,
                'reasonCodes' => ['SHARED_IP'],
            ])->values()->all();
    }

    /**
     * عميلٌ يُسترد أكثر ممّا يُبقي.
     *
     * تُقاس **نسبةً** لا عددًا: من اشترى مئةً وردّ ثلاثًا ليس كمن اشترى
     * ثلاثًا وردّ ثلاثًا. والعدد المطلق وحده يُدين أكبر الزبائن.
     *
     * @return list<array<string, mixed>>
     */
    private function refundAbuse(int $limit): array
    {
        return DB::table('orders as o')
            ->selectRaw('o.customer_id, count(*) as orders, '
                .'count(*) filter (where o.refunded_total_minor > 0) as refunded, '
                .'sum(o.refunded_total_minor) as refunded_minor')
            ->whereNotNull('o.customer_id')
            ->whereIn('o.status', OrderStatus::EVER_COLLECTED)
            ->groupBy('o.customer_id')
            ->havingRaw('count(*) >= 2')
            ->havingRaw('count(*) filter (where o.refunded_total_minor > 0)::numeric / count(*) >= ?', [self::REFUND_RATE_ALERT])
            ->orderByDesc('refunded_minor')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'customerId' => (int) $r->customer_id,
                'orders' => (int) $r->orders,
                'refundedOrders' => (int) $r->refunded,
                'refundedMinor' => (int) $r->refunded_minor,
                'refundRate' => round((int) $r->refunded / max(1, (int) $r->orders), 2),
                'reasonCodes' => ['REFUND_RATE'],
            ])->values()->all();
    }

    /**
     * استحقاقٌ يُنزَّل من عناوين كثيرة.
     *
     * هذا أقوى إشارات إساءة المحتوى الرقميّ: الملفّ يُشترى مرّةً ويُوزَّع.
     * والعتبة تُقرأ من المحرّك القائم لا تُخترع هنا.
     *
     * @return list<array<string, mixed>>
     */
    private function downloadAbuse(\DateTimeInterface $since, int $limit): array
    {
        return DB::table('risk_assessments')
            ->selectRaw('entitlement_id, customer_id, count(distinct ip_hash) as ips, count(*) as attempts, max(score) as peak')
            ->whereNotNull('entitlement_id')
            ->where('created_at', '>=', $since)
            ->groupBy('entitlement_id', 'customer_id')
            ->havingRaw('count(distinct ip_hash) >= 3')
            ->orderByDesc('ips')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'entitlementId' => (int) $r->entitlement_id,
                'customerId' => $r->customer_id === null ? null : (int) $r->customer_id,
                'distinctIps' => (int) $r->ips,
                'attempts' => (int) $r->attempts,
                'peakScore' => (int) $r->peak,
                'reasonCodes' => ['DOWNLOAD_SPREAD'],
            ])->values()->all();
    }

    /**
     * بصمةُ متصفّحٍ واحدة لعملاء كثيرين.
     *
     * أقوى من العنوان: العنوان يُشارَك بحكم الشبكة، والبصمة تُشارَك بحكم
     * الجهاز. ومع ذلك تبقى إشارةً لا حكمًا — مختبرُ مدرسةٍ يُنتجها.
     *
     * @return list<array<string, mixed>>
     */
    private function sharedDevice(\DateTimeInterface $since, int $limit): array
    {
        return DB::table('risk_assessments')
            ->selectRaw('user_agent_hash, count(distinct customer_id) as customers, count(*) as assessments')
            ->whereNotNull('user_agent_hash')
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', $since)
            ->groupBy('user_agent_hash')
            ->havingRaw('count(distinct customer_id) >= ?', [self::SHARED_IP_CUSTOMERS])
            ->orderByDesc('customers')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'deviceHash' => mb_substr((string) $r->user_agent_hash, 0, 12),
                'customers' => (int) $r->customers,
                'assessments' => (int) $r->assessments,
                'reasonCodes' => ['SHARED_DEVICE'],
            ])->values()->all();
    }
}
