<?php

namespace Database\Seeders;

use App\Domain\Assets\AssetImporter;
use App\Domain\Cart\Models\Coupon;
use App\Domain\Content\HomeContent;
use App\Domain\Content\Models\Campaign;
use App\Domain\Reviews\AggregateRebuilder;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\ReviewDenied;
use App\Domain\Reviews\ReviewService;
use App\Domain\SEO\Models\ProductFaq;
use App\Models\Customer;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductFact;
use App\Models\ProductPrice;
use App\Models\ProductSeo;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * ⚠ DEV FIXTURE DATA FOR DESIGN PREVIEW — NOT PRODUCTION CATALOG CONTENT. ⚠
 *
 * Fills a local database with a dozen catalogue rows that look like the real
 * thing PLUS the homepage content those designs sit inside (§3.3), so the
 * storefront designs (cards, PDP, facets, price/sale states, hero, promo,
 * why/benefits, faq, cta) can be reviewed against realistic Arabic content
 * instead of lorem ipsum or a page of empty states.
 *
 * Rules this seeder holds itself to:
 *  · It REFUSES to run in production, and the DatabaseSeeder call site is
 *    additionally restricted to the local environment.
 *  · Every curriculum value is looked up from the vocabulary CurriculumSeeder
 *    already seeded (stage / grade / subject / semester / kind). Nothing here
 *    invents a term; if the vocabulary is missing the seeder stops and says so.
 *  · Every row is identifiable: SKU and order-number prefix DESIGN-PREVIEW-,
 *    and the run logs a loud line naming it as fixture data.
 *  · NO aggregate is ever hand-written. Ratings, review counts and the
 *    distribution bars are NOT typed into a column: the seeder writes fixture
 *    REVIEWS through App\Domain\Reviews\ReviewService (verified purchase →
 *    pending → approved) and then lets AggregateRebuilder recompute
 *    `review_aggregates` from the approved rows. Fixture opinions, real
 *    arithmetic. A handful of fixtures is left deliberately review-free so the
 *    honest empty state stays visible in preview too.
 *  · Bestsellers are not faked either: instead of writing a sales number, the
 *    seeder writes real, clearly-labelled PAID ORDERS for a few fixtures. The
 *    ranking the storefront shows is then computed from `order_items` by the
 *    same query production uses — fixture input, real arithmetic.
 *  · Cover images are GENERATED, never sourced. Each cover is a deterministic
 *    SVG drawn from the design tokens and committed under
 *    database/seeders/preview-assets/. It carries a visible «غلاف معاينة
 *    للتصميم» caption, so a generated placeholder can never be mistaken for a
 *    photograph of a real product.
 */
class DesignPreviewSeeder extends Seeder
{
    private const SKU_PREFIX = 'DESIGN-PREVIEW-';

