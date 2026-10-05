<?php

namespace App\Domain\SEO;

use App\Domain\I18n\LanguageEligibility;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * خط أنابيب الوسوم — الكاتب الوحيد للعنوان والوصف وrobots وcanonical وOG.
 *
 * MIP §7.6. One owner per capability: nothing else in the codebase writes a
 * title, a meta description, a canonical or a robots directive.
 */
final class MetadataService
{
    /**
     * Logo used as the last resort in the OG image chain.
     *
     * `logo-og.png` لا وجود له: `apps/storefront/public/brand/` يحمل
     * `logo-full.png` و`logo-mark.png` وحدهما، فكانت كلّ صفحة منتجٍ بلا صورة
     * تُعلن صورة مشاركةٍ تردّ ٤٠٤. الملفّ المُعلَن يجب أن يكون موجودًا.
     */
    private const LOGO_PATH = '/brand/logo-full.png';

    public function __construct(
        private readonly SiteIdentity $site,
        private readonly IndexabilityPolicy $policy,
        private readonly LanguageEligibility $languages,
    ) {}

    /**
     * Full metadata for a product page.
     *
     * @return array<string, mixed> camelCase, ready for the API boundary
     */
    public function forProduct(Product $product): array
    {
        $seo = $product->relationLoaded('seo') ? $product->seo : $product->seo()->first();
        $path = '/products/'.$product->slug;

        $authoredTitle = TextBudget::normalize($seo->seo_title ?? null);
        $title = $this->title($authoredTitle ?? $product->title);
        $description = $this->description($product, $seo);
        $verdict = $this->policy->forProduct($product);
        $image = $this->imageFor($product);

        $canonicalOverride = TextBudget::normalize($seo->canonical_override ?? null);
        $canonical = $canonicalOverride !== null
            ? $this->absolute($canonicalOverride)
            : $this->site->url($path);

        return [
            'title' => $title['title'],
            'renderedTitle' => $title['renderedTitle'],
            // Two DIFFERENT §7.6 rules, reported separately and honestly.
            'titleLength' => $title['titleLength'],
            'overEditorBudget' => $title['overEditorBudget'],
            'renderedTitleAlarm' => $title['renderedTitleAlarm'],
            'description' => $description['text'],
            'descriptionSource' => $description['source'],
            'canonical' => $canonical,
            'canonicalIsSelf' => $canonicalOverride === null,
            /*
             * بدائل اللغة تُعلَن فقط لصفحات موجودة فعلًا: لغة مفعّلة، وواجهة
             * تخدم مسارها، وترجمة منشورة قابلة للفهرسة. إعلان hreflang لصفحة
             * غير مخدومة يُدخل روابط 404 إلى الفهرس ويُضعف الصفحة الأصل.
             */
            'alternates' => $this->alternates($product),
            'robots' => $verdict->robots(),
            'indexability' => $verdict->toArray(),
            'openGraph' => [
                'type' => 'product',
                'title' => $title['renderedTitle'],
                'description' => $description['text'],
                'url' => $canonical,
                'siteName' => SiteIdentity::BRAND,
                'locale' => 'ar_SA',
                'image' => $image['url'],
                'imageSource' => $image['source'],
            ],
            'twitter' => [
                'card' => $image['url'] !== null ? 'summary_large_image' : 'summary',
                'title' => $title['renderedTitle'],
                'description' => $description['text'],
                'image' => $image['url'],
            ],
        ];
    }

    /**
     * روابط hreflang المطلقة، أو قائمة فارغة حين لا نظير حقيقي.
     *
     * @return list<array{hreflang: string, href: string, isDefault: bool}>
     */
    private function alternates(Product $product): array
    {
        return array_map(fn (array $a): array => [
            'hreflang' => $a['hreflang'],
            'href' => $this->site->url($a['path']),
            'isDefault' => $a['isDefault'],
        ], $this->languages->alternatesFor($product));
    }

    /**
     * Title with BOTH §7.6 budgets reported.
     *
     * `title` is trimmed to the 60-char editor budget. `renderedTitle` adds the
     * brand suffix and is measured against the separate 68-char truncation
     * alarm — these are different rules and are never conflated.
     *
     * @return array{title: string, renderedTitle: string, titleLength: int, overEditorBudget: bool, renderedTitleAlarm: bool}
     */
    public function title(string $raw): array
    {
        $clean = TextBudget::normalize($raw) ?? '';
        $length = mb_strlen($clean);
        $title = TextBudget::words($clean, TextBudget::TITLE_BUDGET) ?? '';

        // No shared prefixes (§7.6) — the brand is a suffix only.
        $rendered = $title === '' ? SiteIdentity::BRAND : $title.' | '.SiteIdentity::BRAND;

        return [
            'title' => $title,
            'renderedTitle' => $rendered,
            'titleLength' => $length,
            'overEditorBudget' => $length > TextBudget::TITLE_BUDGET,
            'renderedTitleAlarm' => mb_strlen($rendered) > TextBudget::TITLE_ALARM,
        ];
    }

