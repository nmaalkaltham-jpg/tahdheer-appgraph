<?php

namespace App\Domain\Content;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Editorial homepage content, stored in the EXISTING `settings` table.
 *
 * Why settings and not a new `home_content` table: `settings` is already
 * (key unique, value json nullable, timestamps) — byte for byte the shape a
 * `home_content` table would have had — and the codebase already namespaces
 * keys by domain (`seo.site_url`, `seo.routes.*`, see App\Domain\SEO). A second
 * table with an identical schema would add a migration, a model and a second
 * access path for zero capability. So homepage content lives under `home.*`.
 *
 * HONESTY: this class only READS and NORMALIZES what an editor configured. It
 * has no defaults and no fallback copy: a key that was never written yields
 * null / [], and the endpoint answers empty. A configured value that is blank
 * or of the wrong type is dropped rather than rendered — nothing here can
 * produce a string that nobody typed.
 */
final class HomeContent
{
    public const HERO = 'home.hero';

    public const CTA = 'home.cta';

    /** شريط الإعلانات أعلى الترويسة — رسائل قصيرة ورابطٌ ختاميّ وهيئةٌ اختياريّة. */
    public const ANNOUNCEMENTS = 'home.announcements';

    /**
     * هويّة المتجر في التذييل — وثيقة العمل الحرّ وحسابات التواصل.
     *
     * المفتاح تحت `home.` لا `store.`: لوحة التحرير تكتب `home.{block}` حصرًا،
     * ومفتاحٌ خارج البادئة كتلةٌ لا يستطيع المالك حفظها.
     */
    public const IDENTITY = 'home.identity';

    public const WHY = 'home.why';

    public const BENEFITS = 'home.benefits';

    public const FAQ = 'home.faq';

    /** Which real coupon (by code) the store chose to advertise on the homepage. */
    public const PROMO_COUPON = 'home.promo_coupon';

    /** سطر الحملة القصير («خصم ٢٥٪ — بداية الفصل») — نصُّ المالك فوق الحملة الحيّة. */
    public const PROMO = 'home.promo';

    /** ترتيب أقسام الرئيسة وتفعيلها — قرارُ المالك لا ثابتٌ في الشيفرة. */
    public const LAYOUT = 'home.layout';

    /**
     * شرائح الهيرو المتحرّك — قائمةٌ يرتّبها المالك (عقد الهيرو ٢٠٢٦-٠٩-٢٥ §1).
     *
     * صفٌّ واحد في `settings` كما تُخزَّن `why`/`faq` قوائمَ: السجلّ يحمل
     * القوائم أصلًا، فلا جدول جديد لثماني شرائح على الأكثر.
     */
    public const HERO_SLIDES = 'home.hero_slides';

    /** إعدادات حركة الهيرو — كائنٌ واحد، وكلّ مفتاحٍ غائب يقع على افتراضه. */
    public const HERO_SLIDER = 'home.hero_slider';

    public const SLIDES_MAX = 8;

    public const SLIDE_CHIPS_MAX = 6;

    /** المفردات المغلقة — والقيمة الأولى في كلٍّ منها هي الافتراض. */
    public const SLIDE_KINDS = ['brand', 'offer', 'search', 'image'];

    public const SLIDE_THEMES = ['navy-teal', 'navy', 'teal', 'sand', 'mint', 'ink'];

    public const SLIDE_ALIGNS = ['start', 'center'];

    public const SLIDE_OVERLAYS = ['soft', 'none', 'strong'];

    public const SLIDE_FOCALS = ['center', 'top', 'bottom'];

    public const SLIDER_TRANSITIONS = ['slide', 'fade'];

    public const SLIDER_HEIGHTS = ['standard', 'compact', 'tall'];

    public const SLIDER_AMBIENTS = ['drift', 'none', 'kenburns'];

    public const SLIDER_INTERVAL_MIN = 3000;

    public const SLIDER_INTERVAL_MAX = 15000;

    /** افتراضات `slider` كما يسردها العقد — وهي ما يصل المتجرَ قبل أيّ حفظ. */
    public const SLIDER_DEFAULTS = [
        'autoplay' => true,
        'intervalMs' => 6000,
        'transition' => 'slide',
        'continuous' => true,
        'pauseOnHover' => false, // الهيرو لا يتوقّف عند التحويم افتراضيًّا — طلب المالك ٢٠٢٦-٠٩-٢٥؛ زرّ الإيقاف يبقى (WCAG 2.2.2)
        'showArrows' => true,
        'showDots' => true,
        'showProgress' => true,
        'height' => 'standard',
        'ambient' => 'drift',
    ];

    /**
     * أطوال النصوص القصوى. العقد يحدّ العنوان (١٢٠) والنصّ (٢٤٠)، والباقي
     * بمقاس ما يتّسع له مكانه في الشريحة. والزائد يُقتطع ولا يُرفض.
     */
    public const SLIDE_LIMITS = [
        'title' => 120,
        'subtitle' => 240,
        'eyebrow' => 60,
        'titleAccent' => 80,
        'label' => 40,
        'href' => 500,
        'alt' => 160,
        'badge' => 40,
        'couponCode' => 40,
        'placeholder' => 80,
    ];

