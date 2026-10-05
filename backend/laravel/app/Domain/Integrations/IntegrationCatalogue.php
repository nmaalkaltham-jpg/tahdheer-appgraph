<?php

namespace App\Domain\Integrations;

/**
 * المزوّدون وحقولهم — مجموعةٌ مغلقة يعرفها الخادم.
 *
 * ─── لماذا مغلقة ───
 *
 * حقلٌ حرّ الاسم يعني أنّ الخادم لا يعرف أيّ قيمةٍ سرٌّ وأيّها لا. فيُخزَّن
 * `sk_live_…` ظاهرًا لأنّ من أدخله سمّاه «ملاحظة»، أو يُعمّى معرّفُ تاجرٍ
 * فلا يستطيع المالك قراءته. تصنيفُ السرّيّة قرارُ **الخادم** لا المستعمل.
 *
 * ─── والبادئة تُفحص ───
 *
 * `secretPrefix` يمنع الخلط الأشيع عمليًّا: لصقُ مفتاح النشر في خانة السرّ
 * أو العكس. والخلط الأخطر منه — مفتاح `live` في بيئة `test` — يُفحص كذلك،
 * لأنّه يُحصّل مالًا حقيقيًّا في تجربة.
 */
final class IntegrationCatalogue
{
    /**
     * @var array<string, array{
     *   title: string,
     *   kind: 'payment'|'social'|'analytics'|'storage',
     *   docs: string|null,
     *   fields: array<string, array{label: string, secret: bool, prefix?: string, hint?: string}>
     * }>
     */
    public const PROVIDERS = [
        'moyasar' => [
            'title' => 'ميسر — بوّابة دفع',
            'kind' => 'payment',
            'docs' => 'https://docs.moyasar.com',
            'fields' => [
                'merchant_id' => ['label' => 'معرّف التاجر', 'secret' => false, 'hint' => 'معرّف حساب — ليس سرًّا.'],
                'publishable_key' => ['label' => 'المفتاح العلنيّ', 'secret' => false, 'prefix' => 'pk_', 'hint' => 'يُرسَل إلى متصفّح الزبون — ليس سرًّا.'],
                'secret_key' => ['label' => 'المفتاح السرّيّ', 'secret' => true, 'prefix' => 'sk_'],
                'webhook_secret' => ['label' => 'سرّ توقيع الإشعارات', 'secret' => true],
            ],
        ],
        'tap' => [
            'title' => 'تاب — بوّابة دفع',
            'kind' => 'payment',
            'docs' => 'https://developers.tap.company',
            'fields' => [
                'public_key' => ['label' => 'المفتاح العلنيّ', 'secret' => false, 'prefix' => 'pk_'],
                'secret_key' => ['label' => 'المفتاح السرّيّ', 'secret' => true, 'prefix' => 'sk_'],
                'webhook_secret' => ['label' => 'سرّ توقيع الإشعارات', 'secret' => true],
            ],
        ],
        'meta' => [
            'title' => 'ميتا — فيسبوك وإنستغرام',
            'kind' => 'social',
            'docs' => 'https://developers.facebook.com/docs',
            'fields' => [
                'app_id' => ['label' => 'معرّف التطبيق', 'secret' => false],
                'app_secret' => ['label' => 'سرّ التطبيق', 'secret' => true],
                'page_access_token' => ['label' => 'رمز وصول الصفحة', 'secret' => true],
            ],
        ],
        'x' => [
            'title' => 'إكس (تويتر)',
            'kind' => 'social',
            'docs' => 'https://developer.x.com/en/docs',
            'fields' => [
                'api_key' => ['label' => 'مفتاح الواجهة', 'secret' => false],
                'api_secret' => ['label' => 'سرّ الواجهة', 'secret' => true],
                'access_token' => ['label' => 'رمز الوصول', 'secret' => true],
                'access_token_secret' => ['label' => 'سرّ رمز الوصول', 'secret' => true],
            ],
        ],
        'tiktok' => [
            'title' => 'تيك توك',
            'kind' => 'social',
            'docs' => 'https://developers.tiktok.com/doc',
            'fields' => [
                'app_id' => ['label' => 'معرّف التطبيق', 'secret' => false],
                'app_secret' => ['label' => 'سرّ التطبيق', 'secret' => true],
                'access_token' => ['label' => 'رمز الوصول', 'secret' => true],
            ],
        ],
        'snapchat' => [
            'title' => 'سناب شات',
            'kind' => 'social',
            'docs' => 'https://developers.snap.com',
            'fields' => [
                'client_id' => ['label' => 'معرّف العميل', 'secret' => false],
                'client_secret' => ['label' => 'سرّ العميل', 'secret' => true],
            ],
        ],
        'whatsapp' => [
            'title' => 'واتساب للأعمال',
            'kind' => 'social',
            'docs' => 'https://developers.facebook.com/docs/whatsapp',
            'fields' => [
                'phone_number_id' => ['label' => 'معرّف رقم الهاتف', 'secret' => false],
                'access_token' => ['label' => 'رمز الوصول', 'secret' => true],
            ],
        ],
        'google_analytics' => [
            'title' => 'تحليلات جوجل (GA4)',
            'kind' => 'analytics',
            'docs' => 'https://developers.google.com/analytics',
            'fields' => [
                'measurement_id' => ['label' => 'معرّف القياس', 'secret' => false, 'prefix' => 'G-'],
                'api_secret' => ['label' => 'سرّ الواجهة', 'secret' => true],
            ],
        ],
        /*
         * §١٣ §١٥ — Google Drive مصدرُ ملفّات، لا بوّابةَ دفعٍ ولا تحليلات.
         *
         * كان غائبًا عن هذا السجلّ تمامًا، ومعنى ذلك أنّ `CredentialVault`
         * كان **يرفض** رمز Drive: `assertKnown` ترمي على مزوّدٍ مجهول. أي أنّ
         * المحوّل الكامل لم يكن له موضعٌ يحفظ فيه اعتماده إلّا متغيّر بيئة.
         *
         * والحقول أسرارٌ كلّها عدا المرجع: الرمز والتحديث كلاهما يفتح ملفّات
         * حسابٍ كامل، فيُكتب ولا يُقرأ ثانيةً (القاعدة ٣٤).
         */
        'google_drive' => [
            'title' => 'جوجل درايف — مصدر ملفّات',
            'kind' => 'storage',
            'docs' => 'https://developers.google.com/drive/api/guides/about-sdk',
            'fields' => [
                'account_ref' => [
                    'label' => 'مرجع الحساب',
                    'secret' => false,
                    'hint' => 'اسمٌ يميّز الحساب في اللوحة — ليس سرًّا ولا يُستعمل في النداء.',
                ],
                'client_id' => ['label' => 'معرّف العميل', 'secret' => false, 'hint' => 'معرّف تطبيق OAuth — ليس سرًّا.'],
                'client_secret' => ['label' => 'سرّ العميل', 'secret' => true],
                'refresh_token' => [
                    'label' => 'رمز التجديد',
                    'secret' => true,
                    'hint' => 'يُحصَّل مرّةً من موافقة المالك، ويُجدَّد به رمزُ الوصول تلقائيًّا.',
                ],
            ],
        ],
    ];

