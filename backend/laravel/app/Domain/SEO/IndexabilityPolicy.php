<?php

namespace App\Domain\SEO;

use App\Models\Product;

/**
 * سياسة الفهرسة — المصدر الوحيد (MIP §7.3 «IndexabilityPolicy shared by
 * sitemaps/llms.txt/IndexNow/link-graph»).
 *
 * Sitemaps, llms.txt, IndexNow and the internal link graph all ask THIS class.
 * There is no second opinion anywhere in the codebase: if a URL is not
 * `eligible` here, it cannot appear in any of those four surfaces.
 *
 * Implements the §7.1 indexation matrix. Faceted-navigation law: a filtered or
 * sorted URL canonicalises to its clean parent and is never indexable.
 */
final class IndexabilityPolicy
{
    /**
     * Private / transactional / machine surfaces — §7.1 rows that are noindex
     * regardless of content.
     *
     * @var array<string, string>
     */
    private const EXCLUDED_PREFIXES = [
        '/cart' => 'صفحة سلة — معاملات، خارج الفهرسة',
        '/checkout' => 'صفحة دفع — معاملات، خارج الفهرسة',
        '/account' => 'منطقة حساب — تتطلب تسجيل دخول',
        '/orders' => 'صفحة طلب — خاصة بالمشتري',
        '/pay' => 'صفحة سداد — معاملات، خارج الفهرسة',
        '/wishlist' => 'قائمة أمنيات — خاصة بالمستخدم',
        '/compare' => 'صفحة مقارنة — أداة واجهة لا صفحة محتوى',
        '/login' => 'صفحة دخول — خارج الفهرسة',
        '/search' => 'صفحة بحث — واجهة بحث لا صفحة محتوى',
        '/api' => 'واجهة برمجية — محجوبة عن الزواحف',
        '/admin' => 'لوحة إدارة — محجوبة ومحمية بصلاحيات',
    ];

    /**
     * Paid-asset surfaces. «حرمة الملف المدفوع» — a downloadable original or a
     * signed URL must NEVER be exposed to a crawler, an LLM index or a feed.
     *
     * @var list<string>
     */
    private const PAID_ASSET_PREFIXES = [
        '/downloads',
        '/download',
        '/files',
        '/secure',
        '/entitlements',
        '/storage/paid',
        '/media/paid',
    ];

    /** File extensions that can only ever be a paid original in this catalog. */
    private const PAID_ASSET_EXTENSIONS = ['pdf', 'pptx', 'ppt', 'docx', 'doc', 'xlsx', 'xls', 'zip'];

    /**
     * Query keys that turn an archive into a filtered/sorted view (§7.1).
     *
     * @var list<string>
     */
    private const FILTER_PARAMS = [
        'orderby', 'order', 'sort', 'min_price', 'max_price',
        'rating_filter', 'add-to-cart', 'paged', 'page',
    ];

    /** Query key prefixes that mark a facet combination. */
    private const FILTER_PREFIXES = ['th_', 'filter_', 'facet_'];

    /** §7.2: a programmatic hub needs real inventory before it may be indexed. */
    public const HUB_MIN_PRODUCTS = 3;