    /**
     * مفردات أقسام الرئيسة — مرآةُ `HOMEPAGE_SECTION_IDS` في المتجر.
     *
     * والنسختان تتطابقان لزومًا: مُعرِّفٌ هنا بلا مُصيِّر هناك
     * قسمٌ يختاره المالك ولا يظهر له شيء، وهو عطلٌ صامت تمامًا.
     *
     * و`hero` تعني «يحمل `h1`»: قسمان منها معًا يضعان عنوانين رئيسين في
     * صفحةٍ واحدة — والكاتب يرفض ذلك، لأنّه خرقُ بنيةٍ لا ذوقُ تصميم.
     *
     * @var array<string, array{label: string, hero: bool}>
     */
    public const SECTIONS = [
        'hero' => ['label' => 'الهيرو', 'hero' => true],
        'smart-finder' => ['label' => 'هيرو الوصول السريع', 'hero' => true],
        'stages-explorer' => ['label' => 'ابدأ من مرحلتك', 'hero' => false],
        'lesson-bundle' => ['label' => 'حزمة الدرس', 'hero' => false],
        'curriculum-navigator' => ['label' => 'متصفّح المناهج', 'hero' => false],
        'product-types' => ['label' => 'أنواع المحتوى', 'hero' => false],
        'popular-by-grade' => ['label' => 'الأكثر طلبًا لصفّك', 'hero' => false],
        'trust' => ['label' => 'شريط الثقة', 'hero' => false],
        'stages' => ['label' => 'المراحل', 'hero' => false],
        'featured' => ['label' => 'منتجات مختارة', 'hero' => false],
        'subjects' => ['label' => 'المواد', 'hero' => false],
        'best' => ['label' => 'الأكثر مبيعًا', 'hero' => false],
        /* كلّ الملفّات المنشورة مُرقّمةً من GET /api/v1/search — مسار أرشيف المتجر نفسه. */
        'all-products' => ['label' => 'كل المنتجات', 'hero' => false],
        'samples' => ['label' => 'العيّنات المجّانية', 'hero' => false],
        'promo' => ['label' => 'الحملة والقسيمة', 'hero' => false],
        'why' => ['label' => 'لماذا نحن', 'hero' => false],
        'benefits' => ['label' => 'المزايا', 'hero' => false],
        'reviews' => ['label' => 'التقييمات', 'hero' => false],
        'faq' => ['label' => 'الأسئلة الشائعة', 'hero' => false],
        'cta' => ['label' => 'الدعوة الختاميّة', 'hero' => false],
    ];

    /**
     * Optional hero fields, in the order the storefront reads them.
     *
     * ─── الحقول الستّة الأخيرة: هيئةُ الهيرو لا نصُّه ───
     *
     * للمتجر ثلاثة تصاميم هيرو لا واحد — «سينمائيّ» و«اكتشاف» و«حكاية
     * المعلّم» — ويختار المالك بينها بـ`variant` بدل أن يُفرض واحدٌ في
     * الشيفرة. ومعها ما يحتاجه كلٌّ منها: وسيطٌ مُحرَّر (`media_url`
     * و`media_alt`)، وشدّةُ الحجاب فوقه (`overlay`)، ومحاذاةُ النصّ
     * (`align`)، وأسلوبُ الحركة (`animation`).
     *
     * وكلّها تُخزَّن نصًّا ويُطبّعها هذا الصفّ كنصّ — فالتحقّق من المفردات
     * يقع في المتجر لا هنا، على القاعدة نفسها التي يمشي عليها `layout`:
     * قيمةٌ لا يعرفها المُصيِّر تُسقَط ويقع على افتراضه، فلا تكسر قيمةٌ
     * قديمة أو مطبعةٌ في اللوحة صفحةً كاملة.
     *
     * وغيابُها كلّها هو الحال الأوّل: هيرو مضبوطٌ قبل هذه الإضافة يبقى
     * عاملًا بلا تغيير حرف، ويقع على «السينمائيّ» وهو تصميمُه نفسه.
     */
    /** تصاميم الهيرو التي يعرفها المتجر اليوم — والقديمة تُقرأ `slider`. */
    public const HERO_VARIANTS = ['slider', 'institutional', 'bold', 'luxe', 'brand'];

    public const HERO_LEGACY_VARIANTS = ['cinematic' => 'slider', 'discovery' => 'slider', 'story' => 'slider'];

    private const HERO_FIELDS = [
        'title_accent', 'subtitle', 'eyebrow',
        'cta_label', 'cta_url', 'cta_secondary_label', 'cta_secondary_url',
        'variant', 'media_url', 'media_alt', 'overlay', 'align', 'animation',
    ];

    /** الزرّ الثانويّ حقلان اختياريّان — يُعرضان في اللوحة من هذا السجلّ نفسه. */
    private const CTA_FIELDS = ['subtitle', 'cta_label', 'cta_url', 'cta_secondary_label', 'cta_secondary_url'];

