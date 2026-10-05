<?php

/*
 * محرّك الاستدلال المحلّيّ — الضبط كلّه في موضعٍ واحد.
 *
 * ─── لماذا ملفّ ضبطٍ لا `env()` في الشيفرة ───
 *
 * `env()` تردّ `null` تحت `config:cache`، وهي القاعدة المعلنة في
 * `config/services.php`. كلّ قيمةٍ تُقرأ وقت الطلب تُقرأ من هنا.
 *
 * ─── الحدود ليست اقتراحات ───
 *
 * المتجر أولى بالموارد من الذكاء. القيم أدناه تُطبَّق في ثلاث طبقاتٍ معًا:
 * حدود الحاوية في docker compose، وحدود البوّابة هنا، وحدود llama.cpp نفسه.
 * طبقةٌ واحدة تكفي في الحالة الطبيعيّة، والثلاث تكفي حين تفشل واحدة.
 */

return [

    /*
     * المفتاح الرئيس. إطفاؤه يجعل كلّ قدرةٍ تعود «غير متاحة» صراحةً —
     * لا تعطّلًا صامتًا ولا سقوطًا إلى مزوّدٍ خارجيّ مدفوع.
     */
    'enabled' => (bool) env('AI_ENABLED', false),

    /*
     * راياتٌ لكلّ قدرة. إطفاء واحدةٍ لا يمسّ البقيّة، وإطفاء `enabled`
     * يعلوها جميعًا.
     */
    'features' => [
        'product_enrichment' => (bool) env('AI_PRODUCT_ENRICHMENT', false),
        'search_assist' => (bool) env('AI_SEARCH_ASSIST', false),
        'seo' => (bool) env('AI_SEO', false),
        'ocr_enrichment' => (bool) env('AI_OCR_ENRICHMENT', false),
        'admin_copilot' => (bool) env('AI_ADMIN_COPILOT', false),
        'customer_assistant' => (bool) env('AI_CUSTOMER_ASSISTANT', false),
        'recommendations' => (bool) env('AI_RECOMMENDATIONS', false),
    ],

    'engine' => [
        /*
         * عنوان الخدمة داخل شبكة Docker وحدها. لا يُنشر منفذه، ولا يصله
         * متصفّح: llama.cpp بلا مصادقةٍ ولا تحديد معدّل.
         */
        'base_url' => (string) env('AI_BASE_URL', 'http://ai-llm:8081'),

        'model' => (string) env('AI_MODEL', 'Qwen3.5-2B-Q4_K_M'),

        /*
         * مهلتان لا واحدة. الاتّصال يفشل بسرعة حين تكون الخدمة ساقطة،
         * والقراءة تحتمل طول التوليد. مهلةٌ واحدة تجعل خدمةً ميّتة تحجز
         * العامل ستّين ثانية.
         */
        'connect_timeout' => (float) env('AI_CONNECT_TIMEOUT', 2.0),
        'timeout' => (float) env('AI_TIMEOUT', 60.0),

        /*
         * مهلةٌ أقصر للطلب التفاعليّ: المشرف ينتظر أمام الشاشة، والهدف
         * المعلن p95 دون عشر ثوانٍ. وما يتجاوزها يذهب إلى الطابور.
         */
        'interactive_timeout' => (float) env('AI_INTERACTIVE_TIMEOUT', 12.0),

        /*
         * عقوبتا التكرار — ليستا ضبطًا تجميليًّا.
         *
         * قيس هذا: عند توليد قوائم عباراتٍ عربيّة دخل النموذج حلقةً يكرّر فيها
         * العبارة نفسها حتّى نفاد سقف الرموز، فانقطع JSON في منتصف مصفوفة
         * ورُفض الخرج كلّه. وبالعقوبتين توقّف التوليد عند ١٣٢ رمزًا بخرجٍ سليم.
         *
         * `frequency` تعاقب الرمز بقدر تكراره، و`presence` تعاقب ظهوره أصلًا.
         * الأولى تكسر الحلقة والثانية تدفع إلى التنويع.
         */
        'frequency_penalty' => (float) env('AI_FREQUENCY_PENALTY', 0.7),
        'presence_penalty' => (float) env('AI_PRESENCE_PENALTY', 0.4),

        'context' => (int) env('AI_CONTEXT', 4096),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 800),

        /*
         * سقف المدخل بالمحارف. النافذة ٤٠٩٦ رمزًا، والعربيّة تُرمَّز أكثف من
         * الإنجليزيّة. القصّ هنا يمنع طلبًا يتجاوز النافذة فيُرَدّ بخطأٍ بعد
         * أن استهلك الذاكرة.
         */
        'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 8000),
    ],

    /*
     * الضغط العكسيّ. الطلب الذي يتجاوز الحدّ يُرَدّ فورًا أو يُحوَّل إلى
     * الطابور — ولا يُترك ينتظر، فالانتظار يحجز عامل PHP وهو المورد الشحيح.
     */
    'limits' => [
        /* استدلالٌ متزامنٌ واحد: التوازي على معالجٍ بلا مسرّع يضاعف الزمن ولا يضاعف الإنتاجيّة. */
        'max_concurrent' => (int) env('AI_PARALLEL_REQUESTS', 1),

        /* سقفٌ لكلّ فاعل — يمنع مشرفًا واحدًا من ابتلاع الطاقة كلّها. */
        'per_actor_per_minute' => (int) env('AI_ACTOR_RATE', 20),

        /* طابور المهامّ الخلفيّة: ما يتجاوزه يُرفض عند الجدولة لا عند التنفيذ. */
        'max_queue_depth' => (int) env('AI_MAX_QUEUE_DEPTH', 500),
    ],

    /*
     * قاطع الدائرة. خدمةٌ ساقطة لا تُستدعى في كلّ طلب: الفشل المتكرّر يفتح
     * الدائرة فترتدّ النداءات فورًا، ويُعاد الفتح بعد مهلة.
     */
    'breaker' => [
        'failure_threshold' => (int) env('AI_BREAKER_FAILURES', 5),
        'open_seconds' => (int) env('AI_BREAKER_OPEN_SECONDS', 60),
    ],

    /*
     * تخزين النتائج الحتميّة. المفتاح يحمل المهمّة والنموذج ونسخة الموجّه
     * وبصمة المحتوى واللغة — فتغيّر أيٍّ منها يُبطل تلقائيًّا بلا مسحٍ عامّ.
     *
     * والمهلة محدودة دائمًا: Valkey يعمل بسياسة `noeviction`، فمفتاحٌ بلا
     * مهلة يملأ الذاكرة حتّى تفشل الكتابة على المتجر كلّه لا على الذكاء وحده.
     */
    'cache' => [
        'enabled' => (bool) env('AI_CACHE_ENABLED', true),
        'ttl' => (int) env('AI_CACHE_TTL', 86400),
    ],

    /* اسم الطابور. لا بدّ من إضافته إلى `--queue=` في خدمة العامل وإلّا لم يُستهلك. */
    'queue' => (string) env('AI_QUEUE', 'ai'),
];
