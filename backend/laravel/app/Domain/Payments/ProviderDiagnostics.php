<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use Illuminate\Support\Facades\DB;

/**
 * حالة مزوّد الدفع كما هي فعلًا (§٤٣).
 *
 * ─── لماذا «المضبوط» و«المحلول» حقلان ───
 *
 * `PAYMENTS_PROVIDER=fake` في الإنتاج يجعل `resolve` يُعيد `null` فيسقط
 * الاختيار إلى Tap الحيّ — أي أنّ **الإعداد يقول وهميّ والذي يقبض حقيقيّ**.
 * حقلٌ واحد لا يسع الحقيقتين، وإخفاءُ الفرق يجعل المشغّل يظنّ أنّه في وضع
 * تجربة بينما مالٌ حقيقيّ يتحرّك.
 *
 * ─── لماذا لا يخرج سرٌّ من هنا ───
 *
 * الغرض إثبات **وجود** الاعتماد لا كشفه: بادئةٌ وأربعة أحرف أخيرة تكفي
 * لتمييز مفتاحٍ عن آخر وللتأكّد من أنّ الحيّ ليس في مكان التجريبيّ. وطباعةُ
 * المفتاح كاملًا في شاشةٍ إداريّة تضعه في لقطات الشاشة وسجلّات المتصفّح.
 */
final class ProviderDiagnostics
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $configured = (string) (config('services.payments.provider')
            ?? (app()->environment('production') ? 'tap' : 'fake'));

        $resolved = $this->registry->resolve($configured);
        $fellBack = $resolved === null;

        return [
            'providerResolution' => [
                'configured' => $configured,
                'resolved' => $fellBack ? 'tap' : $configured,
                'fellBack' => $fellBack,
                'reason' => $this->reason($configured, $fellBack),
                'mode' => $this->mode($fellBack ? 'tap' : $configured),
                'configuredCredentials' => $this->tapConfigured(),
            ],
            'appEnv' => (string) app()->environment(),
            'tap' => $this->tap(),
            'recent' => $this->recent(),
        ];
    }

    private function reason(string $configured, bool $fellBack): string
    {
        if (! $fellBack) {
            return 'configured';
        }

        // «ممنوع هنا» ليس «غير معروف»: الأوّل إعدادٌ خاطئ والثاني خطأٌ مطبعيّ.
        return in_array($configured, ['fake'], true) ? 'forbidden_in_environment' : 'unknown_provider';
    }

    private function mode(string $provider): string
    {
        if ($provider !== 'tap') {
            return 'sandbox';
        }

        $secret = (string) config('services.tap.secret_key');

        // Tap يميّز الحيّ من التجريبيّ ببادئة المفتاح نفسه.
        return str_starts_with($secret, 'sk_live') ? 'live' : 'test';
    }

    private function tapConfigured(): bool
    {
        return TapCredentials::anyConfigured();
    }

    /** @return array<string, mixed> */
    private function tap(): array
    {
        $credentials = TapCredentials::fromConfig();
        $secret = $credentials->secretKey;
        $webhook = (string) config('services.tap.webhook_secret');

        $errors = [];
        $warnings = [];

        foreach ($credentials->errors as $i => $error) {
            // خلطُ البيئات: يُعرض هنا ويُسقط الإقلاع أصلًا.
            $errors['isolation_'.$i] = $error;
        }

        if ($secret === '' && $credentials->isConsistent()) {
            // بلا مفتاح لا تحصيل ولا تحقّق من توقيع — كلّ إشعارٍ يُرفض.
            $errors['secret_key'] = 'مفتاح Tap السرّيّ غير مضبوط — لا تحصيل ولا تحقّق من التوقيع.';
        }

        if ($webhook === '' && $secret !== '') {
            $warnings['webhook_secret'] = 'لا سرّ خطّاف مستقلّ — يُستعمل المفتاح السرّيّ للتحقّق.';
        }

        if (app()->environment('production') && $secret !== '' && ! str_starts_with($secret, 'sk_live')) {
            // مفتاح تجربةٍ في الإنتاج: الطلبات «تنجح» ولا يصل مال.
            $errors['mode'] = 'مفتاح تجريبيّ في بيئة إنتاج — لا مال حقيقيّ يُحصَّل.';
        }

        return [
            'mode' => $this->mode('tap'),
            'configured' => $secret !== '',
            'problems' => ['errors' => $errors, 'warnings' => $warnings],
            'credentials' => [
                'merchantId' => $credentials->merchantId,
                'tapMode' => $credentials->mode,
                'secretKey' => $this->hint($secret),
                'secretSource' => $credentials->secretSource,
                'publicKey' => $credentials->publicKey === null ? null : $this->hint($credentials->publicKey),
                'webhookSecret' => $this->hint($webhook),
                // حالة كلّ بيئة على حدة — بلا قيم.
                'testSecret' => trim((string) config('services.tap.test.secret_key')) !== '' ? 'configured' : 'missing',
                'liveSecret' => trim((string) config('services.tap.live.secret_key')) !== '' ? 'configured' : 'missing',
                'hasTestSecret' => $secret !== '' && ! str_starts_with($secret, 'sk_live'),
                'hasLiveSecret' => str_starts_with($secret, 'sk_live'),
                'webhookSecretSource' => $webhook === '' ? 'secret_key' : 'dedicated',
            ],
            'webhookUrl' => url('/api/v1/payments/webhook/tap'),
            'baseUrl' => (string) config('services.tap.base_url'),
            'frontendUrl' => config('services.tap.frontend_url'),
        ];
    }

    /**
     * أثرٌ يُثبت الوجود ولا يكشف السرّ.
     *
     * البادئة تفرّق الحيّ من التجريبيّ، والأحرف الأربعة الأخيرة تفرّق مفتاحًا
     * عن آخر عند التدوير. وما بينهما لا يُعرض.
     */
    private function hint(string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }

        if (mb_strlen($secret) <= 8) {
            return str_repeat('•', mb_strlen($secret));
        }

        return mb_substr($secret, 0, 7).'…'.str_repeat('•', 4).mb_substr($secret, -4);
    }

    /** @return array<string, mixed> */
    private function recent(): array
    {
        $since = now()->subDay();

        $byStatus = DB::table('webhook_deliveries')
            ->where('received_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $lastFailure = DB::table('webhook_deliveries')
            ->where('status', 'FAILED')
            ->orderByDesc('received_at')
            ->first(['received_at', 'charge_id', 'status', 'last_error']);

        return [
            'captured24h' => (int) ($byStatus['PROCESSED'] ?? 0),
            'failed24h' => (int) ($byStatus['FAILED'] ?? 0),
            'ignored24h' => (int) ($byStatus['IGNORED'] ?? 0),
            /*
             * تحصيلان على طلبٍ واحد يُقرآن من المدفوعات لا من التسليمات:
             * التسليم المكرّر أمرٌ عاديّ يردّه القيد، والتحصيل المزدوج خللٌ
             * ماليّ. الخلط بينهما يُنتج إنذارًا يوميًّا بلا سبب.
             */
            'duplicateCaptures' => (int) DB::table(DB::raw(
                "(select order_id from payments where state = 'CAPTURED' group by order_id having count(*) > 1) as d"
            ))->count(),
            'lastWebhookAt' => DB::table('webhook_deliveries')->max('received_at'),
            'lastFailure' => $lastFailure === null ? null : [
                'at' => (string) $lastFailure->received_at,
                'chargeId' => $lastFailure->charge_id,
                'status' => $lastFailure->status,
                'code' => null,
                'message' => $lastFailure->last_error,
            ],
        ];
    }
}