    /**
     * حقول شريط الإعلانات — نصوصٌ كلّها في السجلّ، والقارئ يُطبّع ما له
     * مفرداتٌ (النمط، الألوان، الارتفاع). ثلاث رسائل على الأكثر: الشريط سطرٌ
     * واحد، وما زاد لا يُقرأ.
     */
    public const ANNOUNCEMENT_MODES = ['static', 'marquee'];

    /** شبكات التواصل بترتيب عرضها وأسمائها العربيّة — المفتاح هو حقل الرابط. */
    public const SOCIAL_NETWORKS = [
        'x' => 'إكس',
        'instagram' => 'إنستغرام',
        'whatsapp' => 'واتساب',
        'tiktok' => 'تيك توك',
        'snapchat' => 'سناب شات',
    ];

    private const IDENTITY_FIELDS = [
        'document_label', 'document_number',
        'social_whatsapp_url', 'social_instagram_url', 'social_x_url', 'social_tiktok_url', 'social_snapchat_url',
    ];

    private const ANNOUNCEMENTS_FIELDS = [
        'announcements_mode',
        'announcement_1', 'announcement_2', 'announcement_3',
        'announcements_link_label', 'announcements_link_url',
        'announcements_bg', 'announcements_fg', 'announcements_height',
    ];

    /**
     * سجلّ الكتل — مصدرٌ واحد يقرأ منه القارئ والكاتب ولوحة التحرير.
     *
     * قبله كانت قائمةُ الحقول معرفةً هنا وحدها (`private const`)، فأيّ كاتبٍ
     * أو شاشةٍ تحرير تنسخها. ونسختان لقائمةٍ واحدة تنحرفان عند أوّل حقلٍ
     * يُضاف: يُقبل في الكتابة ولا يُقرأ، أو يُعرض في اللوحة ولا يُحفظ.
     *
     * `kind` يقرّر شكل الحمولة لا اسمها:
     *   · `block` — كائنٌ بعنوانٍ إلزاميّ وحقولٍ نصّيّة اختياريّة.
     *   · `cards` — قائمةُ {عنوان، وصفٌ اختياريّ}.
     *   · `faq`   — قائمةُ {سؤال، جواب}، وكلاهما إلزاميّ.
     *   · `coupon`— رمزُ قسيمةٍ واحد.
     *
     * @return array<string, array{kind: string, fields?: list<string>, trust?: bool, title?: bool, settings?: array<string, mixed>, max?: int, chipsMax?: int, limits?: array<string, int>, slide?: array<string, mixed>}>
     */
    public static function blocks(): array
    {
        return [
            self::HERO => [
                'kind' => 'block',
                'fields' => ['title', ...self::HERO_FIELDS],
                'trust' => true,
            ],
            self::CTA => [
                'kind' => 'block',
                'fields' => ['title', ...self::CTA_FIELDS],
                'trust' => false,
            ],
            /*
             * بلا عنوان: الشريط لا يحمل عنوانًا، و`title: false` يُعفيه من شرط
             * الكتلة — في القارئ والكاتب معًا — واللوحة ترسم حقوله من هنا.
             */
            self::ANNOUNCEMENTS => [
                'kind' => 'block',
                'fields' => self::ANNOUNCEMENTS_FIELDS,
                'trust' => false,
                'title' => false,
            ],
            self::IDENTITY => [
                'kind' => 'block',
                'fields' => self::IDENTITY_FIELDS,
                'trust' => false,
                'title' => false,
            ],
            self::WHY => ['kind' => 'cards'],
            self::BENEFITS => ['kind' => 'cards'],
            self::FAQ => ['kind' => 'faq'],
            self::PROMO_COUPON => ['kind' => 'coupon'],
            self::PROMO => ['kind' => 'block', 'fields' => ['badge'], 'trust' => false, 'title' => false],
            self::LAYOUT => ['kind' => 'layout'],
            /*
             * `slides` نوعٌ جديد: قائمةٌ حقولُها تتبع نوعَ كلّ شريحة. واللوحة
             * ترسم محرّرها من `slide` هنا — التسميات والإلزاميّ والمفردات —
             * فلا تنحرف نسخةٌ في الواجهة عن قواعد الكاتب.
             */
            self::HERO_SLIDES => [
                'kind' => 'slides',
                'max' => self::SLIDES_MAX,
                'chipsMax' => self::SLIDE_CHIPS_MAX,
                'limits' => self::SLIDE_LIMITS,
                'slide' => self::slideSchema(),
            ],
            /* `block` بلا عنوان، و`settings` يصف أنواع حقوله (مفتاح/عدد/قائمة). */
            self::HERO_SLIDER => [
                'kind' => 'block',
                'fields' => array_keys(self::SLIDER_DEFAULTS),
                'trust' => false,
                'title' => false,
                'settings' => self::sliderSchema(),
            ],
        ];
    }

