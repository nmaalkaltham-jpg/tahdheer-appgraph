<?php

return [

    /*
     * المساعد الكتابي. `provider` فارغ يعني «غير مهيَّأ» صراحةً: الواجهة تعرض
     * NOT_CONFIGURED ولا تتلقّى نصًّا مُلفَّقًا.
     */
    'writing' => [
        'provider' => env('WRITING_PROVIDER', ''),
        'key' => env('WRITING_API_KEY', ''),
        'model' => env('WRITING_MODEL', 'claude-sonnet-5'),
        'timeout' => (int) env('WRITING_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * مفاتيح كانت تُقرأ بـ env() في وقت التشغيل ولا وجود لها هنا إطلاقًا.
     *
     * `php artisan config:cache` — وهو المعتاد في الإنتاج — يجعل env() تُعيد
     * null، فتصير هذه القيم فارغة بلا أي خطأ:
     *   · services.tap.*         ⇒ TapProvider::verifyWebhook يرفض كل إشعار
     *     دفع وارد (يرفض المفتاح الفارغ صراحةً، وهذا صحيح) ⇒ لا طلب يُموَّل.
     *   · services.meilisearch.* ⇒ البحث يقصد المضيف الافتراضي المحلي.
     *   · services.payments.provider ⇒ يسقط على المزوّد الوهمي.
     *
     * القراءة هنا تحدث وقت بناء الإعداد لا وقت الطلب، فتنجو من التخزين.
     */
    'payments' => [
        'provider' => env('PAYMENTS_PROVIDER'),
    ],

    'tap' => [
        'base_url' => env('TAP_BASE_URL', 'https://api.tap.company/v2'),
        /*
         * عقد البيئة (انظر TapCredentials): وضعٌ واحد ومفاتيحُه. المفتاح
         * القديم `TAP_SECRET_KEY` يُقبل انتقاليًّا حين تطابق بادئتُه الوضع.
         */
        'mode' => env('TAP_MODE'),
        'merchant_id' => env('TAP_MERCHANT_ID'),
        // الوضع الحيّ خارج الإنتاج (خادم تجهيزٍ بمالٍ حقيقيّ) يحتاج إذنًا مسمًّى.
        'allow_live_outside_production' => (bool) env('TAP_ALLOW_LIVE_OUTSIDE_PRODUCTION', false),
        'test' => [
            'secret_key' => env('TAP_TEST_SECRET_KEY'),
            'public_key' => env('TAP_TEST_PUBLIC_KEY'),
        ],
        'live' => [
            'secret_key' => env('TAP_LIVE_SECRET_KEY'),
            'public_key' => env('TAP_LIVE_PUBLIC_KEY'),
        ],
        'secret_key' => env('TAP_SECRET_KEY'),
        'webhook_secret' => env('TAP_WEBHOOK_SECRET'),
        'frontend_url' => env('FRONTEND_URL'),
        // Apple Pay يُفعَّل في حساب Tap أوّلًا ثمّ يُعلَن هنا — الافتراض «لا».
        'apple_pay' => (bool) env('TAP_APPLE_PAY', false),
    ],

    'meilisearch' => [
        /*
         * الاسم الذي يمرّره docker-compose أوّلًا.
         *
         * الشيفرة كانت تقرأ MEILI_HOST بينما compose يمرّر MEILISEARCH_HOST،
         * فكان البحث في الإنتاج يقصد 127.0.0.1:7700 — أي داخل حاوية الـAPI
         * نفسها حيث لا شيء يستمع. عطلٌ صامت: لا خطأ إعداد، فقط بحث لا يجد.
         * يُقبل الاسمان حتى لا ينكسر تشغيل محلّي يستعمل القديم.
         */
        'host' => env('MEILISEARCH_HOST', env('MEILI_HOST', 'http://127.0.0.1:7700')),
        'key' => env('MEILISEARCH_KEY', env('MEILI_MASTER_KEY')),
    ],

    /*
     * Google Drive كمحوّل مصدرٍ للأصول (§١٣ §١٥).
     * فارغٌ افتراضًا: المحوّل يفشل مغلقًا بلا اعتماد ولا يتظاهر بأنّ الملفّات
     * اختفت. `account_ref` مرجعٌ للعرض لا سرّ.
     */
    'google_drive' => [
        'access_token' => env('GOOGLE_DRIVE_ACCESS_TOKEN'),
        'account_ref' => env('GOOGLE_DRIVE_ACCOUNT_REF'),
    ],
];