    /**
     * The fixtures. Curriculum values are SLUGS from CurriculumSeeder:
     * grade  = «{bare ordinal}-{stage}»  · semester = «الفصل-{ordinal}»
     * subject/kind slugs are the canonical Arabic names themselves.
     *
     * `regular` / `sale` are minor units (halalas, VAT-inclusive).
     * `sale_days` is [from, to] as offsets in days from now — a real window,
     * so an unopened or a closed window genuinely shows no discount.
     *
     * @var list<array{kind: string, subject: string, grade: string, semester: string, format: string, editable: string, regular: int, sale?: int, sale_days?: array{int, int}, published_days_ago: int, facts: array<string, int>}>
     */
    private const FIXTURES = [
        [
            'kind' => 'تحضير', 'subject' => 'الرياضيات', 'grade' => 'سادس-ابتدائي',
            'semester' => 'الفصل-الأول', 'format' => 'DOCX', 'editable' => 'yes',
            'regular' => 3500, 'published_days_ago' => 96,
            'facts' => ['pages_count' => 86, 'lessons_count' => 24],
        ],
        [
            'kind' => 'عرض بوربوينت', 'subject' => 'العلوم', 'grade' => 'رابع-ابتدائي',
            'semester' => 'الفصل-الثاني', 'format' => 'PPTX', 'editable' => 'yes',
            'regular' => 4500, 'sale' => 2900, 'sale_days' => [-3, 11], 'published_days_ago' => 74,
            'facts' => ['slides_count' => 62],
        ],
        [
            'kind' => 'أوراق عمل', 'subject' => 'لغتي', 'grade' => 'ثالث-ابتدائي',
            'semester' => 'الفصل-الأول', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 2500, 'published_days_ago' => 68,
            'facts' => ['worksheets_count' => 40, 'activities_count' => 18],
        ],
        [
            'kind' => 'اختبارات', 'subject' => 'الدراسات الإسلامية', 'grade' => 'خامس-ابتدائي',
            'semester' => 'الفصل-الثالث', 'format' => 'DOCX', 'editable' => 'yes',
            'regular' => 3000, 'published_days_ago' => 61,
            'facts' => ['pages_count' => 32],
        ],
        [
            'kind' => 'توزيع', 'subject' => 'المهارات الرقمية', 'grade' => 'أول-متوسط',
            'semester' => 'الفصل-الأول', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 1500, 'published_days_ago' => 55,
            'facts' => ['pages_count' => 12],
        ],
        [
            'kind' => 'تحضير', 'subject' => 'اللغة الإنجليزية', 'grade' => 'ثاني-متوسط',
            'semester' => 'الفصل-الثاني', 'format' => 'DOCX', 'editable' => 'yes',
            'regular' => 3900, 'published_days_ago' => 47,
            'facts' => ['pages_count' => 104, 'lessons_count' => 30],
        ],
        [
            'kind' => 'عرض بوربوينت', 'subject' => 'الفيزياء', 'grade' => 'أول-ثانوي',
            'semester' => 'الفصل-الأول', 'format' => 'PPTX', 'editable' => 'yes',
            'regular' => 5500, 'sale' => 4400, 'sale_days' => [-1, 20], 'published_days_ago' => 39,
            'facts' => ['slides_count' => 118],
        ],
        [
            'kind' => 'أوراق عمل', 'subject' => 'الكيمياء', 'grade' => 'ثاني-ثانوي',
            'semester' => 'الفصل-الثاني', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 3200, 'published_days_ago' => 33,
            'facts' => ['worksheets_count' => 28, 'activities_count' => 15],
        ],
        [
            'kind' => 'وسائل تعليمية', 'subject' => 'القرآن الكريم', 'grade' => 'ثاني-ابتدائي',
            'semester' => 'الفصل-الأول', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 2200, 'published_days_ago' => 26,
            'facts' => ['pages_count' => 24],
        ],
        [
            'kind' => 'حلول', 'subject' => 'الرياضيات', 'grade' => 'ثالث-متوسط',
            'semester' => 'الفصل-الثالث', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 2800, 'published_days_ago' => 18,
            'facts' => ['pages_count' => 96],
        ],
        [
            'kind' => 'تحضير', 'subject' => 'الدراسات الاجتماعية', 'grade' => 'ثالث-ثانوي',
            'semester' => 'الفصل-الثاني', 'format' => 'DOCX', 'editable' => 'yes',
            'regular' => 4200, 'published_days_ago' => 11,
            'facts' => ['pages_count' => 120, 'lessons_count' => 34],
        ],
        [
            // A REAL window that has not opened yet: the storefront must show the
            // regular price today, not a discount.
            'kind' => 'اختبارات', 'subject' => 'الأحياء', 'grade' => 'ثالث-ثانوي',
            'semester' => 'الفصل-الأول', 'format' => 'DOCX', 'editable' => 'yes',
            'regular' => 3600, 'sale' => 2700, 'sale_days' => [7, 28], 'published_days_ago' => 4,
            'facts' => ['pages_count' => 44],
        ],

        // ── Fixtures 13–24 ────────────────────────────────────────────────
        // Breadth so the grid, the «load more» control, the pagination and the
        // stage/subject/semester facets all have real rows to act on instead of
        // a single short page. Same convention, same SKU prefix, same
        // CurriculumSeeder vocabulary — nothing new is invented here.
        [
            'kind' => 'عرض بوربوينت', 'subject' => 'اللغة العربية', 'grade' => 'أول-متوسط',
            'semester' => 'الفصل-الثاني', 'format' => 'PPTX', 'editable' => 'yes',
            'regular' => 4800, 'published_days_ago' => 88,
            'facts' => ['slides_count' => 74],
        ],
        [
            'kind' => 'أوراق عمل', 'subject' => 'الرياضيات', 'grade' => 'أول-ابتدائي',
            'semester' => 'الفصل-الأول', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 2600, 'published_days_ago' => 81,
            'facts' => ['worksheets_count' => 36, 'activities_count' => 20],
        ],
        [
            'kind' => 'تحضير', 'subject' => 'العلوم', 'grade' => 'خامس-ابتدائي',
            'semester' => 'الفصل-الثالث', 'format' => 'DOCX', 'editable' => 'yes',
            'regular' => 3700, 'published_days_ago' => 70,
            'facts' => ['pages_count' => 92, 'lessons_count' => 26],
        ],
        [
            'kind' => 'اختبارات', 'subject' => 'اللغة الإنجليزية', 'grade' => 'ثالث-متوسط',
            'semester' => 'الفصل-الأول', 'format' => 'DOCX', 'editable' => 'yes',
            'regular' => 3100, 'sale' => 2450, 'sale_days' => [-5, 9], 'published_days_ago' => 64,
            'facts' => ['pages_count' => 38],
        ],
        [
            'kind' => 'وسائل تعليمية', 'subject' => 'التربية الفنية', 'grade' => 'ثالث-ابتدائي',
            'semester' => 'الفصل-الثاني', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 1900, 'published_days_ago' => 58,
            'facts' => ['pages_count' => 20],
        ],
        [
            'kind' => 'توزيع', 'subject' => 'الكفايات اللغوية', 'grade' => 'ثاني-ثانوي',
            'semester' => 'الفصل-الأول', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 1700, 'published_days_ago' => 50,
            'facts' => ['pages_count' => 14],
        ],
        [
            'kind' => 'حلول', 'subject' => 'الفيزياء', 'grade' => 'ثالث-ثانوي',
            'semester' => 'الفصل-الثالث', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 3300, 'published_days_ago' => 43,
            'facts' => ['pages_count' => 108],
        ],
        [
            'kind' => 'عرض بوربوينت', 'subject' => 'الدراسات الإسلامية', 'grade' => 'سادس-ابتدائي',
            'semester' => 'الفصل-الأول', 'format' => 'PPTX', 'editable' => 'yes',
            'regular' => 4100, 'sale' => 3280, 'sale_days' => [-2, 14], 'published_days_ago' => 36,
            'facts' => ['slides_count' => 68],
        ],
        [
            // A REAL window that has already CLOSED: the third price state, so
            // the design can be judged on an expired offer too.
            'kind' => 'أوراق عمل', 'subject' => 'المهارات الحياتية', 'grade' => 'ثاني-متوسط',
            'semester' => 'الفصل-الثالث', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 2400, 'sale' => 1900, 'sale_days' => [-30, -3], 'published_days_ago' => 29,
            'facts' => ['worksheets_count' => 30, 'activities_count' => 12],
        ],
        [
            'kind' => 'تحضير', 'subject' => 'التفكير الناقد', 'grade' => 'أول-ثانوي',
            'semester' => 'الفصل-الثاني', 'format' => 'DOCX', 'editable' => 'yes',
            'regular' => 4600, 'published_days_ago' => 22,
            'facts' => ['pages_count' => 88, 'lessons_count' => 22],
        ],
        [
            'kind' => 'اختبارات', 'subject' => 'علم البيئة', 'grade' => 'ثاني-ثانوي',
            'semester' => 'الفصل-الثالث', 'format' => 'DOCX', 'editable' => 'yes',
            'regular' => 2900, 'published_days_ago' => 15,
            'facts' => ['pages_count' => 30],
        ],
        [
            'kind' => 'وسائل تعليمية', 'subject' => 'التربية البدنية', 'grade' => 'أول-ابتدائي',
            'semester' => 'الفصل-الثاني', 'format' => 'PDF', 'editable' => 'no',
            'regular' => 1600, 'published_days_ago' => 8,
            'facts' => ['pages_count' => 18],
        ],
    ];

    /** Per-kind body copy, so each row reads like the file it describes. */
    private const KIND_COPY = [
        'تحضير' => 'دفتر تحضير جاهز لمادة :subject — :grade، :semester. يشمل الأهداف السلوكية واستراتيجيات التدريس وأساليب التقويم مرتّبة حسب الدروس.',
        'عرض بوربوينت' => 'عرض تقديمي متكامل لمادة :subject — :grade، :semester، بشرائح مصمّمة وجاهزة للعرض داخل الحصة.',
        'أوراق عمل' => 'حزمة أوراق عمل وأنشطة صفّية لمادة :subject — :grade، :semester، جاهزة للطباعة والتوزيع.',
        'اختبارات' => 'بنك أسئلة ونماذج اختبار لمادة :subject — :grade، :semester، مع توزيع الدرجات ومفتاح الإجابة.',
        'توزيع' => 'توزيع المنهج الأسبوعي لمادة :subject — :grade، :semester، موافق لخطة الفصل الدراسي.',
        'وسائل تعليمية' => 'وسائل ولوحات تعليمية لمادة :subject — :grade، :semester، جاهزة للطباعة والتعليق داخل الفصل.',
        'حلول' => 'حلول تمارين وأسئلة كتاب :subject — :grade، :semester، مرتّبة حسب الدروس خطوة بخطوة.',
    ];

    /** Fixture coupon — a REAL, redeemable row, not a printed code. */
    private const COUPON_CODE = 'PREVIEW15';

    /** The buyer on every fixture order: an address that cannot exist. */
    private const FIXTURE_EMAIL = 'design-preview@example.invalid';

