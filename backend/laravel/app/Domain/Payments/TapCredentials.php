<?php

namespace App\Domain\Payments;

use RuntimeException;

/**
 * عقد بيئة Tap: وضعٌ واحد (test|live) ومفاتيحُه، ولا خلط.
 *
 *   TAP_MODE=test|live
 *   TAP_MERCHANT_ID
 *   TAP_TEST_SECRET_KEY / TAP_TEST_PUBLIC_KEY
 *   TAP_LIVE_SECRET_KEY / TAP_LIVE_PUBLIC_KEY
 *   TAP_WEBHOOK_SECRET (اختياريّ؛ الافتراض المفتاح السرّيّ كما توثّق Tap)
 *
 * ─── لماذا لا يُقبل «مفتاحٌ واحد» ───
 *
 * `TAP_SECRET_KEY` وحده كان يجعل الخلط صامتًا: مفتاح تجربةٍ في الإنتاج
 * «ينجح» ولا يصل مال، ومفتاح إنتاجٍ في التجربة يُحصّل مالًا حقيقيًّا من
 * اختبار. البادئة (`sk_test_`/`sk_live_`) حقيقةٌ يُعلنها المفتاح نفسه، والوضع
 * حقيقةٌ يُعلنها المشغّل — واختلافُهما يُسقط الإقلاع لا يُسجَّل تحذيرًا.
 *
 * المتغيّر القديم يُقبل انتقاليًّا حين تطابق بادئتُه الوضعَ، ولا يسقط إلى
 * الوضع الآخر أبدًا.
 */