    /**
     * Verdict for a URL path (with or without a query string).
     *
     * Order matters: paid assets are checked before anything else so a paid
     * file can never be rescued by a later rule.
     */
    public function forPath(string $path): IndexabilityVerdict
    {
        $path = trim($path);

        if ($path === '') {
            return IndexabilityVerdict::excluded('مسار فارغ', 'IDX-EMPTY');
        }

        // Absolute URLs are reduced to their path + query for judging.
        if (preg_match('#^https?://#i', $path) === 1) {
            $parts = parse_url($path);
            $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        $path = '/'.ltrim($path, '/');
        [$clean, $query] = array_pad(explode('?', $path, 2), 2, null);
        $clean = rtrim($clean, '/');
        $clean = $clean === '' ? '/' : $clean;
        $lower = mb_strtolower($clean);

        if ($this->isPaidAsset($lower)) {
            return IndexabilityVerdict::excluded(
                'رابط ملف مدفوع — لا يُكشف لأي زاحف أو فهرس',
                'IDX-PAID-ASSET',
            );
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix => $reason) {
            if ($lower === $prefix || str_starts_with($lower, $prefix.'/')) {
                return IndexabilityVerdict::excluded($reason, 'IDX-PRIVATE-SURFACE');
            }
        }

        if ($query !== null && $query !== '' && $this->hasFilterParam($query)) {
            return IndexabilityVerdict::excluded(
                'رابط مُرشَّح أو مُرتَّب — يُوحَّد canonical إلى الأصل النظيف قبل أي حجب',
                'IDX-FACET',
            );
        }

        return IndexabilityVerdict::eligible('مسار عام قابل للفهرسة', 'IDX-PUBLIC');
    }

    /**
     * Verdict for a product. A product URL is only eligible when the product is
     * really published and really purchasable-or-listable.
     *
     * Retired products are excluded: MIP §7.1 keeps digital URLs alive with
     * OutOfStock rather than 404ing them, but a retired product is not a
     * sitemap candidate.
     */
    public function forProduct(Product $product): IndexabilityVerdict
    {
        $pathVerdict = $this->forPath('/products/'.$product->slug);

        if (! $pathVerdict->isEligible()) {
            return $pathVerdict;
        }

        $status = (string) ($product->status ?? '');

        if ($status === 'retired' || $status === 'archived') {
            return IndexabilityVerdict::excluded(
                'منتج متقاعد — يُوجَّه إلى البديل ولا يدخل خريطة الموقع',
                'IDX-RETIRED',
            );
        }

        if ($status !== 'published') {
            return IndexabilityVerdict::excluded(
                'منتج غير منشور — الحالة: '.($status === '' ? 'غير محددة' : $status),
                'IDX-UNPUBLISHED',
            );
        }

        if ($product->published_at === null) {
            return IndexabilityVerdict::monitor(
                'منشور بلا تاريخ نشر — تحت المراقبة حتى يُضبط التاريخ',
                'IDX-NO-PUBLISH-DATE',
            );
        }

        if ($product->published_at->isFuture()) {
            return IndexabilityVerdict::monitor(
                'تاريخ النشر في المستقبل — تحت المراقبة حتى يحين',
                'IDX-SCHEDULED',
            );
        }

        // No price row ⇒ no honest Offer can be emitted; watch, do not publish.
        if ($product->relationLoaded('price') && $product->price === null) {
            return IndexabilityVerdict::monitor(
                'لا يوجد سعر مسجَّل — لا يمكن إصدار عرض صادق',
                'IDX-NO-PRICE',
            );
        }

        return IndexabilityVerdict::eligible('منتج منشور بمسار نظيف', 'IDX-PRODUCT');
    }

    /**
     * Verdict for a programmatic hub / archive that depends on inventory
     * (§7.2: index only at ≥3 products).
     */
    public function forCollection(string $path, int $productCount): IndexabilityVerdict
    {
        $pathVerdict = $this->forPath($path);

        if (! $pathVerdict->isEligible()) {
            return $pathVerdict;
        }

        if ($productCount < self::HUB_MIN_PRODUCTS) {
            return IndexabilityVerdict::monitor(
                'المخزون '.$productCount.' من '.self::HUB_MIN_PRODUCTS.' — دون عتبة الفهرسة',
                'IDX-THIN-INVENTORY',
            );
        }

        return IndexabilityVerdict::eligible(
            'تجميعة مأهولة بـ'.$productCount.' منتجًا',
            'IDX-COLLECTION',
        );
    }

    /** True when the path points at a paid original or a protected download. */
    private function isPaidAsset(string $lower): bool
    {
        foreach (self::PAID_ASSET_PREFIXES as $prefix) {
            if ($lower === $prefix || str_starts_with($lower, $prefix.'/')) {
                return true;
            }
        }

        $extension = mb_strtolower(pathinfo($lower, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::PAID_ASSET_EXTENSIONS, true);
    }

    /** True when the query string contains any facet/sort/action parameter. */
    private function hasFilterParam(string $query): bool
    {
        parse_str($query, $params);

        foreach (array_keys($params) as $key) {
            $key = mb_strtolower((string) $key);

            if (in_array($key, self::FILTER_PARAMS, true)) {
                return true;
            }

            foreach (self::FILTER_PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