    /**
     * Editorial homepage copy, keyed exactly as App\Domain\Content\HomeContent
     * reads it. Dev fixture copy — realistic in SHAPE (lengths, tone, the
     * bilingual/RTL edge cases the layout has to survive), never a marketing
     * claim anyone approved.
     */
    private const HOME_CONTENT = [
        HomeContent::HERO => [
            'eyebrow' => 'متجر «التحاضير الحديثة»',
            'title' => 'كل ما يحتاجه المعلّم لتحضير حصته',
            'title_accent' => 'جاهزًا قبل بداية الدرس',
            'subtitle' => 'تحاضير وعروض وأوراق عمل واختبارات مبنية على مناهج وزارة التعليم، تصلك بصيغة قابلة للتعديل فور إتمام الدفع.',
            'cta_label' => 'تصفّح المنتجات',
            'cta_url' => '/shop',
            'cta_secondary_label' => 'كيف يعمل المتجر',
            'cta_secondary_url' => '/how-it-works',
            /*
             * شرائح الهيرو الافتراضية لا تَعِد بما لا يملكه متجرٌ حديث النشر:
             * «دعم عبر واتساب» يناقض ‎/contact‎ حين يغيب
             * `NEXT_PUBLIC_WHATSAPP_NUMBER`، و«قابل للتعديل» صفةُ منتجٍ ثلاثية
             * الحالة لا وصفٌ للكتالوج كلّه. الصيغ حقيقةٌ تصحّ على الكتالوج.
             * ويبقى الحقل محرَّرًا من اللوحة: هذه قيمةُ البدء لا قيدٌ.
             */
            'trust' => ['تحميل فوري بعد الدفع', 'صيغ Word وPowerPoint وPDF'],
        ],
        HomeContent::WHY => [
            ['title' => 'مبنيّة على المنهج', 'description' => 'كل ملف مرتبط بمرحلة ومادة وفصل دراسي محدّد، فلا تبحث في محتوى لا يخصّ صفّك.'],
            ['title' => 'تعديل بلا قيود', 'description' => 'ملفات Word وPowerPoint مفتوحة، تغيّر فيها اسم المدرسة والمعلّم والتواريخ كما تشاء.'],
            ['title' => 'تحميل فوري', 'description' => 'يصلك الملف مباشرة بعد الدفع ويبقى محفوظًا في حسابك للتحميل مرة أخرى.'],
            ['title' => 'تحديث مع المنهج', 'description' => 'عند تحديث المقرر تُحدَّث النسخة، وتجد الإصدار الجديد في مشترياتك.'],
        ],
        HomeContent::BENEFITS => [
            ['title' => 'دفع آمن', 'description' => 'مدى والبطاقات وApple Pay عبر بوابة دفع موثّقة، دون حفظ بيانات بطاقتك لدينا.'],
            ['title' => 'فاتورة ضريبية', 'description' => 'فاتورة نظامية بالرقم الضريبي لكل طلب، جاهزة للتحميل من صفحة الطلب.'],
            ['title' => 'دعم قبل الشراء وبعده', 'description' => 'اسأل عن محتوى أي ملف قبل شرائه، وتواصل معنا إن واجهت أي مشكلة في التحميل.'],
        ],
        HomeContent::FAQ => [
            ['question' => 'كيف أستلم الملف بعد الدفع؟', 'answer' => 'يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويصلك على بريدك أيضًا. الملف يبقى متاحًا في حسابك.'],
            ['question' => 'هل الملفات قابلة للتعديل؟', 'answer' => 'الملفات بصيغة Word وPowerPoint قابلة للتعديل بالكامل. أما ملفات PDF فهي جاهزة للطباعة كما هي، ويوضّح كل منتج صيغته قبل الشراء.'],
            ['question' => 'هل المحتوى موافق لمناهج وزارة التعليم؟', 'answer' => 'نعم، كل ملف مرتبط بمرحلة وصف ومادة وفصل دراسي محدّد وفق الخطة المعتمدة للعام الدراسي الحالي.'],
            ['question' => 'هل يمكنني استرجاع المبلغ؟', 'answer' => 'المنتجات رقمية وتُسلَّم فور الدفع، ولذلك يخضع الاسترجاع لسياسة الاسترجاع المنشورة في المتجر. تواصل معنا إن لم يكن الملف مطابقًا لوصفه.'],
            ['question' => 'هل أحتاج إلى حساب للشراء؟', 'answer' => 'يمكنك الشراء كزائر باستخدام بريدك الإلكتروني، وسنرسل لك رابط الطلب. إنشاء حساب يجعل جميع مشترياتك في مكان واحد.'],
        ],
        HomeContent::CTA => [
            'title' => 'ابدأ فصلك الدراسي بملفات جاهزة',
            'subtitle' => 'اختر مرحلتك ومادتك، وحمّل ما تحتاجه خلال دقائق.',
            'cta_label' => 'تصفّح المنتجات',
            'cta_url' => '/shop',
        ],
        HomeContent::PROMO_COUPON => ['code' => self::COUPON_CODE],
    ];

    /**
     * A campaign with a REAL window: the storefront's «ينتهي بعد …» line is
     * computed from `ends_at`, so this row is what makes the countdown true.
     */
    private const CAMPAIGN = [
        'title' => 'عروض بداية الفصل الدراسي',
        'description' => 'خصومات على تحاضير وعروض الفصل الأول لعدد محدود من الأيام.',
        'url' => '/offers',
        'starts_days_ago' => 2,
        'ends_in_days' => 12,
    ];

    /** 1-based fixture numbers an editor «chose» for the featured rail. */
    private const FEATURED = [2, 4, 7, 11];

    /**
     * 1-based fixture number => how many PAID fixture orders to write for it.
     * These are order rows, not a counter: `source=best` ranks them with the
     * same SQL production runs, and every other fixture stays at zero sales
     * and is therefore correctly absent from the bestsellers rail.
     */
    private const SALES = [3 => 7, 1 => 5, 7 => 4, 10 => 2];

    // ── Covers ────────────────────────────────────────────────────────────

    /**
     * Design tokens, verbatim. The covers are drawn from the SAME palette the
     * artboards use, so a preview card is judged against the real language
     * rather than against stock photography nobody approved.
     */
    private const INK = '#14261d';

    private const BRAND = '#1e7355';

    private const DEEP = '#123d2f';

    private const GOLD = '#d8b56a';

    private const SURFACE = '#ffffff';

    private const BORDER = '#e6e9e2';

    /** Tajawal is never embedded in an SVG — a generic stack keeps the file honest and small. */
    private const FONT = "-apple-system, 'Segoe UI', Tahoma, 'Noto Naskh Arabic', Arial, sans-serif";

    private const COVER_W = 1200;

    private const COVER_H = 900;

    /**
     * `sample` is the documented non-deliverable role (see
     * ProductFile::DELIVERABLE_ROLES, which excludes it): a cover can never be
     * picked up by DownloadDelivery as the paid file.
     */
    private const COVER_ROLE = 'sample';

    /** Directory on the `public` disk (storage/app/public → /storage/…). */
    private const COVER_PUBLIC_DIR = 'design-preview';

    /** Rendered onto every cover, so a generated image says what it is. */
    private const COVER_CAPTION = 'غلاف معاينة للتصميم';

    // ── Reviews ───────────────────────────────────────────────────────────

    /** Fixture accounts. Real rows, addresses that can never exist. */
    private const REVIEWERS = [
        'نورة العتيبي', 'محمد الشهري', 'أمل القحطاني', 'سارة الدوسري',
        'عبدالله الغامدي', 'هند الزهراني', 'خالد الحربي', 'ريم المطيري',
        'فهد السبيعي', 'لمياء العنزي', 'ماجد البقمي', 'شيماء الرشيدي',
        'يوسف الخالدي', 'دانة الشمري',
    ];

    /** The edition an order line records; shown as «variantPurchased». */
    private const FIXTURE_VERSION = 'إصدار ١٤٤٧هـ';

