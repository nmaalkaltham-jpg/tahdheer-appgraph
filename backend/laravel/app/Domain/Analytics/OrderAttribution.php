<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Search\Analytics\SearchAttribution;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ربطُ الطلب برحلة صاحبه — اللقطة التي تجعل «إيراد الصفحة» سؤالًا له جواب.
 *
 * ─── نموذج الإسناد، مُعلَنًا لا مضمَرًا ───
 *
 * **اللمسة الأولى** ضمن المدّة المحفوظة: أوّل صفحةٍ رآها هذا الزائر، ومُحيلها.
 * لا آخر لمسة. والفرق ليس أكاديميًّا — آخر لمسةٍ تنسب كلّ إيرادٍ إلى صفحة
 * السلّة وصفحة الدفع، فيبدو أنّ المتجر يكسب من صفحاتٍ لا تجلب أحدًا.
 *
 * ولأنّ النموذج يُغيّر كلّ رقمٍ يُبنى عليه، فهو مكتوبٌ في صيغة المؤشّر نفسها
 * (`formula`) لا في هذا التعليق وحده.
 *
 * ─── ولماذا خارج معاملة الدفع ───
 *
 * الإسناد قياسٌ لا محاسبة. وكتابتُه داخل معاملة إنشاء الطلب تعني أنّ عطلًا في
 * جدول قياسٍ يُرجع معاملةً ماليّة — أي أنّ زبونًا دفع ولا طلب له. فيُكتب بعد
 * أن يوجد الطلب، ويُبتلع فشلُه ويُسجَّل.
 *
 * ─── ولا سطر بلا موافقة ───
 *
 * بلا بصمة زائرٍ لا يوجد ما يُسنَد إليه. وصفٌّ بأعمدةٍ فارغة يوهم بإسنادٍ
 * وقع وفشل، بينما الحقيقة أنّ الزائر لم يوافق على القياس أصلًا — وهي حالةٌ
 * تُقاس في التغطية بالطرح لا بصفٍّ كاذب.
 */
final class OrderAttribution
{
    public function __construct(
        private readonly VisitorIdentity $identity,
        private readonly EventIngest $ingest,
        /* إسنادُ أسطر الطلب إلى البحث — منتجًا منتجًا (`SearchAttribution`). */
        private readonly SearchAttribution $search,
    ) {}

    public function record(Order $order, Request $request): void
    {
        $this->search->onOrder($order, $request);

        try {
            if ($this->ingest->refusesTracking($request)) {
                return;
            }

            $visitorId = $this->identity->read($request, VisitorIdentity::VISITOR_COOKIE);

            if ($visitorId === null) {
                return;
            }

            $visitorHash = $this->ingest->hash($visitorId);

            if (! $this->ingest->hasAnalyticsConsent($visitorHash)) {
                return;
            }

            $sessionId = $this->identity->read($request, VisitorIdentity::SESSION_COOKIE);

            /*
             * أوّل حدثٍ محفوظ لهذا الزائر. `page_view` وحده: حدثُ «أضيف إلى
             * السلّة» يقع على صفحة المنتج فينسب الجلب إليها مرّتين، وحدثُ
             * البحث لا صفحة له أصلًا.
             */
            $first = AnalyticsEvent::query()
                ->where('visitor_hash', $visitorHash)
                ->where('event_type', 'page_view')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->first(['path', 'referrer_host', 'occurred_at']);

            $touches = AnalyticsEvent::query()->where('visitor_hash', $visitorHash)->count();

            /*
             * الكتابة داخل معاملةٍ خاصّة بها — وهي نقطة حفظٍ حين يكون هناك
             * معاملةٌ جارية.
             *
             * وهذا ما يجعل الضمان صحيحًا لا مُدّعًى: في PostgreSQL أيُّ خطأ
             * SQL داخل معاملةٍ **يُبطلها كلّها**، فلا يكفي أن نلتقط الاستثناء
             * هنا — لو نُودي هذا السطر يومًا من داخل معاملة المنادي لأسقط
             * عطلُ قياسٍ معاملةً ماليّة رغم `catch`. ونقطة الحفظ تحصر الإبطال
             * في الكتابة وحدها.
             */
            DB::transaction(fn () => DB::table('order_attributions')->updateOrInsert(
                ['order_id' => $order->id],
                [
                    'visitor_hash' => $visitorHash,
                    'session_hash' => $sessionId === null ? null : $this->ingest->hash($sessionId),
                    'landing_path' => $first->path ?? null,
                    'landing_referrer_host' => $first->referrer_host ?? null,
                    'first_seen_at' => $first->occurred_at ?? null,
                    'touch_count' => $touches,
                    'anonymized_at' => null,
                    'created_at' => now(),
                ],
            ));
        } catch (Throwable $e) {
            /*
             * الإسناد لا يُسقط طلبًا. الزبون دفع، والطلب قائم، وفقدُ سطر
             * قياسٍ أهون من ردٍّ بخطأ بعد خصم المبلغ.
             */
            Log::warning('analytics.attribution_failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
