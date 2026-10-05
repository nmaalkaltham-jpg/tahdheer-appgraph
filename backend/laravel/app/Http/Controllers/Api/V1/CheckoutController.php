<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\OrderAttribution;
use App\Domain\Cart\CartService;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Checkout\CheckoutValidation;
use App\Domain\Orders\OrderFinalizer;
use App\Domain\Orders\OrderToken;
use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\ProviderRegistry;
use App\Domain\Payments\ProviderRequery;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CheckoutService $checkout,
        private readonly ProviderRegistry $providers,
        private readonly OrderAttribution $attribution,
        private readonly ProviderRequery $requery,
    ) {}

    /**
     * GET /api/v1/checkout/methods — ما يقبله الدفع فعلًا.
     *
     * `providers` من السجلّ (ما يمكن حلّه في هذه البيئة)، و`applePay` من
     * المزوّد الافتراضيّ وإعداده، و`marks` علاماتُ الدفع التي تُرسم في
     * الصفحة: mada وVISA وMC دائمًا، وApple Pay حين يُقبل، وSTC Pay حين يوجد
     * مزوّدٌ له — ولا يوجد اليوم. عامّ وقابلٌ للتخزين: لا يحمل حالةَ زبون.
     */
    public function methods(): JsonResponse
    {
        $default = $this->providers->default();
        $applePay = $default->supportsApplePay();

        $marks = ['mada', 'VISA', 'MC'];
        if ($applePay) {
            $marks[] = 'Apple Pay';
        }
        if ($this->providers->resolve('stcpay') !== null) {
            $marks[] = 'STC Pay';
        }

        return response()->json([
            'providers' => $this->providers->available(),
            'applePay' => $applePay,
            'marks' => $marks,
        ])->header('Cache-Control', 'public, max-age=300');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(
            [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => ['required', 'string', 'regex:'.CheckoutValidation::SAUDI_PHONE_PATTERN],
                'taxProfile' => 'nullable|array',
                'taxProfile.orgName' => 'required_with:taxProfile|string|max:255',
                'taxProfile.vatNumber' => ['required_with:taxProfile', 'string', 'regex:'.CheckoutValidation::ZATCA_VAT_PATTERN],
                'policyConsent' => 'accepted',
                'marketingOptIn' => 'sometimes|boolean',
                /*
                 * §٢٦ §٣٢ — عنوان الشحن، اختياريٌّ **شكلًا** وإلزاميٌّ بحسب
                 * ما في السلّة.
                 *
                 * إلزامُه هنا كان سيطلب عنوانًا من مشتري ملفٍّ رقميّ — وهو ما
                 * يمنعه §٣٢ صراحةً: جمعُ ما لا يلزم مسألةُ خصوصيّة قبل أن
                 * يكون مسألة تحويل. فالإلزام يقرّره `CheckoutRequirementsResolver`
                 * من استراتيجيّات ما في السلّة، لا هذا الجدول.
                 *
                 * والمدينة وحدها إلزاميّة داخل العنوان: هي ما تحتاجه شركات
                 * الشحن أدنى حدّ، والباقي يُترك فارغًا حين لا يُعرف.
                 */
                'shippingAddress' => 'sometimes|array',
                'shippingAddress.recipientName' => 'sometimes|nullable|string|max:255',
                'shippingAddress.phone' => ['sometimes', 'nullable', 'string', 'regex:'.CheckoutValidation::SAUDI_PHONE_PATTERN],
                'shippingAddress.countryCode' => 'sometimes|string|size:2',
                'shippingAddress.region' => 'sometimes|nullable|string|max:120',
                'shippingAddress.city' => 'required_with:shippingAddress|string|max:120',
                'shippingAddress.district' => 'sometimes|nullable|string|max:120',
                'shippingAddress.street' => 'sometimes|nullable|string|max:255',
                'shippingAddress.buildingNumber' => 'sometimes|nullable|string|max:20',
                'shippingAddress.secondaryNumber' => 'sometimes|nullable|string|max:20',
                'shippingAddress.postalCode' => 'sometimes|nullable|string|max:20',
                'shippingAddress.notes' => 'sometimes|nullable|string|max:500',
                'paymentMethod' => self::PAYMENT_METHOD_RULE,
            ],
            [
                'name.required' => 'الاسم مطلوب',
                'email.required' => 'البريد الإلكتروني مطلوب',
                'email.email' => 'البريد الإلكتروني غير صالح',
                'phone.required' => 'رقم الجوال مطلوب',
                'phone.regex' => 'رقم الجوال يجب أن يكون بصيغة 5XXXXXXXX (بدون 966+)',
                'taxProfile.orgName.required_with' => 'اسم المنشأة مطلوب للفاتورة الضريبية',
                'taxProfile.vatNumber.required_with' => 'الرقم الضريبي مطلوب للفاتورة الضريبية',
                'taxProfile.vatNumber.regex' => 'الرقم الضريبي يجب أن يتكون من 15 رقمًا ويبدأ وينتهي بالرقم 3',
                'policyConsent.accepted' => 'يجب الموافقة على السياسات لإتمام الطلب',
                'shippingAddress.city.required_with' => 'المدينة مطلوبة في عنوان الشحن',
                'shippingAddress.phone.regex' => 'رقم جوال المستلِم يجب أن يكون بصيغة 5XXXXXXXX (بدون 966+)',
                'paymentMethod.in' => self::UNKNOWN_METHOD,
            ],
        );

        // قبل إنشاء الطلب: طلبُ Apple Pay غير المتاح يُرفض ولا يُنشئ طلبًا يتيمًا.
        $method = $this->paymentMethod($data['paymentMethod'] ?? null);

        $cart = $this->cart->resolve($request);
        if ($cart === null) {
            throw ValidationException::withMessages(['cart' => ['السلة فارغة — لا يمكن إنشاء طلب بدون منتجات']]);
        }

        $result = $this->checkout->createOrder($cart, $data);
        $order = $result['order'];

        /*
         * الإسناد بعد وجود الطلب وخارج معاملته.
         *
         * `orders` لا يحمل عمود إسنادٍ واحدًا — وهي الحقيقة البنيويّة التي
         * تجعل «أيّ صفحةٍ أنتجت هذا الإيراد» بلا جواب. وهذا السطر هو الموضع
         * الوحيد الذي يعرف فيه المشروع الطلبَ وبصمةَ زائره معًا.
         *
         * ولا يرمي أبدًا: القياس لا يُبطل بيعًا.
         */
        $this->attribution->record($order, $request);

        return response()->json([
            'orderNumber' => $order->number,
            'orderToken' => $result['token'],
            'payment' => $this->ensureIntent($order, $method),
        ], 201);
    }

    /**
     * POST /api/v1/checkout/orders/{token}/payment — §٢٢ استعادة الدفع.
     *
     * طلبٌ بانتظار الدفع فشلت نيّتُه (أو أُغلقت صفحتُه) كان يبقى ميتًا: لا
     * رابط ولا طريق. هنا تُعاد النيّة القائمة أو تُنشأ بمفتاحها — نيّةٌ واحدة
     * للطلب، لا نيّتان تتنافسان على تمويله.
     */
    public function payment(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(
            ['paymentMethod' => self::PAYMENT_METHOD_RULE],
            ['paymentMethod.in' => self::UNKNOWN_METHOD],
        );
        $method = $this->paymentMethod($data['paymentMethod'] ?? null);

        $order = Order::query()->where('order_key_hash', OrderToken::hash($token))->first();

        if ($order === null) {
            return response()->json(['message' => 'الطلب غير موجود'], 404);
        }

        if (! in_array((string) $order->status, ['PENDING', 'AWAITING_PAYMENT'], true)) {
            return response()->json([
                'message' => 'هذا الطلب لم يعد بانتظار الدفع.',
                'code' => 'ORDER_NOT_PAYABLE',
                'status' => $order->status,
            ], 409);
        }

        /*
         * §١٧ — لا نيّة جديدة فوق مالٍ وصل. تحصيلٌ مسجَّل على طلبٍ لم يُموَّل
         * (فرق مبلغٍ أو عملة) يعني أنّ الزبون دفع؛ رابطٌ ثانٍ يُدفّعه مرّتين.
         */
        if (Payment::query()->where('order_id', $order->id)->where('state', 'CAPTURED')->exists()) {
            return response()->json([
                'message' => 'استلمنا دفعةً على هذا الطلب ولم نتمكّن من مطابقتها تلقائيًّا — لا تدفع مرّةً أخرى؛ سيتواصل معك الفريق.',
                'code' => 'PAYMENT_UNRESOLVED',
                'status' => $order->status,
            ], 409);
        }

        $payment = $this->ensureIntent($order, $method);

        if (($payment['blocked'] ?? null) === 'CONFLICT') {
            return response()->json([
                'message' => 'تغيّر مبلغ هذا الطلب منذ إنشاء رابط الدفع — تواصل معنا ولا تدفع الرابط القديم.',
                'code' => 'IDEMPOTENCY_CONFLICT',
            ], 409);
        }

        if (isset($payment['blocked'])) {
            return $payment['blocked'] === 'PAID'
                ? response()->json([
                    'message' => 'وصلنا دفعُك على هذا الطلب — حدّث الصفحة.',
                    'code' => 'ORDER_NOT_PAYABLE',
                    'status' => $order->fresh()->status,
                ], 409)
                : response()->json([
                    'message' => 'محاولةُ دفعٍ سابقة على هذا الطلب لم تُحسم بعد لدى مزوّد الدفع — لا تدفع مرّةً أخرى الآن، وأعد المحاولة بعد دقائق.',
                    'code' => 'PAYMENT_UNRESOLVED',
                    'status' => $order->status,
                ], 409);
        }

        return response()->json([
            'orderNumber' => $order->number,
            'payment' => $payment,
        ]);
    }

    /** ما يلتزم به مفتاح Tap: الطلب والمبلغ والعملة والمزوّد والطريقة. */
    private static function intentFingerprint(Order $order, string $provider, ?string $method): string
    {
        return hash('sha256', implode('|', [
            $order->id, (int) $order->total_minor, OrderFinalizer::STORE_CURRENCY, $provider, $method ?? 'all',
        ]));
    }

    /**
     * نيّةٌ أخرى قائمة لهذا الطلب قد تُدفع؟ يُسأل المزوّد عنها.
     *
     * @return 'PAID'|'UNRESOLVED'|null
     */
    private function liveSiblingBlocksNewIntent(Order $order, string $key): ?string
    {
        $siblings = Payment::query()
            ->where('order_id', $order->id)
            ->where('state', 'CREATED')
            ->whereNotNull('intent_id')
            ->where('idempotency_key', '!=', $key)
            ->get();

        foreach ($siblings as $sibling) {
            $outcome = $this->requery->requeryCharge($sibling);

            if (in_array($outcome, ['captured', 'unfundable'], true)) {
                return 'PAID';
            }
            if ($outcome === 'unavailable') {
                return 'UNRESOLVED';
            }
            // 'failed' أُغلق، و'open' ما زال مفتوحًا لكنّ الزبون تركه: مسموح.
        }

        return null;
    }

    /** `paymentMethod` اختياريّ؛ القيمة الوحيدة المعروفة Apple Pay. */
    private const PAYMENT_METHOD_RULE = 'sometimes|nullable|in:'.PaymentProvider::METHOD_APPLE_PAY;

    private const APPLE_PAY_UNAVAILABLE = 'الدفع عبر Apple Pay غير متاح حاليًّا';

    private const UNKNOWN_METHOD = 'طريقة الدفع غير معروفة';

    /**
     * يقبل الطريقة المطلوبة أو يرفضها — ولا يسقط إلى غيرها بصمت: من ضغط
     * Apple Pay ثمّ وجد صفحة بطاقة لم يحصل على ما طلب.
     */
    private function paymentMethod(?string $method): ?string
    {
        if ($method === PaymentProvider::METHOD_APPLE_PAY && ! $this->providers->default()->supportsApplePay()) {
            throw ValidationException::withMessages(['paymentMethod' => [self::APPLE_PAY_UNAVAILABLE]]);
        }

        return $method;
    }

    /**
     * نيّة الدفع للطلب: القائمة إن صلحت، وإلّا تُنشأ بمفتاح النيّة نفسه.
     *
     * لكلّ طريقةٍ مفتاحُها: `{provider}:intent:{id}` للصفحة العامّة (كما كان)
     * و`…:apple_pay` لـApple Pay — فالتبديل بينهما لا يعيد رابطَ الطريقة
     * الأخرى. نيّةٌ واحدة لكلّ (طلب، طريقة)، والمال يُطابَق بمعرّف التحصيل.
     *
     * @return array{provider: string, status: string, method: ?string, payUrl?: string}
     */
    private function ensureIntent(Order $order, ?string $method = null): array
    {
        $provider = $this->providers->default();
        $key = "{$provider->name()}:intent:{$order->id}".($method === null ? '' : ":{$method}");
        $payment = ['provider' => $provider->name(), 'status' => 'requires_payment', 'method' => $method];

        $existing = Payment::query()->where('idempotency_key', $key)->first();

        /*
         * §٢٣ — لا رسمٌ جديد فوق رسمٍ قد يُدفع. نيّاتُ الطلب الأخرى القائمة
         * (طريقةٌ أخرى، Apple Pay مقابل الصفحة العامّة) يُسأل عنها المزوّد
         * قبل فتح رسمٍ ثانٍ: مقبوضٌ ⇒ يُموَّل عبر المسار نفسه ولا رسم جديد؛
         * ما زال مفتوحًا عند المزوّد ⇒ مسموح (الزبون ترك تلك الصفحة)؛ لا
         * جواب ⇒ يُرفض الآن ويُعاد لاحقًا — فالمال لا يُؤخذ مرّتين من سهو.
         */
        $blocker = $this->liveSiblingBlocksNewIntent($order, $key);
        if ($blocker !== null) {
            return $payment + ['blocked' => $blocker];
        }

        /*
         * §٦ — المفتاح نفسه يحمل الطلب الماليّ نفسه. نيّةٌ أُنشئت لمبلغٍ أو
         * عملةٍ أو طريقةٍ غير ما يطلبه الطلب الآن لا تُعاد ولا يُعاد مفتاحُها
         * عند Tap (فيعيد الرسم القديم بمبلغه القديم): خطأٌ صريح.
         */
        $fingerprint = self::intentFingerprint($order, $provider->name(), $method);
        if ($existing !== null && $existing->intent_fingerprint !== null && $existing->intent_fingerprint !== $fingerprint
            && in_array($existing->state, ['CREATED', 'INTENT_FAILED'], true)) {
            Log::error('checkout.intent_fingerprint_conflict', ['order_id' => $order->id, 'payment_id' => $existing->id]);

            return $payment + ['blocked' => 'CONFLICT'];
        }

        /*
         * رابطٌ قائم لا يُعاد قبل سؤال Tap عنه (رُصد في الصندوق التجريبيّ
         * ٢٠٢٦-٠٩-٢٥: رسمٌ «مُلغى» عند Tap بقي CREATED عندنا لأنّ خطّافه لم
         * يصل، فكان «ادفع الآن» يعيد الزبون إلى صفحة دفعٍ ميتة). مقبوضٌ ⇒
         * يُموَّل عبر المُنهي نفسه ولا دفع ثانٍ؛ منتهٍ بلا مال ⇒ رسمٌ جديد
         * بمحاولةٍ جديدة؛ مفتوح أو لا جواب ⇒ يُعاد كما هو.
         */
        if ($existing !== null && $existing->state === 'CREATED' && $existing->intent_id !== null) {
            $outcome = $this->requery->requeryCharge($existing);
            if (in_array($outcome, ['captured', 'unfundable'], true)) {
                return $payment + ['blocked' => 'PAID'];
            }
            $existing->refresh();
        }

        if ($existing !== null && $existing->state === 'CREATED' && $existing->intent_id !== null) {
            if ($existing->pay_url !== null) {
                $payment['payUrl'] = $existing->pay_url;
            }
            // العودة إلى طريقةٍ سابقة تجعلها الأحدث: صفحة الطلب تعرض آخر ما اختير.
            $existing->touch();

            return $payment;
        }

        /*
         * رقمُ المحاولة يدخل مفتاح Tap (`reference.idempotent`): المهلةُ
         * بعد قبول Tap ثمّ الإعادة تعيد الرسم نفسه. ولا يزيد إلّا بعد فشلٍ
         * مؤكَّد للرسم السابق — فرسمٌ ميت لا يُعاد، ورسمٌ حيّ لا يُكرَّر.
         */
        $attempt = (int) ($existing?->intent_attempt ?? 1);
        if ($existing !== null && $existing->state === 'FAILED') {
            $attempt++;
        }
        $providerKey = "tahdheer:{$key}:{$attempt}";

        try {
            $intent = $provider->createIntent($order, $method, $providerKey);

            /*
             * الرسم السابق يُستبدل على الصفّ نفسه (مفتاح النيّة واحد)، فتُحفظ
             * هويّتُه وحالتُه حدثًا قبل الاستبدال: أثرُ كلّ رسمٍ أُنشئ للطلب
             * يبقى قابلًا للتتبّع في التسوية والنزاع.
             */
            if ($existing !== null && $existing->intent_id !== null && $existing->intent_id !== $intent->intentId) {
                PaymentEvent::query()->create([
                    'payment_id' => $existing->id,
                    'event_type' => 'intent.replaced',
                    'payload' => [
                        'previous_charge' => $existing->intent_id,
                        'previous_state' => $existing->state,
                        'previous_attempt' => (int) $existing->intent_attempt,
                        'new_charge' => $intent->intentId,
                        'new_attempt' => $attempt,
                    ],
                ]);
            }

            Payment::query()->updateOrCreate(
                ['idempotency_key' => $key],
                [
                    'order_id' => $order->id,
                    'provider' => $provider->name(),
                    'intent_id' => $intent->intentId,
                    'pay_url' => $intent->payUrl,
                    'state' => 'CREATED',
                    'intent_attempt' => $attempt,
                    'intent_fingerprint' => $fingerprint,
                ],
            );
            if ($intent->payUrl !== null) {
                $payment['payUrl'] = $intent->payUrl;
            }
        } catch (Throwable $e) {
            // Order stands; the client may retry payment. payUrl stays absent — honest state.
            Log::error('checkout.intent_failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            /*
             * والفشل يُدوَّن: بلا صفٍّ لا يعرف أحدٌ أنّ نيّةً حاوَلت. المفتاح
             * مفتاحُ النيّة نفسه فتعيد المحاولة الناجحة كتابته لا مضاعفته.
             */
            Payment::query()->updateOrCreate(
                ['idempotency_key' => $key],
                ['order_id' => $order->id, 'provider' => $provider->name(), 'state' => 'INTENT_FAILED', 'intent_attempt' => $attempt, 'intent_fingerprint' => $fingerprint],
            );
        }

        return $payment;
    }
}
