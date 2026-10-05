<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Privacy\CredentialPaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * استقبال أحداث القياس — حيث يصير الوعد المنشور شيفرة.
 *
 * ─── الوعد الذي يحكم هذا الصنف ───
 *
 * سياسة الخصوصيّة المنشورة على المتجر تقول حرفيًّا: «نحترم إشارتَي
 * Do Not Track و Global Privacy Control. عند وصول أيّهما، يتوقّف القياس
 * كلّيًا، ولا يوجد إعداد في الموقع يستطيع تجاوز ذلك».
 *
 * فالرفض هنا مطلق: لا رايةَ ولا ضبطَ ولا موافقةٌ مخزَّنة تتجاوزه. وهو يُفحص
 * **أوّلًا**، قبل أيّ قراءةٍ أو كتابة — فترتيبُ الفحوص جزءٌ من الوعد لا تفصيلُ
 * تنفيذ.
 *
 * ─── والخطّ الذي ترسمه السياسة بين حدثٍ وحدث ───
 *
 * الحدث بلا معرّفٍ رابط — مسارٌ ونوعٌ وجهاز — لا يعرّف بشخص، ويُكتب بلا
 * موافقة. وبصمة الزائر والجلسة تربطان زياراتٍ ببعضها، فلا تُكتبان إلّا
 * بموافقةٍ صريحة لغرض `ANALYTICS`.
 *
 * ولذلك لا يُرفض الحدث كلّه عند غياب الموافقة: رفضُه يُفقد المتجر عدّ صفحاته
 * بلا داعٍ، وحفظُه بمعرّفٍ يكسر الوعد. الوسط هو ما تنصّ عليه السياسة.
 *
 * ─── ولماذا لا يُخزَّن عنوان الشبكة ───
 *
 * لا `ip` في الجدول ولا في المسار. والبصمة تأتي من كعكةٍ أولى‑الطرف يملكها
 * الزائر ويمحوها متى شاء — لا من بصمةِ جهازٍ يُعاد بناؤها رغمًا عنه.
 */
final class EventIngest
{
    /** ما يُقبل من أنواع. قائمةٌ مغلقة: نوعٌ حرّ يجعل التقرير ضجيجًا. */
    public const TYPES = [
        'page_view',
        'product_view',
        'add_to_cart',
        'checkout_start',
        'search',
        'purchase',
    ];

    /** أقصى ما يُقبل في دفعةٍ واحدة — ضغطٌ عكسيّ عند الباب. */
    public const MAX_BATCH = 20;

    /**
     * هل طلب الزائر ألّا يُقاس؟
     *
     * يُفحص قبل كلّ شيء. و`Sec-GPC` معيارٌ حديث و`DNT` أقدم، والاثنان
     * يُحترمان لأنّ السياسة سمّتهما معًا.
     */
    public function refusesTracking(Request $request): bool
    {
        return $request->header('Sec-GPC') === '1' || $request->header('DNT') === '1';
    }

    /**
     * هل وافق هذا الزائر على القياس؟
     *
     * يُقرأ من `consent_records` — السجلّ القائم بمفرداته، لا جدولٌ ثانٍ ولا
     * كعكةُ موافقةٍ موازية. والأحدث يحسم: موافقةٌ ثمّ سحبٌ تعني سحبًا.
     */
    public function hasAnalyticsConsent(?string $subjectRef): bool
    {
        if ($subjectRef === null || $subjectRef === '') {
            return false;
        }

        $latest = DB::table('consent_records')
            ->where('subject_ref', $subjectRef)
            ->where('purpose', 'ANALYTICS')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first(['granted']);

        return $latest !== null && (bool) $latest->granted;
    }