    /**
     * وصفُ حقول الشريحة للّوحة: لكلّ نوعٍ تسميةٌ وسطرُ وصفٍ وقائمةُ حقوله،
     * ولكلّ حقلٍ تسميتُه ونوعُ مدخله وحدُّه وإلزاميّته ومفرداته.
     *
     * @return array{kinds: list<array{value: string, label: string, description: string, fields: list<string>}>, fields: array<string, array<string, mixed>>}
     */
    public static function slideSchema(): array
    {
        $text = ['eyebrow', 'title', 'titleAccent', 'subtitle'];
        $look = ['theme', 'align', 'media', 'overlay'];

        return [
            'kinds' => [
                ['value' => 'brand', 'label' => 'العلامة', 'description' => 'رسالة المتجر الأساسيّة مع بطاقة الأكثر مبيعًا بجانبها.',
                    'fields' => [...$text, 'primary', 'secondary', 'showProductCard', ...$look]],
                ['value' => 'offer', 'label' => 'عرض', 'description' => 'حملةٌ أو خصم بشارةٍ وموعد انتهاء ورمز قسيمة.',
                    'fields' => [...$text, 'primary', 'secondary', 'offer', ...$look]],
                ['value' => 'search', 'label' => 'بحث', 'description' => 'مربّع بحث بارز مع اختصاراتٍ سريعة للمراحل والمواد.',
                    'fields' => [...$text, 'search', ...$look]],
                ['value' => 'image', 'label' => 'صورة', 'description' => 'صورةٌ كاملة العرض بعنوانٍ وحجابٍ فوقها.',
                    'fields' => [...$text, 'primary', 'secondary', ...$look]],
            ],
            'fields' => [
                'eyebrow' => ['label' => 'السطر العلويّ', 'type' => 'text', 'max' => self::SLIDE_LIMITS['eyebrow']],
                'title' => ['label' => 'العنوان', 'type' => 'text', 'max' => self::SLIDE_LIMITS['title'], 'required' => true],
                'titleAccent' => ['label' => 'الجزء المميّز من العنوان', 'type' => 'text', 'max' => self::SLIDE_LIMITS['titleAccent']],
                'subtitle' => ['label' => 'النصّ التعريفيّ', 'type' => 'textarea', 'max' => self::SLIDE_LIMITS['subtitle']],
                'primary' => ['label' => 'الزرّ الرئيس', 'type' => 'link'],
                'secondary' => ['label' => 'الزرّ الثانويّ', 'type' => 'link'],
                'showProductCard' => ['label' => 'اعرض بطاقة الأكثر مبيعًا', 'type' => 'bool'],
                'offer' => ['label' => 'تفاصيل العرض', 'type' => 'offer'],
                'search' => ['label' => 'البحث', 'type' => 'search', 'max' => self::SLIDE_CHIPS_MAX],
                'theme' => ['label' => 'لون الشريحة', 'type' => 'choice', 'options' => [
                    ['value' => 'navy-teal', 'label' => 'كحليّ مع فيروزيّ'],
                    ['value' => 'navy', 'label' => 'كحليّ'],
                    ['value' => 'teal', 'label' => 'فيروزيّ'],
                    ['value' => 'sand', 'label' => 'رمليّ'],
                    ['value' => 'mint', 'label' => 'نعناعيّ'],
                    ['value' => 'ink', 'label' => 'حبر داكن'],
                ]],
                'align' => ['label' => 'محاذاة النصّ', 'type' => 'choice', 'options' => [
                    ['value' => 'start', 'label' => 'بداية السطر'],
                    ['value' => 'center', 'label' => 'توسيط'],
                ]],
                'media' => ['label' => 'صورة الخلفيّة', 'type' => 'media', 'focal' => [
                    ['value' => 'center', 'label' => 'الوسط'],
                    ['value' => 'top', 'label' => 'الأعلى'],
                    ['value' => 'bottom', 'label' => 'الأسفل'],
                ]],
                'overlay' => ['label' => 'شدّة الحجاب فوق الصورة', 'type' => 'choice', 'options' => [
                    ['value' => 'soft', 'label' => 'خفيف'],
                    ['value' => 'none', 'label' => 'بلا حجاب'],
                    ['value' => 'strong', 'label' => 'قويّ'],
                ]],
            ],
        ];
    }