    /**
     * 1-based fixture number => the star ratings to write, in order. Every one
     * becomes a real order + a real review that goes through ReviewService.
     *
     * The spread is deliberate: near-perfect products, mixed ones, one that is
     * genuinely disliked (#10), one with a single review (#9 — below the
     * 3-review floor for AggregateRating markup), and SIX fixtures with no
     * entry at all, so the «كن أول من يقيّم» empty state is still previewable.
     *
     * @var array<int, list<int>>
     */
    private const REVIEW_PLAN = [
        1 => [5, 5, 5, 4, 5, 4, 5, 5, 3, 5, 4, 5],
        2 => [5, 4, 4, 5, 3, 4, 4],
        3 => [5, 5, 5, 5, 5, 4, 5, 5, 4],
        4 => [4, 3, 5, 4, 2, 4],
        5 => [5, 4],
        6 => [3, 4, 2, 5, 3],
        7 => [5, 5, 4, 5, 5, 5, 4, 5, 5, 5, 4],
        8 => [4, 4, 5, 3],
        9 => [5],
        10 => [2, 3, 1, 4, 2],
        11 => [5, 4, 5, 4, 5, 5, 3],
        13 => [4, 5, 4],
        14 => [5, 5, 4, 4, 5, 5],
        16 => [3, 5, 4, 4, 5, 2, 4, 5],
        17 => [5, 5, 5],
        19 => [4, 4, 5],
        20 => [5, 4, 5, 5, 4, 5, 5, 4, 5],
        22 => [3, 4, 4, 5],
    ];

    /**
     * Fixture number => how many helpful votes the product's FIRST review gets,
     * cast by real (fixture) accounts through ReviewService::voteHelpful. The
     * counter is a COUNT of `review_votes`, never a typed number.
     *
     * @var array<int, int>
     */
    private const HELPFUL = [1 => 3, 3 => 2, 7 => 3, 10 => 1, 16 => 2, 20 => 3];

    /**
     * Fixture number => the store's reply to that product's LOWEST-rated review.
     * Signed «التحاضير الحديثة» by ReviewService, never by a staff name.
     *
     * @var array<int, string>
     */
    private const REPLIES = [
        10 => 'شكرًا لصراحتك. الحلول مرتبطة بطبعة الكتاب الحالية، وراسلنا عبر واتساب لنرسل لك النسخة المطابقة لخطة مدرستك دون رسوم.',
        16 => 'نعتذر عن عدم مطابقة التوقع. أضفنا نماذج إجابة إضافية للاختبار، والنسخة المحدّثة متاحة الآن في حسابك.',
    ];

    /**
     * Review bodies by star band. Fixture opinions written for design review —
     * realistic in SHAPE (length, tone, the RTL/bilingual edges the card has to
     * survive), never a quote anyone actually said.
     *
     * @var array<int, list<string>>
     */
    private const REVIEW_BODIES = [
        5 => [
            'ملف منظّم ومرتّب، وفّر عليّ وقت التحضير كاملًا. التوزيع مطابق للخطة والأهداف واضحة.',
            'استخدمته من أول أسبوع في الفصل، والتنسيق ممتاز والخط واضح عند الطباعة.',
            'الصيغة قابلة للتعديل فعلًا؛ غيّرت اسم المدرسة والتواريخ خلال دقائق.',
            'المحتوى مطابق للوصف تمامًا، ورابط التحميل وصلني مباشرة بعد الدفع.',
            'أفضل ما استخدمته هذا الفصل. الأنشطة متدرجة والأسئلة تغطّي كل الدروس.',
            'جودة الإخراج عالية، وطبعته مباشرة دون أي تعديل على الهوامش.',
            'ساعدني كثيرًا في تنظيم الحصص، وأنصح به زملائي في نفس الصف.',
            'محتوى يستحق سعره، وسأشتري بقية الفصول من نفس السلسلة.',
        ],
        4 => [
            'ملف جيد ومفيد، لكن كنت أتمنى أسئلة إضافية في الوحدة الأخيرة.',
            'التنسيق مرتّب والمحتوى مناسب، احتجت تعديلًا بسيطًا على حجم الخط فقط.',
            'يغطّي المنهج بشكل جيد، ولو أُضيفت نماذج إجابة لكان أفضل.',
            'استفدت منه والتحميل كان سريعًا، مع ملاحظة بسيطة على ترتيب الدروس.',
            'محتوى قوي بشكل عام، وبعض الصفحات تحتاج مساحة أكبر للكتابة.',
            'مناسب للصف ويوفّر وقتًا حقيقيًا، مع فرق بسيط عن خطة مدرستنا.',
        ],
        3 => [
            'الملف مقبول ويؤدي الغرض، لكنه أقل تفصيلًا مما توقعت.',
            'يحتاج مراجعة لبعض الأنشطة، وبقية المحتوى مناسب.',
            'فكرة جيدة وتنفيذ متوسط؛ استفدت من نصفه تقريبًا.',
            'المحتوى مختصر، وأتمنى إضافة أمثلة أكثر لكل درس.',
        ],
        2 => [
            'لم يطابق توقعاتي؛ عدد الصفحات أقل مما يحتاجه الصف عندنا.',
            'التنسيق يحتاج عملًا، واضطررت لإعادة ترتيب أغلب الصفحات.',
            'المحتوى عام أكثر من اللازم ولا يغطّي دروس الوحدة الثالثة.',
        ],
        1 => [
            'الملف لم يناسب خطة مدرستنا إطلاقًا ولم أستفد منه.',
            'توقعت محتوى مختلفًا تمامًا عن الوصف؛ لم يفدني داخل الحصة.',
        ],
    ];

    /** Optional review headline by star band — half the fixtures carry none. */
    private const REVIEW_TITLES = [
        5 => ['وفّر عليّ وقتًا كبيرًا', 'جاهز للطباعة', 'يستحق السعر'],
        4 => ['جيد مع ملاحظة بسيطة', 'مفيد ومرتّب'],
        3 => ['مقبول'],
        2 => ['لم يطابق توقعاتي'],
        1 => ['لم يناسب خطتنا'],
    ];

    /** Fact key => the Arabic noun the PDP/FAQ counts it with. */
    private const FACT_NOUNS = [
        'slides_count' => 'شريحة',
        'pages_count' => 'صفحة',
        'lessons_count' => 'درسًا',
        'worksheets_count' => 'ورقة عمل',
        'activities_count' => 'نشاطًا',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('DesignPreviewSeeder: refused to run — design-preview fixtures never touch production.');

            return;
        }

        $kinds = Kind::query()->pluck('name_ar', 'slug');
        $subjects = Subject::query()->pluck('id', 'slug');
        $grades = Grade::query()->get(['id', 'slug', 'name_ar', 'stage_id'])->keyBy('slug');
        $semesters = Semester::query()->get(['id', 'slug', 'name_ar'])->keyBy('slug');

        if ($kinds->isEmpty() || $subjects->isEmpty() || $grades->isEmpty() || $semesters->isEmpty()) {
            $this->command?->warn('DesignPreviewSeeder: curriculum vocabulary is missing — run CurriculumSeeder first.');

            return;
        }

        $created = 0;
        /** @var array<int, Product> $fixtures 1-based fixture number => row */
        $fixtures = [];
        /** @var array<int, array<string, mixed>> $meta 1-based fixture number => resolved labels */
        $meta = [];