    /**
     * كتابة دفعة.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{accepted: int, identified: bool, refused: bool}
     */
    public function record(Request $request, array $events, ?string $visitorId, ?string $sessionId): array
    {
        if ($this->refusesTracking($request)) {
            /*
             * «يتوقّف القياس كلّيًا». لا صفّ يُكتب — ولا حتّى بلا معرّف.
             * فالوعد لم يقل «قياسٌ مجهول»، قال «يتوقّف».
             */
            return ['accepted' => 0, 'identified' => false, 'refused' => true];
        }

        $identified = $visitorId !== null && $this->hasAnalyticsConsent($this->hash($visitorId));

        $visitorHash = $identified ? $this->hash($visitorId) : null;
        $sessionHash = $identified && $sessionId !== null ? $this->hash($sessionId) : null;

        $device = $this->device((string) $request->userAgent());
        $now = now();
        $rows = [];

        foreach (array_slice($events, 0, self::MAX_BATCH) as $event) {
            $type = (string) ($event['type'] ?? '');

            if (! in_array($type, self::TYPES, true)) {
                continue;
            }

            $rows[] = [
                'occurred_at' => $now,
                'event_type' => $type,
                'visitor_hash' => $visitorHash,
                'session_hash' => $sessionHash,
                'product_id' => $this->productId($event['productId'] ?? null),
                'order_id' => null,
                /*
                 * القيمة تُقبل من المتصفّح للعرض فقط ولا تُبنى عليها محاسبة:
                 * المال يُقرأ من صفوف الطلبات وحدها. وسالبُها يُسقَط.
                 */
                'value_minor' => $this->positiveInt($event['valueMinor'] ?? null),
                'path' => $this->path($event['path'] ?? null),
                'referrer_host' => $this->referrerHost($event['referrer'] ?? null),
                'device' => $device,
                'is_logged_in' => (bool) ($event['isLoggedIn'] ?? false),
                'meta' => null,
                /*
                 * مفتاح التكرار لا يُكتب إلّا لمعرَّف: بلا زائرٍ لا يمكن
                 * تمييز إعادة إرسالٍ من زيارةٍ ثانية، والقيد الفريد كان
                 * سيُسقط مشاهداتٍ حقيقيّة من زوّارٍ مختلفين.
                 */
                'dedup_key' => $identified ? $this->dedupKey($event, $visitorHash) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return ['accepted' => 0, 'identified' => $identified, 'refused' => false];
        }

        try {
            /*
             * `insertOrIgnore` لا `insert`: مفتاحُ تكرارٍ مصطدم يعني إعادة
             * إرسال، وهي حالةٌ متوقّعة لا خطأ. ورميةٌ هنا كانت تُفقد الدفعة
             * كلّها لأجل صفٍّ مكرّر واحد.
             */
            $accepted = AnalyticsEvent::query()->insertOrIgnore($rows);
        } catch (Throwable $e) {
            /*
             * القياس لا يُسقط المتجر. يُسجَّل ويُمضى — وفقدُ حدثٍ أهون من
             * صفحةٍ تنكسر لأجله.
             */
            Log::warning('analytics.ingest_failed', ['error' => $e->getMessage()]);

            return ['accepted' => 0, 'identified' => $identified, 'refused' => false];
        }

        return ['accepted' => (int) $accepted, 'identified' => $identified, 'refused' => false];
    }

    /**
     * بصمةٌ لا يمكن عكسها إلى الكعكة.
     *
     * مفتاح التطبيق ملحٌ ثابت: بلا ملحٍ تكون البصمة قابلةً للبحث العكسيّ
     * بقاموسٍ من المعرّفات المحتملة.
     */
    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    /** @param array<string, mixed> $event */
    private function dedupKey(array $event, ?string $visitorHash): ?string
    {
        $client = $event['id'] ?? null;

        return is_string($client) && trim($client) !== ''
            ? mb_substr(($visitorHash === null ? '' : mb_substr($visitorHash, 0, 16).':').trim($client), 0, 120)
            : null;
    }

    private function productId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function path(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        /*
         * المسار وحده بلا وسائط استعلام: «؟q=اسم المستخدم» يصل الجدول ويبقى
         * فيه تسعين يومًا. ورمزُ الطلب في `/orders/{token}` يُستبدل قبل القصّ:
         * هو مفتاح الطلب والتنزيل، والطلبات لا تحفظ إلّا بصمته.
         */
        $path = preg_split('/[?#]/u', trim($value), 2)[0] ?? null;

        return is_string($path) ? mb_substr(CredentialPaths::redactPath($path), 0, 200) : null;
    }

    private function referrerHost(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $host = parse_url(trim($value), PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        /*
         * تصغيرٌ وحذفُ `www.`.
         *
         * المضيف غير حسّاسٍ لحالة الأحرف بالمعيار، و`www.google.com` و
         * `google.com` موقعٌ واحد. وتركُهما كما وردا يشقّ المُحيل الواحد إلى
         * سطرين في «إيراد كلّ مُحيل» — فيبدو مصدرٌ واحد مصدرين نصفَ حجمه
         * كلٌّ منهما، وترتيبُ القائمة كلّه يكذب.
         */
        $host = mb_strtolower($host);

        return mb_substr(str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host, 0, 120);
    }

    /**
     * الجهاز من وكيل المستعمل — ثلاث فئاتٍ لا بصمةٌ كاملة.
     *
     * السلسلة كاملةً تعرّف بجهازٍ بعينه؛ وثلاث فئاتٍ تكفي لسؤال «هل الشراء
     * أصعب على الجوّال» ولا تعرّف بأحد.
     */
    private function device(string $userAgent): string
    {
        $ua = mb_strtolower($userAgent);

        return match (true) {
            str_contains($ua, 'ipad') || str_contains($ua, 'tablet') => 'tablet',
            str_contains($ua, 'mobi') || str_contains($ua, 'android') => 'mobile',
            default => 'desktop',
        };
    }
}
