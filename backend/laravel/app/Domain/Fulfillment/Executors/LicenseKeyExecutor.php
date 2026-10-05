<?php

namespace App\Domain\Fulfillment\Executors;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfillment\Models\FulfillmentUnit;
use App\Domain\Licensing\Models\GrantedLicense;
use App\Domain\Licensing\Models\LicenseKey;
use App\Domain\Licensing\Models\LicenseTerms;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

/**
 * إسناد مفتاح رخصة من المخزون (§٢٠).
 *
 * المفتاح يُقفل قبل إسناده (`lockForUpdate`): بغير ذلك يأخذ زبونان المفتاح
 * نفسه عند شرائهما معًا — وهو سباقٌ يقع فعلًا في الحملات لا نظريًّا.
 *
 * ونفادُ المخزون **لا يُعدّ فشلًا**: المفاتيح تُحمَّل دفعاتٍ من المزوّد،
 * والانتظار حالةٌ مشروعة يحلّها المشغّل برفع دفعةٍ جديدة. عدُّه فشلًا يُنبّه
 * على ما لا عطل فيه.
 */
class LicenseKeyExecutor implements FulfillmentExecutor
{
    public function strategies(): array
    {
        return ['LICENSE_KEY'];
    }

    public function execute(FulfillmentUnit $unit, Order $order, OrderItem $item): FulfillmentOutcome
    {
        if ($order->customer_id === null) {
            return FulfillmentOutcome::failed('لا زبون على الطلب — لا تُسنَد رخصةٌ بلا مالك.');
        }

        $variantId = $item->product_variant_id
            ?? ProductVariant::query()->where('legacy_product_id', $item->product_id)->value('id');

        if ($variantId === null) {
            return FulfillmentOutcome::failed('لا صيغة مربوطة بالبند — لا يُعرف من أيّ مخزونٍ يُسحب المفتاح.');
        }

        return DB::transaction(function () use ($order, $item, $variantId) {
            /*
             * ─── مفتاحٌ لكلّ نسخةٍ اشتُريت ───
             *
             * كان يُسحب مفتاحٌ واحد مهما كانت الكميّة. وكانت الكميّة `1`
             * دائمًا فاستوى الأمران؛ وبعد أن صارت حقيقيّةً افترقا: من يشتري
             * خمس رخصٍ يُحصَّل منه خمسة أثمان ويستلم مفتاحًا واحدًا — والوحدة
             * تُعلَّم `FULFILLED` فلا شيء يشكو. وهو خطأٌ ماليّ صامت في صالح
             * المتجر، وذاك أسوأ أنواعه.
             *
             * وما نقص عن المطلوب انتظارٌ لا تنفيذٌ جزئيّ: تسليمُ ثلاثةٍ من
             * خمسةٍ وتعليمُ الوحدة منفَّذةً يُقفل الطلب على نقصٍ لا يراه أحد.
             */
            $needed = max(1, (int) ($item->quantity ?? 1));

            $keys = LicenseKey::query()
                ->where('product_variant_id', $variantId)
                ->where('status', 'AVAILABLE')
                ->orderBy('id')
                ->limit($needed)
                ->lockForUpdate()
                ->get();

            if ($keys->count() < $needed) {
                return FulfillmentOutcome::pending(sprintf(
                    'المطلوب %d مفتاحًا والمتاح %d — ارفع دفعةً جديدة (§٢٠).',
                    $needed,
                    $keys->count(),
                ));
            }

            /* الرخصة لقطةٌ من القالب لا مرجعٌ إليه (§١٠١). */
            $terms = $item->product_offer_id === null
                ? null
                /*
                 * `where('id', …)` لا `whereKey`: الأخير منهجُ Eloquent، وعلى
                 * بانِي الاستعلام المجرّد يصير `where('key', …)` — عمودٌ لا وجود
                 * له، فيفشل إسنادُ كلّ مفتاح.
                 */
                : LicenseTerms::query()->find(
                    DB::table('product_offers')->where('id', $item->product_offer_id)->value('license_terms_id')
                );

            $license = GrantedLicense::query()->firstOrCreate(
                ['order_item_id' => $item->id],
                [
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'license_type' => $terms->license_type ?? 'SINGLE_USER',
                    'seat_count' => $terms->seat_count ?? null,
                    'usage_scope' => $terms->usage_scope ?? null,
                    'commercial_rights' => (bool) ($terms->commercial_rights ?? false),
                    'redistribution_rights' => (bool) ($terms->redistribution_rights ?? false),
                    'modification_rights' => (bool) ($terms->modification_rights ?? false),
                    'terms_version' => $terms->terms_version ?? 'unversioned',
                    'expires_at' => ($terms?->validity_days) === null ? null : now()->addDays($terms->validity_days),
                ],
            );

            foreach ($keys as $key) {
                $key->update([
                    'status' => 'ASSIGNED',
                    'granted_license_id' => $license->id,
                    'order_item_id' => $item->id,
                    'assigned_at' => now(),
                ]);
            }

            /* الوحدة تحمل الأوّل مرجعًا؛ والبقيّة تُقرأ من `order_item_id`. */
            return FulfillmentOutcome::fulfilled(licenseKeyId: $keys->first()->id);
        });
    }

    /**
     * يُبطل المفتاح المُسنَد — ولا يُعيده إلى المخزون.
     *
     * المفتاح الذي رآه زبونٌ مرّةً محروقٌ: قد يكون نسخه أو فعّله عند
     * المزوّد. وإعادتُه `AVAILABLE` تبيعه لثانٍ فيجد رخصةً مستعملة، وهو
     * عطلٌ يظهر عند المشتري الثاني ولا يُفسَّر. فيُعلَّم `REVOKED` بسببٍ
     * مكتوب — والقيد في المحرّك يوجب السبب أصلًا.
     */
    public function revoke(FulfillmentUnit $unit, Order $order, OrderItem $item, string $reason): ?string
    {
        $keys = LicenseKey::query()
            ->where('order_item_id', $item->id)
            ->whereIn('status', ['ASSIGNED', 'ACTIVATED', 'RESERVED'])
            ->lockForUpdate()
            ->get();

        if ($keys->isEmpty()) {
            return null;
        }

        foreach ($keys as $key) {
            $key->update(['status' => 'REVOKED', 'revocation_reason' => $reason]);
        }

        return "أُبطل {$keys->count()} مفتاح رخصة";
    }
}