final readonly class TapCredentials
{
    public const MODES = ['test', 'live'];

    /**
     * @param  list<string>  $errors  خلطٌ أو نقص — فارغة حين الإعداد متّسق
     */
    public function __construct(
        public string $mode,
        public ?string $merchantId,
        public string $secretKey,
        public ?string $publicKey,
        public string $webhookSecret,
        public array $errors,
        public string $secretSource,
    ) {}

    public static function fromConfig(): self
    {
        $errors = [];
        $tap = (array) config('services.tap', []);

        $mode = mb_strtolower(trim((string) ($tap['mode'] ?? '')));
        $legacyOnly = trim((string) ($tap['secret_key'] ?? ''));
        if ($mode === '') {
            /*
             * بلا TAP_MODE: المفتاح القديم وحده يُعلن بيئته ببادئته (انتقاليًّا)؛
             * وإلّا فالإنتاج live وما عداه test. لا سقوط بين البيئتين هنا:
             * الوضع يُشتقّ من مفتاحٍ واحدٍ صريح لا يُختار من بين اثنين.
             */
            $mode = match (true) {
                str_starts_with($legacyOnly, 'sk_live_') => 'live',
                str_starts_with($legacyOnly, 'sk_test_') => 'test',
                default => app()->environment('production') ? 'live' : 'test',
            };
        }
        if (! in_array($mode, self::MODES, true)) {
            $errors[] = "TAP_MODE «{$mode}» غير معروف — test أو live.";
            $mode = 'test';
        }

        $expected = 'sk_'.$mode.'_';
        $other = 'sk_'.($mode === 'live' ? 'test' : 'live').'_';

        $secret = trim((string) ($tap[$mode]['secret_key'] ?? ''));
        $source = 'TAP_'.mb_strtoupper($mode).'_SECRET_KEY';

        $legacy = trim((string) ($tap['secret_key'] ?? ''));
        if ($secret === '' && $legacy !== '') {
            if (str_starts_with($legacy, $expected)) {
                $secret = $legacy;
                $source = 'TAP_SECRET_KEY (legacy)';
            } else {
                $errors[] = "TAP_SECRET_KEY القديم مفتاحُ بيئةٍ أخرى والوضع «{$mode}» — لا يُستعمل.";
            }
        }

        /*
         * البيئة التشغيليّة تُلزم الوضع: الإنتاج لا يُحصّل بمفاتيح تجربة (يبدو
         * ناجحًا ولا يصل مال)، وما سواه لا يُحصّل بمفاتيح حيّة (مالٌ حقيقيّ من
         * جهاز مطوّر) إلّا بإذنٍ صريح مسمًّى.
         */
        if (app()->environment('production') && $mode !== 'live') {
            $errors[] = 'بيئة الإنتاج لا تعمل بوضع Tap التجريبيّ — TAP_MODE=live.';
        }
        if (! app()->environment('production') && $mode === 'live' && ! (bool) ($tap['allow_live_outside_production'] ?? false)) {
            $errors[] = 'وضع Tap الحيّ خارج الإنتاج يحتاج TAP_ALLOW_LIVE_OUTSIDE_PRODUCTION=true صراحةً.';
        }

        if ($secret !== '' && preg_match('/^sk_(test|live)_[A-Za-z0-9]{6,}$/', $secret) !== 1) {
            $errors[] = 'المفتاح السرّيّ ليس بصيغة مفتاح Tap (sk_test_… أو sk_live_…).';
        }

        if ($secret !== '' && ! str_starts_with($secret, $expected)) {
            $errors[] = str_starts_with($secret, $other)
                ? "الوضع «{$mode}» ومفتاحُه السرّيّ من البيئة الأخرى — خلطٌ بين التجربة والإنتاج."
                : "المفتاح السرّيّ لا يحمل بادئة «{$expected}».";
        }

        $public = trim((string) ($tap[$mode]['public_key'] ?? '')) ?: null;
        if ($public !== null && preg_match('/^pk_(test|live)_[A-Za-z0-9]{6,}$/', $public) !== 1) {
            $errors[] = 'المفتاح العلنيّ ليس بصيغة مفتاح Tap.';
        }
        if ($public !== null && ! str_starts_with($public, 'pk_'.$mode.'_')) {
            $errors[] = "المفتاح العلنيّ لا يطابق الوضع «{$mode}».";
        }

        /*
         * الإنتاج لا يحمل مفتاح تجربةٍ أصلًا — ولا في خانته الخاصّة ولا في
         * الخانة القديمة. وجودُه «غير مستعمل» يعني أنّ سرًّا لا حاجة إليه يعيش
         * في بيئة الإنتاج، وأنّ تبديلًا واحدًا في TAP_MODE يحوّل المتجر إلى
         * التجربة بصمت.
         */
        if (app()->environment('production')) {
            foreach (['test' => $tap['test']['secret_key'] ?? '', 'legacy' => $tap['secret_key'] ?? ''] as $slot => $value) {
                if (str_starts_with(trim((string) $value), 'sk_test_')) {
                    $errors[] = $slot === 'test'
                        ? 'TAP_TEST_SECRET_KEY مضبوط في بيئة الإنتاج — يُزال: الإنتاج يحمل المفتاح الحيّ وحده.'
                        : 'TAP_SECRET_KEY القديم يحمل مفتاح تجربةٍ في بيئة الإنتاج — يُزال.';
                }
            }
        }

        // والمفتاح الآخر لا يجوز أن يكون من الوضع الخطأ ولو لم يُستعمل.
        $otherMode = $mode === 'live' ? 'test' : 'live';
        $otherSecret = trim((string) ($tap[$otherMode]['secret_key'] ?? ''));
        if ($otherSecret !== '' && ! str_starts_with($otherSecret, 'sk_'.$otherMode.'_')) {
            $errors[] = 'TAP_'.mb_strtoupper($otherMode).'_SECRET_KEY يحمل بادئة بيئةٍ أخرى.';
        }

        $webhook = trim((string) ($tap['webhook_secret'] ?? ''));

        /*
         * معرّف التاجر يُرسَل مع كلّ رسم ويُطابَق في كلّ خطّاف. بلا معرّفٍ لا
         * يُعرف أنّ الرسم عند تاجر المتجر — فلا تحصيل.
         */
        $merchant = trim((string) ($tap['merchant_id'] ?? ''));
        if ($secret !== '' && $merchant === '') {
            $errors[] = 'TAP_MERCHANT_ID مطلوب حين يُضبط مفتاح Tap.';
        } elseif ($merchant !== '' && preg_match('/^\d{4,20}$/', $merchant) !== 1) {
            $errors[] = 'TAP_MERCHANT_ID ليس معرّف تاجرٍ رقميًّا.';
        }

        return new self(
            mode: $mode,
            merchantId: $merchant !== '' ? $merchant : null,
            secretKey: $secret,
            publicKey: $public,
            webhookSecret: $webhook !== '' ? $webhook : $secret,
            errors: $errors,
            secretSource: $source,
        );
    }

    /** هل ضُبط أيّ مفتاح Tap أصلًا؟ بلا مفاتيح لا يُفحص شيء (بيئة اختبار). */
    public static function anyConfigured(): bool
    {
        $tap = (array) config('services.tap', []);

        foreach ([$tap['secret_key'] ?? null, $tap['test']['secret_key'] ?? null, $tap['live']['secret_key'] ?? null] as $v) {
            if (trim((string) $v) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function tapIsTheCheckoutProvider(): bool
    {
        return (string) (config('services.payments.provider') ?? 'tap') === 'tap';
    }

    public function isConsistent(): bool
    {
        return $this->errors === [];
    }

    public function configured(): bool
    {
        return $this->secretKey !== '' && $this->isConsistent();
    }

    /**
     * يُنادى عند الإقلاع: إعدادٌ متناقض يُسقط التطبيق قبل أن يُحصّل هللة.
     *
     * @throws RuntimeException
     */
    public static function assertConsistentAtBoot(): void
    {
        /*
         * §١٨ — الإنتاج الذي يُحصّل عبر Tap بلا مفتاح يقبل الطلبات ثمّ لا يستطيع
         * إنشاء رسمٍ ولا التحقّق من خطّاف: كلّ دفعٍ يفشل بعد أن يُنشأ الطلب.
         * يُرفض الإقلاع بدل ذلك.
         */
        if (! self::anyConfigured()) {
            if (app()->environment('production') && self::tapIsTheCheckoutProvider()) {
                throw new RuntimeException('Tap configuration is inconsistent: Tap is the production payment provider but no Tap secret key is configured.');
            }

            return;
        }

        $credentials = self::fromConfig();

        if (! $credentials->isConsistent()) {
            throw new RuntimeException('Tap configuration is inconsistent: '.implode(' ', $credentials->errors));
        }
    }
}
