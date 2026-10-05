<?php

namespace App\Domain\Insights;

use App\Domain\Insights\Aggregators\AcquisitionInsight;
use App\Domain\Insights\Aggregators\CartsInsight;
use App\Domain\Insights\Aggregators\CustomersInsight;
use App\Domain\Insights\Aggregators\FunnelInsight;
use App\Domain\Insights\Aggregators\OcrInsight;
use App\Domain\Insights\Aggregators\PagesInsight;
use App\Domain\Insights\Aggregators\ProductsInsight;
use App\Domain\Insights\Aggregators\RefundsInsight;
use Illuminate\Contracts\Container\Container;

/**
 * سجلّ النطاقات — الاسم الذي يصل من الرابط إلى المُجمِّع الذي يجيبه.
 *
 * ─── لماذا سجلٌّ لا `match` في وحدة التحكّم ───
 *
 * الأسماء المتاحة تُعلَن في موضعٍ واحد، فتقرؤها وحدة التحكّم للتحقّق،
 * ويقرؤها الاختبار ليتأكّد أنّ كلّ نطاقٍ مسجَّلٍ يردّ فعلًا. نطاقٌ يُضاف إلى
 * `match` وحده كان سيمرّ بلا اختبار حتّى يفتحه مشغّل.
 *
 * ─── والوصف ليس زينة ───
 *
 * كلّ نطاقٍ يقول ما يقيسه قبل أن يُفتح، فلا يفتح المشغّل تسع لوحاتٍ ليعرف
 * أيّها يجيب سؤاله.
 */
final readonly class InsightsRegistry
{
    /** @var array<string, array{class-string<InsightAggregator>, string, string}> */
    private const DOMAINS = [
        'funnel' => [FunnelInsight::class, 'قمع التحويل', 'من سلّةٍ إلى طلبٍ إلى دفع — ونصفه الأعلى غير مقيس.'],
        'products' => [ProductsInsight::class, 'المنتجات', 'الرابحون، والمنشور الذي لم يبع، والطلب غير الملبّى.'],
        'customers' => [CustomersInsight::class, 'العملاء', 'من يشتري، ومن يكرّر، ومن على وشك أن يذهب.'],
        'carts' => [CartsInsight::class, 'السلال', 'ما وُضع في السلّة ولم يُشترَ، وكم يساوي.'],
        'refunds' => [RefundsInsight::class, 'الاستردادات', 'كم خرج، ولماذا، ومن يكرّره.'],
        'ocr' => [OcrInsight::class, 'استخراج المحتوى', 'الأصول المرفوعة — ولا خطّ استخراجٍ بعد.'],
        'acquisition' => [AcquisitionInsight::class, 'الاكتساب', 'القنوات — من مُحيل اللمسة الأولى، بلا كلفة.'],
        'pages' => [PagesInsight::class, 'إيراد الصفحات', 'أيّ صفحةٍ جلبت الشراء، وكم أنتجت.'],
    ];

    /*
     * ─── نطاقان غائبان عمدًا ───
     *
     * «البحث» و«الظهور» لهما تجميعٌ خادميّ قائم: `‎/admin/search/queries‎`
     * يحسب نسبة اللانتيجة ونسبة النقر ومتوسّط رتبته وأعلى الاستعلامات،
     * و`‎/admin/crawl‎` يحسب المكسور واليتيم والعميق. إعادةُ بنائهما هنا
     * تكرارٌ لمنطق عملٍ قائم — وهو ممنوع — وتُنتج رقمين للحقيقة الواحدة
     * يتباعدان مع أوّل تعديل. اللوحة تنادي النقطتين القائمتين كما هما.
     */

    public function __construct(private Container $container) {}

    /** @return list<string> */
    public function names(): array
    {
        return array_keys(self::DOMAINS);
    }

    public function has(string $domain): bool
    {
        return array_key_exists($domain, self::DOMAINS);
    }

    public function make(string $domain): InsightAggregator
    {
        return $this->container->make(self::DOMAINS[$domain][0]);
    }

    /** @return array{label: string, description: string} */
    public function describe(string $domain): array
    {
        return ['label' => self::DOMAINS[$domain][1], 'description' => self::DOMAINS[$domain][2]];
    }

    /** @return list<array{key: string, label: string, description: string}> */
    public function catalogue(): array
    {
        return array_values(array_map(
            fn (string $key) => ['key' => $key] + $this->describe($key),
            array_keys(self::DOMAINS),
        ));
    }
}
