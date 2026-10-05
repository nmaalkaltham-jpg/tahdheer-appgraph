<?php

declare(strict_types=1);

namespace App\Domain\SEO;

use App\Models\Grade;
use App\Models\Kind;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * كتلة الروابط الداخليّة في صفحة المنتج — أربع مجموعاتٍ بترتيبٍ ثابت، كلٌّ
 * بثلاثة روابط على الأكثر، والمجموعة الفارغة تُحذف لا تُعرض فارغة.
 *
 * كلّ رابطٍ من الكتالوج الحقيقيّ: منتجاتٌ منشورة، وأرشيفاتُ تصنيفٍ مبنيّةٌ
 * بالمسار نفسه الذي يبنيه فتات الخبز والخريطة. رابطُ أرشيفٍ لمسارٍ غير
 * حيٍّ (`RouteRegistry`) لا يُصدَر: رابطٌ داخليّ إلى ٤٠٤ أسوأ من غيابه.
 *
 * «حسب الدرس»: نطاق المنهج لا يملك كيان «درس» اليوم (`CurriculumHubs` يولّد
 * محاور مادّة–صفّ فحسب)، فالمجموعة تغيب حتّى يوجد ما يُربَط به — لا تُخترع.
 */
final class InternalLinks
{
    private const PER_GROUP = 3;

    public function __construct(private readonly RouteRegistry $routes) {}

    /**
     * @return list<array{title: string, links: list<array{label: string, href: string}>}>
     */
    public function forProduct(Product $product): array
    {
        $groups = [
            $this->sameSubject($product),
            $this->sameKindOtherGrades($product),
            $this->byLesson($product),
            $this->gradeSubjects($product),
        ];

        return array_values(array_filter($groups, static fn (?array $g) => $g !== null));
    }

    /** «نفس المادة»: المادّة والصفّ نفسُهما في فصولٍ أخرى، ثمّ أرشيف المادّة. */
    private function sameSubject(Product $product): ?array
    {
        if ($product->subject === null || $product->grade === null) {
            return null;
        }

        $archive = $this->archiveLink(
            'subjects', '/subjects/', (string) $product->subject->slug,
            'كل ملفات '.$product->subject->name_ar.' '.$product->grade->name_ar,
        );

        $siblings = Product::query()->published()
            ->whereKeyNot($product->id)
            ->where('subject_id', $product->subject_id)
            ->where('grade_id', $product->grade_id)
            ->when($product->semester_id !== null, fn ($q) => $q->where(
                fn ($q) => $q->whereNull('semester_id')->orWhere('semester_id', '!=', $product->semester_id),
            ))
            ->orderByDesc('published_at')
            ->limit(self::PER_GROUP - ($archive === null ? 0 : 1))
            ->get(['id', 'title', 'slug']);

        return $this->group('نفس المادة', $this->productLinks($siblings), $archive);
    }

    /** «نفس النوع لصفوف أخرى»: النوع والمادّة نفسُهما في الصفّين المجاورين، ثمّ أرشيف النوع. */
    private function sameKindOtherGrades(Product $product): ?array
    {
        if ($product->resource_type === null || $product->subject === null || $product->grade === null) {
            return null;
        }

        $grade = $product->grade;
        $gradeLabel = str_starts_with((string) $grade->name_ar, 'الصف') ? $grade->name_ar : 'الصف '.$grade->name_ar;

        $kind = Kind::query()
            ->where('slug', $product->resource_type)
            ->orWhere('name_ar', $product->resource_type)
            ->first();

        $archive = $kind === null ? null : $this->archiveLink(
            'kinds', '/kinds/', (string) $kind->slug,
            'كل '.$product->resource_type.' '.$gradeLabel,
        );

        $adjacent = Grade::query()
            ->where('stage_id', $grade->stage_id)
            ->whereIn('position', [(int) $grade->position - 1, (int) $grade->position + 1])
            ->pluck('id');

        $siblings = $adjacent->isEmpty() ? new Collection : Product::query()->published()
            ->where('resource_type', $product->resource_type)
            ->where('subject_id', $product->subject_id)
            ->whereIn('grade_id', $adjacent)
            ->orderByDesc('published_at')
            ->limit(self::PER_GROUP - ($archive === null ? 0 : 1))
            ->get(['id', 'title', 'slug']);

        return $this->group('نفس النوع لصفوف أخرى', $this->productLinks($siblings), $archive);
    }

    /** «حسب الدرس»: لا كيان درسٍ في نطاق المنهج بعد ⇒ لا مجموعة. */
    private function byLesson(Product $product): ?array
    {
        return null;
    }

    /** «مواد الصف»: موادّ أخرى للصفّ والفصل نفسيهما، كلٌّ إلى أرشيف مادّتها. */
    private function gradeSubjects(Product $product): ?array
    {
        if ($product->grade === null || ! $this->routes->isLive('subjects')) {
            return null;
        }

        $grade = $product->grade;
        $gradeLabel = str_starts_with((string) $grade->name_ar, 'الصف') ? $grade->name_ar : 'الصف '.$grade->name_ar;

        $others = Product::query()->published()
            ->with('subject:id,slug,name_ar')
            ->where('grade_id', $product->grade_id)
            ->when($product->semester_id !== null, fn ($q) => $q->where('semester_id', $product->semester_id))
            ->whereNotNull('subject_id')
            ->when($product->subject_id !== null, fn ($q) => $q->where('subject_id', '!=', $product->subject_id))
            ->orderByDesc('published_at')
            ->get(['id', 'subject_id', 'resource_type'])
            ->filter(fn (Product $p) => $p->subject !== null)
            ->unique('subject_id')
            ->take(self::PER_GROUP);

        $links = $others->map(fn (Product $p) => [
            'label' => trim(($p->resource_type ?? '').' '.$p->subject->name_ar).' — '.$grade->name_ar,
            'href' => TaxonomyPath::join('/subjects/', (string) $p->subject->slug),
        ])->values()->all();

        return $this->group('مواد '.$gradeLabel, $links, null);
    }

    /** @return array{label: string, href: string}|null */
    private function archiveLink(string $route, string $prefix, string $slug, string $label): ?array
    {
        if (! $this->routes->isLive($route)) {
            return null;
        }

        return ['label' => $label, 'href' => TaxonomyPath::join($prefix, $slug)];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{label: string, href: string}>
     */
    private function productLinks(Collection $products): array
    {
        return $products->map(fn (Product $p) => [
            'label' => (string) $p->title,
            'href' => '/products/'.$p->slug,
        ])->values()->all();
    }

    /**
     * @param  list<array{label: string, href: string}>  $links
     * @param  array{label: string, href: string}|null  $archive
     * @return array{title: string, links: list<array{label: string, href: string}>}|null
     */
    private function group(string $title, array $links, ?array $archive): ?array
    {
        if ($archive !== null) {
            $links[] = $archive;
        }

        $links = array_slice($links, 0, self::PER_GROUP);

        return $links === [] ? null : ['title' => $title, 'links' => $links];
    }
}
