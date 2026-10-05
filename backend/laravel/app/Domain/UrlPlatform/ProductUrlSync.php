<?php

namespace App\Domain\UrlPlatform;

use App\Models\Product;

/**
 * مزامنة رابط المنتج مع السجلّ — تُستدعى من وحدة تحكّم المنتج داخل معاملتها.
 *
 * المنتج يبقى مالك عمود `slug` (المتجر يقرؤه)، والسجلّ يملك الهويّة
 * والتاريخ والتحويل. الاثنان يُكتبان في المعاملة نفسها فلا يفترقان.
 */
final class ProductUrlSync
{
    public function __construct(private readonly UrlRegistry $registry) {}

    public function created(Product $product, ?int $actorId): void
    {
        $this->registry->claim('product', $product->id, 'ar', (string) $product->slug, [
            'actorId' => $actorId, 'source' => 'product.create', 'allowSuffix' => false,
        ]);
    }

    /** يُستدعى قبل حفظ الأعمدة: الرابط القديم من الصفّ، والجديد من المدخل. */
    public function slugChanging(Product $product, string $newSlug, ?int $actorId, ?string $reason, bool $force = false): void
    {
        $entry = $this->registry->activeFor('product', $product->id);
        if ($entry === null) {
            $this->registry->claim('product', $product->id, 'ar', $newSlug, ['actorId' => $actorId, 'source' => 'product.update', 'allowSuffix' => false]);

            return;
        }
        if ($entry->slug === $newSlug) {
            return;
        }
        $this->registry->changeSlug($entry, $newSlug, ['actorId' => $actorId, 'reason' => $reason, 'source' => 'product.update', 'force' => $force]);
    }

    public function published(Product $product, ?int $actorId): void
    {
        $entry = $this->registry->activeFor('product', $product->id)
            ?? $this->registry->claim('product', $product->id, 'ar', (string) $product->slug, ['actorId' => $actorId, 'source' => 'product.publish', 'allowSuffix' => false]);
        $this->registry->publish($entry, ['actorId' => $actorId, 'source' => 'product.publish']);
    }

    public function withdrawn(Product $product, ?int $actorId, string $to): void
    {
        $entry = $this->registry->activeFor('product', $product->id);
        if ($entry === null) {
            return;
        }
        // ما لم يُنشر في السجلّ لا يُؤرشف: منتجٌ نُشر بتعديل القاعدة مباشرةً لا يملك صفًّا عامًّا.
        if ($to === 'retired' && $entry->status()->isPublic()) {
            $this->registry->archive($entry, ['actorId' => $actorId, 'source' => 'product.withdraw']);
        }
    }
}