        foreach (self::FIXTURES as $index => $row) {
            $kindName = $kinds[$row['kind']] ?? null;
            $subjectId = $subjects[$row['subject']] ?? null;
            $grade = $grades[$row['grade']] ?? null;
            $semester = $semesters[$row['semester']] ?? null;
            $copy = self::KIND_COPY[$row['kind']] ?? null;

            // A fixture that names a term the vocabulary does not have is a bug
            // in this file, not something to paper over with invented data.
            if ($kindName === null || $subjectId === null || $grade === null || $semester === null || $copy === null) {
                $this->command?->warn(sprintf(
                    'DesignPreviewSeeder: skipped fixture #%d — unknown curriculum term (%s / %s / %s / %s).',
                    $index + 1,
                    $row['kind'],
                    $row['subject'],
                    $row['grade'],
                    $row['semester'],
                ));

                continue;
            }

            $title = sprintf('%s %s — %s — %s', $kindName, $row['subject'], $grade->name_ar, $semester->name_ar);
            $shortDesc = strtr($copy, [
                ':subject' => $row['subject'],
                ':grade' => $grade->name_ar,
                ':semester' => $semester->name_ar,
            ]);
            $editableNote = $row['editable'] === 'yes'
                ? sprintf('الملف بصيغة %s قابلة للتعديل، فتغيّر اسم المدرسة والمعلّم كما تشاء.', $row['format'])
                : sprintf('الملف بصيغة %s جاهز للطباعة مباشرة.', $row['format']);

            $product = Product::query()->updateOrCreate(
                ['sku' => self::SKU_PREFIX.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)],
                [
                    'slug' => str_replace(' ', '-', $row['kind'].' '.$row['subject'].' '.$row['grade'].' '.$row['semester']),
                    'title' => $title,
                    'subtitle' => $grade->name_ar.' · '.$semester->name_ar,
                    'short_desc' => $shortDesc,
                    'description' => $shortDesc.' '.$editableNote.' التحميل فوري بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك.',
                    'resource_type' => $kindName,
                    'format' => $row['format'],
                    'stage_id' => $grade->stage_id,
                    'grade_id' => $grade->id,
                    'subject_id' => $subjectId,
                    'semester_id' => $semester->id,
                    'editable' => $row['editable'],
                    'status' => 'published',
                    'fulfillment_type' => 'digital',
                    'published_at' => now()->subDays($row['published_days_ago']),
                ],
            );

            $price = [
                'regular_minor' => $row['regular'],
                'sale_minor' => null,
                'sale_from' => null,
                'sale_to' => null,
                'currency' => 'SAR',
            ];
            if (isset($row['sale'], $row['sale_days'])) {
                $price['sale_minor'] = $row['sale'];
                $price['sale_from'] = now()->addDays($row['sale_days'][0]);
                $price['sale_to'] = now()->addDays($row['sale_days'][1]);
            }
            ProductPrice::query()->updateOrCreate(['product_id' => $product->id], $price);

            foreach ($row['facts'] as $key => $value) {
                ProductFact::query()->updateOrCreate(
                    ['product_id' => $product->id, 'key' => $key],
                    ['int_value' => $value],
                );
            }

            ProductSeo::query()->updateOrCreate(
                ['product_id' => $product->id],
                [
                    'seo_title' => $title.' — تحميل فوري',
                    'seo_desc' => $shortDesc,
                    'quick_answer' => sprintf(
                        'نعم، %s لمادة %s (%s، %s) متاح بصيغة %s. %s',
                        $kindName,
                        $row['subject'],
                        $grade->name_ar,
                        $semester->name_ar,
                        $row['format'],
                        'يصلك رابط التحميل مباشرة بعد إتمام الدفع.',
                    ),
                ],
            );