    public const ENVIRONMENTS = ['test', 'live'];

    /** @return array<string, array{label: string, secret: bool, prefix?: string, hint?: string}> */
    public static function fields(string $provider): array
    {
        return self::PROVIDERS[$provider]['fields'] ?? [];
    }

    public static function knows(string $provider, string $field): bool
    {
        return isset(self::PROVIDERS[$provider]['fields'][$field]);
    }

    public static function isSecret(string $provider, string $field): bool
    {
        return self::PROVIDERS[$provider]['fields'][$field]['secret'] ?? false;
    }

    /**
     * ما يُعرض للمتصفّح: الحقول وتصنيفها — لا قيمة فيه إطلاقًا.
     *
     * @return list<array<string, mixed>>
     */
    public static function describe(): array
    {
        $out = [];

        foreach (self::PROVIDERS as $key => $provider) {
            $out[] = [
                'provider' => $key,
                'title' => $provider['title'],
                'kind' => $provider['kind'],
                'docs' => $provider['docs'],
                'fields' => array_map(
                    fn (string $field) => [
                        'field' => $field,
                        'label' => $provider['fields'][$field]['label'],
                        'secret' => $provider['fields'][$field]['secret'],
                        'prefix' => $provider['fields'][$field]['prefix'] ?? null,
                        'hint' => $provider['fields'][$field]['hint'] ?? null,
                    ],
                    array_keys($provider['fields']),
                ),
            ];
        }

        return $out;
    }
}
