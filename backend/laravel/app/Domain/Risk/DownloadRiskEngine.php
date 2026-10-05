<?php

namespace App\Domain\Risk;

use App\Domain\Risk\Models\RiskAssessment;
use App\Models\Entitlement;
use Illuminate\Support\Facades\DB;

/**
 * تقييم خطر التنزيل (§١٠٣ §١٠٥).
 *
 * ─── القيد الحاكم ───
 *
 * §١٠٥: «إشارات الخطر لا تُثبت إساءةً تلقائيًّا». ولذلك المنع الآليّ محجوزٌ
 * لما لا لبس فيه — تجاوزُ حدٍّ مُعلَن اشتراه الزبون ووافق عليه — وما عداه
 * يُرفع لإنسان.
 *
 * والسبب عمليّ لا أخلاقيّ فحسب: متجرٌ يمنع بناءً على تغيّر عنوان IP يمنع
 * المسافرين وأصحاب الهواتف المتنقّلة بين الأبراج قبل أن يمنع لصًّا واحدًا،
 * فيدفع ثمنًا في الدعم والسمعة أكبر ممّا يوفّره.
 *
 * ─── لماذا أربعة قرارات لا اثنان ───
 *
 * بين «اسمح» و«امنع» يقع «تحقّق» و«راجعْ يدويًّا». نظامٌ بقرارين يضطرّ إلى
 * الاختيار بين تجاهل النمط المريب أو قطع الوصول عن زبونٍ دافع — وكلاهما
 * خطأ. الوسطان هما ما يجعل الحماية قابلةً للتشغيل أصلًا.
 *
 * ─── لا عنوان IP خامًا ───
 *
 * المقارنة المطلوبة «أهو العنوان نفسه؟» لا «ما هو العنوان؟». التجزئة تكفي
 * لها وتُسقط الاحتفاظ بمعرّفٍ شخصيّ (§١٠٦).
 */
class DownloadRiskEngine
{
    /** نافذة الرصد. أقصر منها يفوّت الأنماط، وأطول يعاقب على سلوكٍ قديم. */
    private const WINDOW_HOURS = 24;

    /** عتبات القرار — تُقرأ مع الإشارات لا وحدها. */
    private const CHALLENGE_AT = 30;

    private const REVIEW_AT = 60;

    public function assess(Entitlement $entitlement, ?string $ip, ?string $userAgent): RiskDecision
    {
        $signals = [];

        foreach ([
            $this->limitExhausted($entitlement),
            $this->burstOfDownloads($entitlement),
            $this->manyDistinctAddresses($entitlement),
            $this->refundAfterDownload($entitlement),
        ] as $signal) {
            if ($signal !== null) {
                $signals[] = $signal;
            }
        }

        $score = array_sum(array_map(static fn (RiskSignal $s) => $s->weight, $signals));
        $decision = $this->decide($signals, $score);

        $assessment = RiskAssessment::create([
            'subject_type' => 'download',
            'subject_id' => $entitlement->id,
            'customer_id' => $entitlement->customer_id,
            'entitlement_id' => $entitlement->id,
            'decision' => $decision,
            'score' => min($score, 65535),
            'signals' => array_map(static fn (RiskSignal $s) => $s->toArray(), $signals),
            'reason' => $signals === [] ? null : $signals[0]->description,
            'ip_hash' => $ip === null ? null : hash('sha256', $ip),
            'user_agent_hash' => $userAgent === null ? null : hash('sha256', $userAgent),
            // ما رُفع أو مُنع يحمل حالةَ مراجعة، وإلّا اختفى من طابور المراجعة.
            'review_outcome' => in_array($decision, ['MANUAL_REVIEW', 'DENY'], true) ? 'PENDING' : null,
        ]);

        return new RiskDecision($decision, $signals, $score, $assessment->id);
    }