    /**
     * Meta description precedence chain, in the exact MIP §7.6 order for the
     * fields this schema actually stores:
     *
     *   authored seo_desc → quick_answer → short_desc → description
     *
     * The chain stops at the first field that carries real text. When every
     * field is empty the description is NULL — an absent description is honest;
     * an invented one is not.
     *
     * @return array{text: ?string, source: ?string}
     */
    public function description(Product $product, ?object $seo = null): array
    {
        $seo ??= $product->relationLoaded('seo') ? $product->seo : $product->seo()->first();

        $chain = [
            'seoDesc' => $seo->seo_desc ?? null,
            'quickAnswer' => $seo->quick_answer ?? null,
            'shortDesc' => $product->short_desc ?? null,
            'description' => $product->description ?? null,
        ];

        foreach ($chain as $source => $candidate) {
            $text = TextBudget::normalize(is_string($candidate) ? $candidate : null);

            if ($text !== null) {
                return [
                    'text' => TextBudget::words($text, TextBudget::DESCRIPTION_BUDGET),
                    'source' => $source,
                ];
            }
        }

        return ['text' => null, 'source' => null];
    }

    /**
     * OG/Twitter image fallback chain: product cover → gallery → logo.
     * `product_media` is read defensively — the media domain owns that table.
     *
     * `source` distinguishes a real product image from the logo of last resort,
     * so callers can tell an actual page image from an OG-only asset.
     *
     * @return array{url: ?string, source: ?string}
     */
    public function imageFor(Product $product): array
    {
        if (Schema::hasTable('product_media')) {
            $media = DB::table('product_media')
                ->where('product_id', $product->id)
                ->orderByDesc('is_cover')
                ->orderBy('position')
                ->first();

            if ($media !== null && ! empty($media->url)) {
                return [
                    'url' => $this->mediaUrl((string) $media->url),
                    'source' => ((int) ($media->is_cover ?? 0)) === 1 ? 'cover' : 'gallery',
                ];
            }
        }

        // Last resort in the chain. Only when a real origin is configured —
        // a relative logo path in an OG tag is useless to a crawler.
        if ($this->site->isConfigured()) {
            return ['url' => $this->site->url(self::LOGO_PATH), 'source' => 'logo'];
        }

        return ['url' => null, 'source' => null];
    }

    /** Absolute-ise a stored path without rewriting an already absolute URL. */
    private function absolute(string $value): string
    {
        return str_starts_with($value, 'http') ? $value : $this->site->url($value);
    }

    /**
     * أصل الوسائط ليس أصل المتجر.
     *
     * الصور مخزَّنة مسارات نسبية (`/storage/…`) و**لارافيل** هو من يخدمها، لا
     * المتجر. وكانت تُجعل مطلقةً بـ`SiteIdentity::url()` — أي على أصل واجهة
     * المتجر — فيخرج `og:image` و`twitter:image` وحقل `image` في JSON-LD على
     * مضيفٍ لا يملك الملفّ. قِيس حيًّا: الأصل العامّ يردّ ٤٠٤ على
     * `/storage/design-preview/DESIGN-PREVIEW-01.svg` وأصل الـAPI يردّ ٢٠٠.
     * والمصدر هو `app.url` لا الدالّة `url()`: الأخيرة تبني على جذر **الطلب
     * الجاري**، فتُنتج مضيف من نادى لا مضيف من يخدم — وتتغيّر قيمتها حين
     * تعمل الخدمة في طابور بلا طلب. و`app.url` هو المصدر نفسه الذي يبني عليه
     * `config/filesystems.php:44` عنوانَ القرص العامّ (`APP_URL.'/storage'`)،
     * فيتّحد مُصدِر العنوان مع خادمه.
     *
     * وبلا `app.url` يُترك المسار نسبيًّا ولا يُخترع مضيف — سياسة
     * `SiteIdentity` نفسها: الحذف أصدق من حدسٍ خاطئ.
     */
    private function mediaUrl(string $value): string
    {
        if (str_starts_with($value, 'http')) {
            return $value;
        }

        $origin = rtrim((string) config('app.url'), '/');

        return $origin === '' ? $value : $origin.'/'.ltrim($value, '/');
    }
}