            $fixtures[$index + 1] = $product;
            $meta[$index + 1] = [
                'kind' => $kindName,
                'subject' => $row['subject'],
                'grade' => $grade->name_ar,
                'semester' => $semester->name_ar,
                'format' => $row['format'],
                'editable' => $row['editable'],
                'facts' => $row['facts'],
                'title' => $title,
            ];
            $created++;
        }

        $this->command?->info(sprintf(
            '[DEV FIXTURE] DesignPreviewSeeder wrote %d design-preview products (SKU prefix %s). '
            .'This is preview data for design review — NOT production catalog content. '
            .'No rating or review count was typed anywhere; every star below is recomputed by '
            .'AggregateRebuilder from the fixture reviews this same run writes.',
            $created,
            self::SKU_PREFIX,
        ));

        $this->seedCovers($fixtures, $meta);
        $this->seedFaqs($fixtures, $meta);
        $this->seedHomepage($fixtures);
        $this->seedReviews($fixtures);
    }

    /**
     * Homepage §3.3 content: editorial copy in `settings`, a live campaign, a
     * redeemable coupon, the featured flags, and the paid orders the
     * bestsellers rail is computed from.
     *
     * @param  array<int, Product>  $fixtures  1-based fixture number => row
     */
    private function seedHomepage(array $fixtures): void
    {
        foreach (self::HOME_CONTENT as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Campaign::query()->updateOrCreate(
            ['title' => self::CAMPAIGN['title']],
            [
                'description' => self::CAMPAIGN['description'],
                'url' => self::CAMPAIGN['url'],
                'starts_at' => now()->subDays(self::CAMPAIGN['starts_days_ago']),
                'ends_at' => now()->addDays(self::CAMPAIGN['ends_in_days']),
            ],
        );

        Coupon::query()->updateOrCreate(
            ['code' => self::COUPON_CODE],
            ['type' => 'percent', 'value' => 15, 'expires_at' => now()->addDays(30)],
        );

        $featured = array_values(array_filter(array_map(
            fn (int $n) => ($fixtures[$n] ?? null)?->id,
            self::FEATURED,
        )));
        Product::query()->whereIn('id', $featured)->update(['featured' => true]);

        $orders = 0;
        foreach (self::SALES as $fixtureNumber => $units) {
            $product = $fixtures[$fixtureNumber] ?? null;
            if ($product === null) {
                continue;
            }

            // The price the buyer would actually have paid on that day.
            $paidMinor = (int) ($product->price?->sale_minor ?? $product->price?->regular_minor ?? 0);

            for ($n = 1; $n <= $units; $n++) {
                $order = Order::query()->updateOrCreate(
                    ['number' => sprintf('%s%02d-%02d', self::SKU_PREFIX, $fixtureNumber, $n)],
                    [
                        'email' => self::FIXTURE_EMAIL,
                        'status' => 'COMPLETED',
                        'paid_at' => now()->subDays($n),
                        'totals_gross_minor' => $paidMinor,
                        'total_minor' => $paidMinor,
                    ],
                );

                OrderItem::query()->updateOrCreate(
                    ['order_id' => $order->id, 'product_id' => $product->id],
                    ['title_snapshot' => $product->title, 'price_snapshot_minor' => $paidMinor],
                );

                $orders++;
            }
        }

        $this->command?->info(sprintf(
            '[DEV FIXTURE] DesignPreviewSeeder wrote homepage content: %d settings keys (%s), '
            .'1 campaign ending in %d days, coupon %s, %d featured products and %d PAID fixture orders '
            .'(order numbers prefixed %s, buyer %s). Fixture copy and fixture purchases for design review — '
            .'not approved marketing copy and not real revenue.',
            count(self::HOME_CONTENT),
            implode(', ', array_keys(self::HOME_CONTENT)),
            self::CAMPAIGN['ends_in_days'],
            self::COUPON_CODE,
            count($featured),
            $orders,
            self::SKU_PREFIX,
            self::FIXTURE_EMAIL,
        ));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Covers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A generated cover per fixture, on BOTH surfaces the codebase already has:
     *
     *  1. `assets` + `product_files` via App\Domain\Assets\AssetImporter with
     *     visibility=public_sample and the non-deliverable `sample` role. That
     *     is the ledger: checksum, dedupe and reference counting, exactly like
     *     any other file the store holds.
     *  2. `product_media` with is_cover=1 — the table MetadataService,
     *     ProductPageSeo and ProductReadiness already read. It stores a URL,
     *     and the ledger cannot supply one: LocalSignedProvider deliberately
     *     has NO key-addressable URL (paid-content sanctity), so the renderable
     *     copy goes to the `public` disk, which is Laravel's own place for a
     *     publicly servable file. Same bytes, same sha, two honest roles.
     *
     * The SVG source is committed under database/seeders/preview-assets/ and is
     * rewritten only when the deterministic render would differ — a seed run on
     * an up-to-date checkout writes nothing back into the repository.
     *
     * @param  array<int, Product>  $fixtures  1-based fixture number => row
     * @param  array<int, array<string, mixed>>  $meta
     */
    private function seedCovers(array $fixtures, array $meta): void
    {
        /** @var AssetImporter $importer */
        $importer = app(AssetImporter::class);
        $dir = self::coverDir();
        File::ensureDirectoryExists($dir);

        // Without the symlink /storage/… 404s and the preview would show broken
        // images instead of the design. Idempotent, local only.
        //
        // --relative is required, not cosmetic: the default writes an absolute
        // host path, and nginx/php-fpm see this tree at /var/www/html, where
        // that path does not exist. The link then dangles inside the containers
        // while resolving fine on the host — so every cover 404s and nothing
        // says why. A relative link resolves identically in both.
        if (app()->environment('local') && ! file_exists(public_path('storage'))) {
            Artisan::call('storage:link', ['--relative' => true]);
        }

        $covers = 0;
        $now = now();

        foreach ($fixtures as $number => $product) {
            $sku = (string) $product->sku;
            $svg = $this->coverSvg($meta[$number]);
            $path = $dir.'/'.$sku.'.svg';

            if (! is_file($path) || file_get_contents($path) !== $svg) {
                File::put($path, $svg);
            }

            $importer->import(
                product: $product,
                sourcePath: $path,
                role: self::COVER_ROLE,
                customerLabel: 'غلاف معاينة — '.$product->title.'.svg',
                visibility: 'public_sample',
                archiveExisting: true,
            );

            $object = self::COVER_PUBLIC_DIR.'/'.$sku.'.svg';
            Storage::disk('public')->put($object, $svg);

            $url = '/storage/'.$object;
            $values = [
                // Honest alt text: it says the image is a generated preview
                // cover, because that is exactly what it is.
                'alt_ar' => 'غلاف معاينة مولّد — '.$product->title,
                'width' => self::COVER_W,
                'height' => self::COVER_H,
                'position' => 0,
                'is_cover' => true,
                'updated_at' => $now,
            ];

            $updated = DB::table('product_media')
                ->where('product_id', $product->id)
                ->where('url', $url)
                ->update($values);

            if ($updated === 0) {
                DB::table('product_media')->insert(
                    ['product_id' => $product->id, 'url' => $url, 'created_at' => $now] + $values,
                );
            }

            $covers++;
        }

        $this->command?->info(sprintf(
            '[DEV FIXTURE] DesignPreviewSeeder generated %d SVG cover images from the design tokens and attached '
            .'each as its product cover (assets.visibility=public_sample, product_files.role=%s, product_media.is_cover=1, '
            .'served from %s). These are GENERATED PLACEHOLDERS for design review — not photography, not a real product '
            .'cover, and every one carries a visible «%s» caption. No paid file is ever exposed this way.',
            $covers,
            self::COVER_ROLE,
            '/storage/'.self::COVER_PUBLIC_DIR.'/',
            self::COVER_CAPTION,
        ));
    }

    /** Where the committed SVG corpus lives. */
    public static function coverDir(): string
    {
        return database_path('seeders/preview-assets');
    }

    /**
     * One deterministic cover: identical input always renders identical bytes,
     * so the corpus never churns and the asset ledger dedupes cleanly.
     *
     * Composition is the artboards' own vocabulary — a deep header band, a gold
     * rule, a tinted panel, the subject in Arabic, the grade and semester
     * lines, the primary fact, a format chip and an editable chip, plus a flat
     * mark that differs per format. No stock imagery, no emoji, no invented
     * logo, no embedded font.
     *
     * @param  array<string, mixed>  $meta
     */
    private function coverSvg(array $meta): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $subject = (string) $meta['subject'];
        $size = match (true) {
            mb_strlen($subject) > 15 => 62,
            mb_strlen($subject) > 9 => 74,
            default => 88,
        };

        $editableLabel = $meta['editable'] === 'yes' ? 'قابل للتعديل' : 'جاهز للطباعة';
        $fact = $this->primaryFactLabel($meta['facts']);

        $svg = [];
        $svg[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" role="img" aria-labelledby="cover-title" lang="ar">',
            self::COVER_W, self::COVER_H, self::COVER_W, self::COVER_H,
        );
        $svg[] = '  <title id="cover-title">'.$e($meta['title'].' — '.self::COVER_CAPTION).'</title>';
        $svg[] = '  <rect width="1200" height="900" fill="'.self::SURFACE.'"/>';

        // Header band + gold rule.
        $svg[] = '  <rect width="1200" height="132" fill="'.self::DEEP.'"/>';
        $svg[] = '  <rect y="132" width="1200" height="6" fill="'.self::GOLD.'"/>';
        $svg[] = $this->text(1120, 84, $e($meta['kind']), 42, self::SURFACE, 'right', 700);
        $svg[] = $this->text(80, 84, $e($meta['semester']), 28, self::GOLD, 'left');

        // Tinted panel.
        $svg[] = '  <rect x="72" y="196" width="1056" height="470" rx="28" fill="'.self::BRAND.'" fill-opacity="0.06" stroke="'.self::BORDER.'"/>';

        // Right column — the file's identity, read right-to-left.
        $svg[] = $this->text(1064, 320, $e($subject), $size, self::DEEP, 'right', 700);
        $svg[] = $this->text(1064, 396, $e((string) $meta['grade']), 40, self::INK, 'right', 400, '0.78');
        $svg[] = $this->text(1064, 452, $e((string) $meta['format'].' · '.$editableLabel), 32, self::INK, 'right', 400, '0.55');
        if ($fact !== null) {
            $svg[] = $this->text(1064, 508, $e($fact), 32, self::BRAND, 'right', 700);
        }

        // Chips: format (solid brand) then editable (outlined).
        $svg[] = '  <rect x="914" y="552" width="150" height="60" rx="30" fill="'.self::BRAND.'"/>';
        $svg[] = $this->text(989, 592, $e((string) $meta['format']), 30, self::SURFACE, 'center', 700);
        $svg[] = '  <rect x="638" y="552" width="256" height="60" rx="30" fill="'.self::SURFACE.'" stroke="'.self::BORDER.'"/>';
        $svg[] = $this->text(766, 592, $e($editableLabel), 28, self::INK, 'center');

        // Left mark — a flat glyph of the artefact this file actually is.
        $svg[] = $this->coverMark((string) $meta['format']);

        // Footer: the brand, and the caption that keeps the image honest.
        $svg[] = '  <rect x="80" y="762" width="1040" height="2" fill="'.self::BORDER.'"/>';
        $svg[] = $this->text(1120, 836, $e('التحاضير الحديثة'), 30, self::DEEP, 'right', 700);
        $svg[] = $this->text(80, 836, $e(self::COVER_CAPTION), 26, self::INK, 'left', 400, '0.45');

        $svg[] = '</svg>';

        return implode("\n", $svg)."\n";
    }

    /**
     * One `<text>` node with the shared font stack.
     *
     * Two RTL details that are load-bearing, not decoration:
     *  · direction="rtl" sits on the TEXT, never on the root. Without it the
     *    bidi algorithm runs LTR and «62 شريحة» / «PPTX · قابل للتعديل» put the
     *    Latin run on the wrong side of the Arabic one; on the root element a
     *    browser applies it to the whole replaced-element layout instead and
     *    pushes the drawing out of its own viewport.
     *  · `text-anchor` is start/end in the INLINE direction, so under rtl
     *    `start` is the right edge. $align is therefore stated physically
     *    (right/left/center) and mapped here — writing `end` for «align right»
     *    is precisely the bug this mapping exists to prevent.
     */
    private function text(
        int $x,
        int $y,
        string $content,
        int $size,
        string $fill,
        string $align = 'right',
        int $weight = 400,
        ?string $opacity = null,
    ): string {
        $anchor = match ($align) {
            'left' => 'end',
            'center' => 'middle',
            default => 'start',
        };

        return sprintf(
            '  <text x="%d" y="%d" text-anchor="%s" direction="rtl" unicode-bidi="embed" font-family="%s" font-size="%d" font-weight="%d" fill="%s"%s>%s</text>',
            $x,
            $y,
            $anchor,
            htmlspecialchars(self::FONT, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $size,
            $weight,
            $fill,
            $opacity === null ? '' : ' fill-opacity="'.$opacity.'"',
            $content,
        );
    }

    /** A slide for PPTX, a gridded worksheet for PDF, a written page for DOCX. */
    private function coverMark(string $format): string
    {
        $stroke = ' fill="'.self::SURFACE.'" stroke="'.self::DEEP.'" stroke-opacity="0.25" stroke-width="3"';
        $tint = self::BRAND;
        $parts = ['  <g>'];

        if ($format === 'PPTX') {
            $parts[] = '    <rect x="128" y="300" width="300" height="200" rx="14"'.$stroke.'/>';
            $parts[] = '    <rect x="180" y="400" width="48" height="70" rx="8" fill="'.$tint.'" fill-opacity="0.45"/>';
            $parts[] = '    <rect x="248" y="350" width="48" height="120" rx="8" fill="'.self::GOLD.'" fill-opacity="0.75"/>';
            $parts[] = '    <rect x="316" y="380" width="48" height="90" rx="8" fill="'.$tint.'" fill-opacity="0.7"/>';
            $parts[] = '    <rect x="160" y="536" width="236" height="12" rx="6" fill="'.$tint.'" fill-opacity="0.18"/>';
            $parts[] = '    <rect x="212" y="566" width="132" height="12" rx="6" fill="'.$tint.'" fill-opacity="0.18"/>';
        } elseif ($format === 'PDF') {
            $parts[] = '    <rect x="128" y="256" width="260" height="340" rx="14"'.$stroke.'/>';
            for ($i = 1; $i <= 4; $i++) {
                $y = 256 + $i * 68;
                $parts[] = sprintf(
                    '    <rect x="128" y="%d" width="260" height="2" fill="%s" fill-opacity="0.22"/>',
                    $y, $tint,
                );
            }
            foreach ([215, 302] as $x) {
                $parts[] = sprintf(
                    '    <rect x="%d" y="256" width="2" height="340" fill="%s" fill-opacity="0.22"/>',
                    $x, $tint,
                );
            }
            $parts[] = '    <rect x="152" y="284" width="96" height="14" rx="7" fill="'.self::GOLD.'" fill-opacity="0.85"/>';
        } else {
            $parts[] = '    <rect x="128" y="256" width="260" height="340" rx="14"'.$stroke.'/>';
            $parts[] = '    <path d="M128 316 L188 256 L128 256 Z" fill="'.self::GOLD.'" fill-opacity="0.6"/>';
            foreach ([[352, 200], [396, 224], [440, 168], [484, 210], [528, 128]] as [$y, $w]) {
                $parts[] = sprintf(
                    '    <rect x="%d" y="%d" width="%d" height="12" rx="6" fill="%s" fill-opacity="0.20"/>',
                    388 - 32 - $w, $y, $w, $tint,
                );
            }
        }

        $parts[] = '  </g>';

        return implode("\n", $parts);
    }

    /**
     * «62 شريحة» / «86 صفحة» — built from a REAL product_facts row, or null
     * when the fixture declares no countable fact.
     *
     * @param  array<string, int>  $facts
     */
    private function primaryFactLabel(array $facts): ?string
    {
        foreach ($facts as $key => $value) {
            if (isset(self::FACT_NOUNS[$key])) {
                return $value.' '.self::FACT_NOUNS[$key];
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PDP depth
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Authored FAQ rows (§7.4). Every answer is derived from data the product
     * row ACTUALLY carries — its facts, its format, its editability, its
     * curriculum placement — so the visible FAQ and the FAQPage graph it feeds
     * can never claim something the catalogue does not hold.
     *
     * @param  array<int, Product>  $fixtures
     * @param  array<int, array<string, mixed>>  $meta
     */
    private function seedFaqs(array $fixtures, array $meta): void
    {
        $rows = 0;

        foreach ($fixtures as $number => $product) {
            $m = $meta[$number];
            $contents = [];
            foreach ($m['facts'] as $key => $value) {
                if (isset(self::FACT_NOUNS[$key])) {
                    $contents[] = $value.' '.self::FACT_NOUNS[$key];
                }
            }

            $faqs = [
                [
                    'question' => sprintf('ماذا يتضمّن ملف %s لمادة %s؟', $m['kind'], $m['subject']),
                    'answer' => $contents === []
                        ? sprintf('الملف مُعدّ لمادة %s — %s، %s بصيغة %s.', $m['subject'], $m['grade'], $m['semester'], $m['format'])
                        : sprintf(
                            'يتضمّن الملف %s، وهو مُعدّ لمادة %s — %s، %s بصيغة %s.',
                            implode(' و', $contents), $m['subject'], $m['grade'], $m['semester'], $m['format'],
                        ),
                ],
                [
                    'question' => 'هل يمكنني تعديل الملف؟',
                    'answer' => $m['editable'] === 'yes'
                        ? sprintf('نعم، الملف بصيغة %s قابلة للتعديل بالكامل، فتغيّر اسم المدرسة والمعلّم والتواريخ كما تشاء.', $m['format'])
                        : sprintf('الملف بصيغة %s وجاهز للطباعة كما هو، وغير قابل للتعديل.', $m['format']),
                ],
                [
                    'question' => 'متى يصلني الملف بعد الدفع؟',
                    'answer' => 'يظهر رابط التحميل مباشرة في صفحة الطلب بعد إتمام الدفع، ويبقى الملف متاحًا في حسابك للتحميل مرة أخرى.',
                ],
                [
                    'question' => sprintf('هل الملف مناسب لـ%s؟', $m['grade']),
                    'answer' => sprintf(
                        'نعم، هذا الملف مُصنَّف لـ%s في %s ضمن مادة %s وفق الخطة المعتمدة للعام الدراسي الحالي.',
                        $m['grade'], $m['semester'], $m['subject'],
                    ),
                ],
            ];

            foreach ($faqs as $position => $faq) {
                ProductFaq::query()->updateOrCreate(
                    ['product_id' => $product->id, 'question' => $faq['question']],
                    ['answer' => $faq['answer'], 'position' => $position],
                );
                $rows++;
            }
        }

        $this->command?->info(sprintf(
            '[DEV FIXTURE] DesignPreviewSeeder wrote %d product FAQ rows across %d products. Every answer is derived '
            .'from the fixture\'s own facts, format and curriculum placement — fixture Q&A for design review, '
            .'not published editorial content.',
            $rows,
            count($fixtures),
        ));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reviews
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Fixture reviews, written the ONLY way the platform allows one to exist:
     * a fixture customer, a REAL paid fixture order for that exact product, a
     * ReviewService::submit that re-verifies the purchase, and an explicit
     * approve. Nothing is inserted straight into `reviews`, and nothing at all
     * is inserted into `review_aggregates` — AggregateRebuilder recomputes that
     * table from the approved rows at the end, exactly as the outbox consumer
     * does in production.
     *
     * Side effect, stated plainly: these orders are real paid fixture orders,
     * so they also count towards «الأكثر مبيعًا», which ranks by paid
     * `order_items`. That is the honest consequence of a review needing a
     * purchase — not a second, invented sales number.
     *
     * @param  array<int, Product>  $fixtures  1-based fixture number => row
     */
    private function seedReviews(array $fixtures): void
    {
        /** @var ReviewService $service */
        $service = app(ReviewService::class);

        /** @var array<int, Customer> $customers */
        $customers = [];
        foreach (self::REVIEWERS as $i => $name) {
            $customers[$i] = Customer::query()->updateOrCreate(
                ['email' => sprintf('design-preview-reviewer-%02d@example.invalid', $i + 1)],
                ['name' => $name, 'locale' => 'ar', 'marketing_consent' => false],
            );
        }

        // Counts rows this run ENSURED are present and approved — new on a
        // first seed, already-there on a re-seed. Either way the number the log
        // prints is the number of fixture opinions the preview now holds.
        $written = 0;
        $votes = 0;
        $replies = 0;
        $reviewed = 0;

        foreach (self::REVIEW_PLAN as $number => $ratings) {
            $product = $fixtures[$number] ?? null;
            if ($product === null) {
                continue;
            }

            $publishedDaysAgo = (int) self::FIXTURES[$number - 1]['published_days_ago'];
            $paidMinor = (int) ($product->price?->sale_minor ?? $product->price?->regular_minor ?? 0);

            /** @var array<int, Review> $created */
            $created = [];

            foreach ($ratings as $i => $rating) {
                $customer = $customers[$i % count($customers)];

                // Reviews are spread across the product's published life, so a
                // 4-day-old file cannot carry a 3-month-old opinion.
                $daysAgo = max(1, (int) round($publishedDaysAgo * ($i + 1) / (count($ratings) + 1)));
                $paidDaysAgo = min($publishedDaysAgo, $daysAgo + 2);

                $order = Order::query()->updateOrCreate(
                    ['number' => sprintf('%sR%02d-%02d', self::SKU_PREFIX, $number, $i + 1)],
                    [
                        'customer_id' => $customer->id,
                        'email' => $customer->email,
                        'status' => 'COMPLETED',
                        'paid_at' => now()->subDays($paidDaysAgo),
                        'totals_gross_minor' => $paidMinor,
                        'total_minor' => $paidMinor,
                    ],
                );

                OrderItem::query()->updateOrCreate(
                    ['order_id' => $order->id, 'product_id' => $product->id],
                    [
                        'title_snapshot' => $product->title,
                        'price_snapshot_minor' => $paidMinor,
                        'version_label' => $number % 2 === 0 ? self::FIXTURE_VERSION : null,
                    ],
                );

                try {
                    $review = $service->submit($customer, (int) $product->id, [
                        'rating' => $rating,
                        'title' => $i % 2 === 0 ? self::pick(self::REVIEW_TITLES[$rating], $number + $i) : null,
                        'body' => self::pick(self::REVIEW_BODIES[$rating], $number + $i),
                        'categoryScores' => $i % 3 === 0 ? self::categoryScores($rating) : [],
                    ]);
                } catch (ReviewDenied) {
                    // Re-run: this customer already reviewed this product.
                    $review = Review::query()
                        ->where('product_id', $product->id)
                        ->where('customer_id', $customer->id)
                        ->first();

                    if ($review === null) {
                        continue;
                    }
                }

                $service->approve($review);
                $written++;

                // Backdating fixture timestamps only. No aggregate depends on
                // them, so nothing is bypassed — the feed just stops showing a
                // three-month-old catalogue whose every opinion arrived today.
                $when = now()->subDays($daysAgo);
                Review::query()->whereKey($review->id)->update([
                    'created_at' => $when,
                    'updated_at' => $when,
                    'moderated_at' => $when,
                ]);

                $created[$i] = $review;
            }

            if ($created !== []) {
                $reviewed++;
            }

            // A store reply on the product's lowest-rated review — the design
            // has to show what an answered complaint looks like.
            $replyBody = self::REPLIES[$number] ?? null;
            $lowest = $ratings === [] ? null : array_search(min($ratings), $ratings, true);
            if ($replyBody !== null && $lowest !== false && isset($created[$lowest])) {
                $service->reply($created[$lowest], $replyBody);
                $replies++;
            }

            // Helpful votes, cast by accounts other than the author, so the
            // default «الأكثر إفادة» sort has a real order to apply.
            $wanted = self::HELPFUL[$number] ?? 0;
            if ($wanted > 0 && isset($created[0])) {
                for ($v = 0; $v < $wanted; $v++) {
                    $voter = $customers[count($customers) - 1 - $v];
                    if ((int) $voter->id === (int) $created[0]->customer_id) {
                        continue;
                    }
                    try {
                        $service->voteHelpful($created[0], $voter);
                        $votes++;
                    } catch (ReviewDenied) {
                        // Re-run: this account already voted. Nothing to do.
                    }
                }
            }
        }

        $rebuilt = app(AggregateRebuilder::class)->rebuildAll();
        $reviewFree = count($fixtures) - $reviewed;

        $this->command?->info(sprintf(
            '[DEV FIXTURE] DesignPreviewSeeder seeded %d APPROVED fixture reviews by %d fixture accounts across %d products, '
            .'plus %d store replies and %d helpful votes. THESE ARE FIXTURE OPINIONS FOR DESIGN REVIEW — nobody said them, '
            .'and they are not real customer feedback. Each one is backed by a real fixture order (numbers prefixed %sR, '
            .'@example.invalid buyers), which is also why those orders now count towards the bestsellers ranking. '
            .'review_aggregates was REBUILT from the approved rows for %d products by AggregateRebuilder — never typed in. '
            .'%d fixtures were left review-free on purpose so the honest «no reviews yet» state stays previewable.',
            $written,
            count($customers),
            $reviewed,
            $replies,
            $votes,
            self::SKU_PREFIX,
            $rebuilt,
            $reviewFree,
        ));
    }

    /**
     * Deterministic choice from a list — the same fixture always gets the same
     * copy, so a re-seed never reshuffles the preview.
     *
     * @param  list<string>  $options
     */
    private static function pick(array $options, int $seed): string
    {
        return $options[$seed % count($options)];
    }

    /**
     * Per-criterion scores around the overall star, clamped to 1–5. Derived
     * from the rating rather than drawn at random, so the breakdown can never
     * contradict the star the same reviewer gave.
     *
     * @return array<string, int>
     */
    private static function categoryScores(int $rating): array
    {
        $offsets = [
            'content_quality' => 0,
            'edit_ease' => 1,
            'design_quality' => 0,
            'worksheets_value' => -1,
            'description_match' => 0,
            'download_ease' => 1,
        ];

        $scores = [];
        foreach ($offsets as $criterion => $offset) {
            $scores[$criterion] = max(1, min(5, $rating + $offset));
        }

        return $scores;
    }
}