    /**
     * وصفُ حقول إعدادات الحركة للّوحة.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sliderSchema(): array
    {
        return [
            'autoplay' => ['label' => 'تشغيل تلقائيّ', 'type' => 'bool'],
            'intervalMs' => ['label' => 'مدّة كلّ شريحة', 'type' => 'int',
                'min' => self::SLIDER_INTERVAL_MIN, 'max' => self::SLIDER_INTERVAL_MAX, 'step' => 500],
            'transition' => ['label' => 'أسلوب الانتقال', 'type' => 'choice', 'options' => [
                ['value' => 'slide', 'label' => 'انزلاق'],
                ['value' => 'fade', 'label' => 'تلاشٍ'],
            ]],
            'continuous' => ['label' => 'استأنف الدوران بعد تفاعل الزائر', 'type' => 'bool'],
            'pauseOnHover' => ['label' => 'توقّف مؤقّتًا عند التحويم أو التركيز', 'type' => 'bool'],
            'showArrows' => ['label' => 'أسهم التنقّل', 'type' => 'bool'],
            'showDots' => ['label' => 'نقاط الشرائح', 'type' => 'bool'],
            'showProgress' => ['label' => 'شريط التقدّم في النقطة النشطة', 'type' => 'bool'],
            'height' => ['label' => 'ارتفاع الهيرو', 'type' => 'choice', 'options' => [
                ['value' => 'compact', 'label' => 'مدمج'],
                ['value' => 'standard', 'label' => 'قياسيّ'],
                ['value' => 'tall', 'label' => 'طويل'],
            ]],
            'ambient' => ['label' => 'حركة الخلفيّة', 'type' => 'choice', 'options' => [
                ['value' => 'none', 'label' => 'بلا حركة'],
                ['value' => 'drift', 'label' => 'انسياب هادئ'],
                ['value' => 'kenburns', 'label' => 'تقريبٌ بطيء للصورة'],
            ]],
        ];
    }

    /**
     * إعدادات الحركة مطبَّعةً: كلّ المفاتيح حاضرة، والمنطقيّ منطقيّ،
     * والمدّة محصورة في ٣–١٥ ثانية، وما خرج عن القوائم يقع على افتراضه.
     *
     * @param  array<array-key, mixed>|null  $raw
     * @return array{autoplay: bool, intervalMs: int, transition: string, continuous: bool, pauseOnHover: bool, showArrows: bool, showDots: bool, showProgress: bool, height: string, ambient: string}
     */
    public static function sliderSettings(?array $raw): array
    {
        $raw ??= [];
        $out = self::SLIDER_DEFAULTS;

        foreach (['autoplay', 'continuous', 'pauseOnHover', 'showArrows', 'showDots', 'showProgress'] as $flag) {
            $value = self::bool($raw[$flag] ?? null);
            if ($value !== null) {
                $out[$flag] = $value;
            }
        }

        $interval = $raw['intervalMs'] ?? null;
        if (is_string($interval) && is_numeric(trim($interval))) {
            $interval = (float) trim($interval);
        }
        if (is_int($interval) || is_float($interval)) {
            $out['intervalMs'] = max(self::SLIDER_INTERVAL_MIN, min(self::SLIDER_INTERVAL_MAX, (int) round($interval)));
        }

        $out['transition'] = self::choice($raw['transition'] ?? null, self::SLIDER_TRANSITIONS);
        $out['height'] = self::choice($raw['height'] ?? null, self::SLIDER_HEIGHTS);
        $out['ambient'] = self::choice($raw['ambient'] ?? null, self::SLIDER_AMBIENTS);

        return $out;
    }

    /** إعدادات الحركة المحفوظة مطبَّعةً — والفراغ افتراضاتُ العقد. */
    public static function heroSlider(): array
    {
        return self::sliderSettings(self::payload(self::HERO_SLIDER));
    }

    /**
     * شرائح الهيرو المُفعَّلة بترتيب المالك، بشكل العقد حرفيًّا.
     *
     * `id` محفوظٌ من الكاتب؛ وصفٌّ بُذر يدويًّا بلا مُعرِّف يُعطى مُعرِّفًا
     * مشتقًّا من موضعه وعنوانه — ثابتًا بين طلبين، لا عشوائيًّا في كلّ قراءة.
     *
     * @return list<array<string, mixed>>
     */
    public static function heroSlides(?\DateTimeInterface $now = null): array
    {
        $now ??= now();
        $out = [];

        foreach (array_slice(self::rows(self::HERO_SLIDES), 0, self::SLIDES_MAX) as $index => $row) {
            $slide = self::normalizeSlide($row);
            if ($slide === null || $slide['enabled'] === false) {
                continue;
            }

            $slide['id'] ??= 's_'.hash('crc32b', $index.'|'.$slide['title']);
            unset($slide['enabled']);

            /* عرضٌ انتهى موعده لا يُعلن موعدًا مضى — والشريحة تبقى بلا عدّاد. */
            if (is_array($slide['offer']) && $slide['offer']['endsAt'] !== null
                && strtotime($slide['offer']['endsAt']) <= $now->getTimestamp()) {
                $slide['offer']['endsAt'] = null;
            }

            $out[] = $slide;
        }

        return $out;
    }