    /**
     * @param  list<RiskSignal>  $signals
     */
    private function decide(array $signals, int $score): string
    {
        /*
         * المنع الوحيد الآليّ: حدٌّ مُعلَن استُنفد. الزبون اشترى عددًا محدّدًا
         * من التنزيلات ويعرفه، فالمنع هنا تنفيذُ عقدٍ لا اتّهامُ أحد.
         */
        foreach ($signals as $signal) {
            if ($signal->code === 'limit_exhausted') {
                return 'DENY';
            }
        }

        if ($score >= self::REVIEW_AT) {
            return 'MANUAL_REVIEW';
        }

        if ($score >= self::CHALLENGE_AT) {
            return 'CHALLENGE';
        }

        return 'ALLOW';
    }

    private function limitExhausted(Entitlement $entitlement): ?RiskSignal
    {
        if ($entitlement->download_limit === null) {
            return null;
        }

        if ($entitlement->download_count < $entitlement->download_limit) {
            return null;
        }

        return new RiskSignal(
            'limit_exhausted', 100,
            'استُنفد حدّ التنزيلات المُعلَن لهذا الاستحقاق.',
            ['limit' => (int) $entitlement->download_limit, 'used' => (int) $entitlement->download_count],
        );
    }

    /**
     * دفعةُ تنزيلاتٍ في نافذةٍ قصيرة.
     *
     * الوزن متدرّجٌ لا ثنائيّ: عشرون تنزيلًا في يومٍ قد تكون مستخدمًا يعيد
     * المحاولة على اتّصالٍ سيّئ، ومئةٌ نمطٌ آخر. العتبة الواحدة تجعل الأولى
     * كالثانية.
     */
    private function burstOfDownloads(Entitlement $entitlement): ?RiskSignal
    {
        $count = DB::table('download_events')
            ->where('entitlement_id', $entitlement->id)
            ->where('event_type', 'completed')
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->count();

        if ($count < 20) {
            return null;
        }

        return new RiskSignal(
            'download_burst', $count >= 60 ? 45 : 25,
            "‏{$count} تنزيلًا في ".self::WINDOW_HOURS.' ساعة.',
            ['count' => $count, 'windowHours' => self::WINDOW_HOURS],
        );
    }

    /**
     * عناوين كثيرة لاستحقاقٍ واحد — مؤشّر مشاركةِ حساب.
     *
     * «مؤشّر» لا «دليل»: عائلةٌ على شبكاتٍ مختلفة وموظّفٌ ينتقل بين مكتبٍ
     * وبيت يُنتجان العدد نفسه. ولذلك يرفع الدرجة ولا يمنع.
     */
    private function manyDistinctAddresses(Entitlement $entitlement): ?RiskSignal
    {
        $distinct = DB::table('download_grants')
            ->where('entitlement_id', $entitlement->id)
            ->whereNotNull('ip')
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->distinct()->count('ip');

        if ($distinct < 5) {
            return null;
        }

        return new RiskSignal(
            'many_addresses', $distinct >= 12 ? 40 : 20,
            "‏{$distinct} عنوانًا مختلفًا في ".self::WINDOW_HOURS.' ساعة.',
            ['distinctAddresses' => $distinct],
        );
    }

    /**
     * تنزيلٌ ثمّ استرداد (§١٠٥).
     *
     * النمط الأغلى على متجرٍ رقميّ: يأخذ الملفّ ثمّ يستردّ ثمنه. لا يُمنع
     * آليًّا — قد يكون الملفّ معطوبًا فعلًا — لكنّه يستحقّ عينًا بشريّة.
     */
    private function refundAfterDownload(Entitlement $entitlement): ?RiskSignal
    {
        $refunds = DB::table('refunds')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->where('orders.customer_id', $entitlement->customer_id)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('entitlements')
                    ->join('download_events', 'download_events.entitlement_id', '=', 'entitlements.id')
                    ->whereColumn('entitlements.order_id', 'orders.id')
                    ->where('download_events.event_type', 'completed');
            })
            ->count();

        if ($refunds < 2) {
            return null;
        }

        return new RiskSignal(
            'refund_after_download', 50,
            "‏{$refunds} استردادًا بعد تنزيلٍ مكتمل لهذا الزبون.",
            ['refundsAfterDownload' => $refunds],
        );
    }
}