    /**
     * شريحةٌ واحدة مطبَّعة بشكل التخزين (= شكل العقد + `enabled`)، أو null
     * حين يغيب العنوان — الإلزاميّ الوحيد لكلّ الأنواع.
     *
     * يستعملها الكاتب قبل الحفظ والقارئ بعده، فما يُخزَّن هو ما يُقرأ.
     * و`id` يُقبل بصيغته (`s_` + ٤–٨ ست عشريّ) وإلّا صار null — والكاتب
     * يولّده حينئذ.
     *
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public static function normalizeSlide(array $raw): ?array
    {
        $title = self::clip($raw['title'] ?? null, 'title');
        if ($title === null) {
            return null;
        }

        $kind = self::choice($raw['kind'] ?? null, self::SLIDE_KINDS);
        $id = self::text($raw['id'] ?? null);

        $slide = [
            'id' => $id !== null && preg_match('/^s_[0-9a-f]{4,8}$/', $id) === 1 ? $id : null,
            'kind' => $kind,
            'enabled' => self::bool($raw['enabled'] ?? null) ?? true,
            'eyebrow' => self::clip($raw['eyebrow'] ?? null, 'eyebrow'),
            'title' => $title,
            'titleAccent' => self::clip($raw['titleAccent'] ?? null, 'titleAccent'),
            'subtitle' => self::clip($raw['subtitle'] ?? null, 'subtitle'),
            'primary' => $kind === 'search' ? null : self::slideLink($raw['primary'] ?? null),
            'secondary' => $kind === 'search' ? null : self::slideLink($raw['secondary'] ?? null),
            'theme' => self::choice($raw['theme'] ?? null, self::SLIDE_THEMES),
            'align' => self::choice($raw['align'] ?? null, self::SLIDE_ALIGNS),
            'media' => self::slideMedia($raw['media'] ?? null),
            'overlay' => self::choice($raw['overlay'] ?? null, self::SLIDE_OVERLAYS),
            /* بطاقة المنتج للعلامة وحدها؛ وافتراضها ظاهرة كما في مثال العقد. */
            'showProductCard' => $kind === 'brand' && (self::bool($raw['showProductCard'] ?? null) ?? true),
            'offer' => null,
            'search' => null,
        ];

        if ($kind === 'offer') {
            $offer = is_array($raw['offer'] ?? null) ? $raw['offer'] : [];
            $slide['offer'] = [
                'badge' => self::clip($offer['badge'] ?? null, 'badge'),
                'endsAt' => self::isoDate($offer['endsAt'] ?? null),
                'couponCode' => self::clip($offer['couponCode'] ?? null, 'couponCode'),
            ];
        }

        if ($kind === 'search') {
            $search = is_array($raw['search'] ?? null) ? $raw['search'] : [];
            $chips = [];
            foreach (is_array($search['chips'] ?? null) ? $search['chips'] : [] as $chip) {
                $link = self::slideLink($chip);
                if ($link !== null && count($chips) < self::SLIDE_CHIPS_MAX) {
                    $chips[] = $link;
                }
            }
            $slide['search'] = [
                'placeholder' => self::clip($search['placeholder'] ?? null, 'placeholder'),
                'chips' => $chips,
            ];
        }

        return $slide;
    }

    /**
     * وجهةٌ مقبولة: مسارٌ داخليّ يبدأ بـ`/` (لا `//` — تلك نطاقٌ آخر
     * متنكّر) أو `https://`، بلا فراغات. وإلّا null.
     */
    public static function safeHref(mixed $value): ?string
    {
        $href = self::text($value);
        if ($href === null || mb_strlen($href) > self::SLIDE_LIMITS['href'] || preg_match('/\s/u', $href) === 1) {
            return null;
        }

        if (preg_match('#^/(?![/\\\\])#', $href) === 1 || preg_match('#^https://[^/\s]+#i', $href) === 1) {
            return $href;
        }

        return null;
    }

    /** رابط وسيط: من التخزين العامّ (`/storage/…`، `/media/…`، أو أصل القرص العامّ) أو https. */
    public static function safeMediaUrl(mixed $value): ?string
    {
        $url = self::text($value);
        if ($url === null || mb_strlen($url) > self::SLIDE_LIMITS['href'] || preg_match('/\s/u', $url) === 1) {
            return null;
        }

        if (preg_match('#^https://[^/\s]+/#i', $url) === 1 || preg_match('#^/(storage|media)/[^/]#', $url) === 1) {
            return $url;
        }

        $disk = config('filesystems.disks.public.url');
        if (is_string($disk) && $disk !== '' && str_starts_with($url, rtrim($disk, '/').'/')) {
            return $url;
        }

        return null;
    }

    /** @return array{label: string, href: string}|null */
    private static function slideLink(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $label = self::clip($raw['label'] ?? null, 'label');
        $href = self::safeHref($raw['href'] ?? null);

        return ($label !== null && $href !== null) ? ['label' => $label, 'href' => $href] : null;
    }

    /** @return array{url: string, alt: string, focal: string}|null */
    private static function slideMedia(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $url = self::safeMediaUrl($raw['url'] ?? null);
        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            /* وصفٌ فارغ صادق: صورةٌ زخرفيّة يتجاوزها قارئ الشاشة. */
            'alt' => self::clip($raw['alt'] ?? null, 'alt') ?? '',
            'focal' => self::choice($raw['focal'] ?? null, self::SLIDE_FOCALS),
        ];
    }

    /** تاريخٌ صالح بصيغة ISO 8601، أو null. */
    private static function isoDate(mixed $value): ?string
    {
        $text = self::text($value);
        if ($text === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $text) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($text)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /** نصٌّ مشذَّب مقتطَعٌ إلى حدّه، أو null. */
    private static function clip(mixed $value, string $limit): ?string
    {
        $text = self::text($value);

        return $text === null ? null : mb_substr($text, 0, self::SLIDE_LIMITS[$limit]);
    }

    /**
     * قيمةٌ من قائمةٍ مغلقة، وإلّا أوّلُ القائمة (افتراضها).
     *
     * @param  non-empty-list<string>  $allowed
     */
    private static function choice(mixed $value, array $allowed): string
    {
        $text = self::text($value);

        return $text !== null && in_array($text, $allowed, true) ? $text : $allowed[0];
    }

    /** منطقيٌّ صريح (`true`/`false`، `1`/`0`، `"true"`/`"false"`)، وإلّا null. */
    private static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1' => true,
                'false', '0' => false,
                default => null,
            };
        }

        return null;
    }

    /** تطبيعُ نصٍّ واحد بالقاعدة التي يقرأ بها هذا الصفّ — يستعمله الكاتب. */
    public static function normalizeText(mixed $value): ?string
    {
        return self::text($value);
    }

    /**
     * The stored payload for a key, or null when it was never configured.
     *
     * @return array<array-key, mixed>|null
     */
    public static function payload(string $key): ?array
    {
        $value = Setting::query()->where('key', $key)->value('value');

        return is_array($value) ? $value : null;
    }

    /**
     * Hero copy. Null when unconfigured or when the one required field —
     * the headline — is missing: a hero with no title is not a hero.
     *
     * @return array<string, mixed>|null
     */
    public static function hero(): ?array
    {
        $hero = self::block(self::HERO, self::HERO_FIELDS, withTrust: true);

        if ($hero !== null && array_key_exists('variant', $hero)) {
            $variant = self::heroVariant($hero['variant']);
            if ($variant === null) {
                unset($hero['variant']);
            } else {
                $hero['variant'] = $variant;
            }
        }

        /*
         * `slider` و`slides` إضافةٌ لا تمسّ المفاتيح القديمة (عقد الهيرو §1).
         * و`slides: []` هو التراجع: المتجر يبني شرائحه الثلاث من الحقول القديمة.
         * ومتجرٌ بلا هيرو ولا شرائح يبقى ٢٠٤ كما كان.
         */
        $slides = self::heroSlides();
        if ($hero === null && $slides === []) {
            return null;
        }

        return [...($hero ?? []), 'slider' => self::heroSlider(), 'slides' => $slides];
    }

    /**
     * تصميم الهيرو المقبول: من القائمة كما هو، والقديم يُحوَّل إلى `slider`،
     * والمجهول يسقط فيقع المتجر على افتراضه.
     */
    public static function heroVariant(mixed $value): ?string
    {
        $text = self::text($value);
        if ($text === null) {
            return null;
        }

        $text = self::HERO_LEGACY_VARIANTS[$text] ?? $text;

        return in_array($text, self::HERO_VARIANTS, true) ? $text : null;
    }

    /**
     * Closing call-to-action. Same required field as the hero; the storefront
     * only renders the button when BOTH cta_label and cta_url are present.
     *
     * @return array<string, mixed>|null
     */
    public static function cta(): ?array
    {
        $block = self::block(self::CTA, self::CTA_FIELDS, withTrust: false);

        if ($block === null) {
            return null;
        }

        /*
         * `secondary` كائنٌ واحد أو null: زرٌّ بنصٍّ بلا وجهة (أو العكس) نصفُ
         * زرٍّ، فلا يُصدَر. والحقلان الخامان يبقيان للوحة التحرير.
         */
        $label = $block['cta_secondary_label'] ?? null;
        $href = $block['cta_secondary_url'] ?? null;
        $block['secondary'] = ($label !== null && $href !== null) ? ['label' => $label, 'href' => $href] : null;

        return $block;
    }

    /**
     * شريط الإعلانات بشكله للمتجر — دائمًا كائن، وفراغُه صادق: `items: []`.
     *
     * النمط يقع على `static` حين يغيب أو يُكتب خطأً؛ والرابط كائنٌ واحد أو
     * null (نصٌّ بلا وجهة نصفُ رابط)؛ والهيئة تُصدَر حين يصحّ اللونان معًا
     * (hex)، والارتفاع عددٌ صحيح موجب أو null.
     *
     * @return array{mode: string, items: list<string>, link: array{label: string, href: string}|null, style: array{bg: string, fg: string, height: int|null}|null}
     */
    public static function announcements(): array
    {
        $raw = self::payload(self::ANNOUNCEMENTS) ?? [];

        $mode = self::text($raw['announcements_mode'] ?? null);
        $items = [];
        foreach (['announcement_1', 'announcement_2', 'announcement_3'] as $field) {
            $item = self::text($raw[$field] ?? null);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $label = self::text($raw['announcements_link_label'] ?? null);
        $href = self::text($raw['announcements_link_url'] ?? null);

        $bg = self::hex($raw['announcements_bg'] ?? null);
        $fg = self::hex($raw['announcements_fg'] ?? null);
        $height = self::text($raw['announcements_height'] ?? null);
        $height = $height !== null && preg_match('/^[1-9]\d{0,3}$/', $height) === 1 ? (int) $height : null;

        return [
            'mode' => in_array($mode, self::ANNOUNCEMENT_MODES, true) ? $mode : 'static',
            'items' => $items,
            'link' => ($label !== null && $href !== null) ? ['label' => $label, 'href' => $href] : null,
            'style' => ($bg !== null && $fg !== null) ? ['bg' => $bg, 'fg' => $fg, 'height' => $height] : null,
        ];
    }

    /** شارة الحملة كما كتبها المالك، أو null. */
    public static function promoBadge(): ?string
    {
        return self::text((self::payload(self::PROMO) ?? [])['badge'] ?? null);
    }

    /**
     * هويّة المتجر للتذييل — دائمًا كائن؛ فراغُه `{document: null, socials: []}`.
     *
     * الوثيقة تُصدَر حين يكتمل الاسم والرقم معًا، والحساب حين يكون رابطه
     * http(s) صالحًا — رابطٌ معطوب لا يصير أيقونةً في التذييل.
     *
     * @return array{document: array{label: string, number: string}|null, socials: list<array{network: string, label: string, href: string}>}
     */
    public static function identity(): array
    {
        $raw = self::payload(self::IDENTITY) ?? [];

        $label = self::text($raw['document_label'] ?? null);
        $number = self::text($raw['document_number'] ?? null);

        $socials = [];
        foreach (self::SOCIAL_NETWORKS as $network => $name) {
            $href = self::text($raw["social_{$network}_url"] ?? null);
            if ($href !== null && preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $href) === 1) {
                $socials[] = ['network' => $network, 'label' => $name, 'href' => $href];
            }
        }

        return [
            'document' => ($label !== null && $number !== null) ? ['label' => $label, 'number' => $number] : null,
            'socials' => $socials,
        ];
    }

    /** لونٌ سداسيّ `#rgb` أو `#rrggbb` مطبَّعًا صغيرًا، أو null. */
    private static function hex(mixed $value): ?string
    {
        $text = self::text($value);

        return $text !== null && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $text) === 1
            ? strtolower($text)
            : null;
    }

    /**
     * «why us» / «benefits» cards — same DTO, two independent keys.
     *
     * @return list<array{title: string, description?: string}>
     */
    public static function cards(string $key): array
    {
        $out = [];

        foreach (self::rows($key) as $row) {
            $title = self::text($row['title'] ?? null);
            if ($title === null) {
                continue;
            }

            $card = ['title' => $title];
            $description = self::text($row['description'] ?? null);
            if ($description !== null) {
                $card['description'] = $description;
            }

            $out[] = $card;
        }

        return $out;
    }

    /**
     * FAQ entries. `id` is the 1-based position in the configured list — a
     * derived key for the accordion, not an invented identifier. Entries
     * missing either half of the pair are dropped: a question with no answer
     * would be an empty promise.
     *
     * @return list<array{id: int, question: string, answer: string}>
     */
    public static function faq(): array
    {
        $out = [];

        foreach (self::rows(self::FAQ) as $row) {
            $question = self::text($row['question'] ?? null);
            $answer = self::text($row['answer'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }

            $out[] = ['id' => count($out) + 1, 'question' => $question, 'answer' => $answer];
        }

        return $out;
    }

    /**
     * أقسام الرئيسة المُفعَّلة بترتيبها، أو `null` حين لم يُضبط الترتيب بعد.
     *
     * و`null` ليست «لا أقسام»: المتجر يقع حينئذ على ترتيبه الافتراضيّ. أمّا
     * قائمةٌ فارغة محفوظة فقرارٌ صريح بإخلاء الصفحة — ولذلك يحذف الكاتب
     * الصفَّ بدل أن يحفظ فراغًا، فلا تُقرأ الحالتان واحدةً.
     *
     * والمجهول يُسقَط: مُعرِّفٌ لا يعرفه هذا السجلّ لا يصل المتجر أصلًا.
     *
     * @return list<string>|null
     */
    public static function layout(): ?array
    {
        $raw = self::payload(self::LAYOUT);
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach ($raw as $id) {
            $text = self::text($id);
            if ($text !== null && isset(self::SECTIONS[$text]) && ! in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /** The advertised coupon CODE, or null when the store advertises none. */
    public static function promotedCouponCode(): ?string
    {
        $payload = self::payload(self::PROMO_COUPON);

        return $payload === null ? null : self::text($payload['code'] ?? null);
    }

    /**
     * A title-plus-optional-fields block (hero / cta).
     *
     * @param  list<string>  $optional
     * @return array<string, mixed>|null
     */
    private static function block(string $key, array $optional, bool $withTrust): ?array
    {
        $raw = self::payload($key);
        if ($raw === null) {
            return null;
        }

        $title = self::text($raw['title'] ?? null);
        if ($title === null) {
            return null;
        }

        $out = ['title' => $title];

        foreach ($optional as $field) {
            $value = self::text($raw[$field] ?? null);
            if ($value !== null) {
                $out[$field] = $value;
            }
        }

        if ($withTrust) {
            $trust = self::strings($raw['trust'] ?? null);
            if ($trust !== []) {
                $out['trust'] = $trust;
            }
        }

        return $out;
    }

    /**
     * The configured list under a key, with non-array entries discarded.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function rows(string $key): array
    {
        $raw = self::payload($key);

        return $raw === null ? [] : array_values(array_filter($raw, is_array(...)));
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(self::text(...), $value), is_string(...)));
    }

    /** A configured string, or null when absent, blank or of another type. */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
