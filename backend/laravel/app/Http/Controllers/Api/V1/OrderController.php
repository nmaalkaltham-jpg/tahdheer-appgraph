<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\OrderStatus;
use App\Domain\Orders\OrderToken;
use App\Domain\Payments\Models\Payment;
use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    private const PAID_STATUSES = OrderStatus::COLLECTED;

    /** Guest order view by raw token. Wrong/tampered token ⇒ 404 (hash miss). */
    public function show(string $token): JsonResponse
    {
        $order = Order::query()
            ->where('order_key_hash', OrderToken::hash($token))
            ->with('items')
            ->first();

        if ($order === null) {
            return response()->json(['message' => 'الطلب غير موجود'], 404);
        }

        $paid = in_array($order->status, self::PAID_STATUSES, true);

        $entitlements = Entitlement::query()
            ->where('order_id', $order->id)
            ->get()
            ->keyBy('order_item_id');

        $files = [];
        foreach ($order->items as $item) {
            $entitlement = $entitlements->get($item->id);
            $files[] = [
                'entitlementId' => $entitlement?->id,
                'title' => $item->title_snapshot,
                'filename' => $this->mainFilename((int) $item->product_id),
                'locked' => ! $paid || $entitlement === null || $entitlement->status !== 'ACTIVE',
            ];
        }

        $out = [
            'orderNumber' => $order->number,
            'status' => $order->status,
            'emailMasked' => self::maskEmail($order->email),
            'items' => $order->items
                ->map(fn ($item) => ['title' => $item->title_snapshot, 'priceMinor' => $item->price_snapshot_minor])
                ->values(),
            'totals' => [
                'grossMinor' => $order->totals_gross_minor,
                'productDiscountMinor' => $order->discount_minor,
                'couponDiscountMinor' => $order->coupon_minor,
                'totalMinor' => $order->total_minor,
                'vatMinor' => $order->vat_minor,
                'savingMinor' => $order->discount_minor + $order->coupon_minor,
            ],
            'files' => $files,
        ];

        if ($order->paid_at !== null) {
            // ISO 8601 لا نصّ SQL: "2026-09-02 02:04:18" يعطي Invalid Date في
            // Safari ويُفسَّر وقتًا محلّيًّا في Chrome. الشكل واحد هنا وفي
            // لوحة الإدارة، فلا تقرأ الواجهتان تاريخين مختلفين للطلب نفسه.
            $out['paidAt'] = $order->paid_at->toIso8601String();
        }

        $masked = Payment::query()
            ->where('order_id', $order->id)
            ->whereNotNull('masked_instrument')
            ->latest('id')
            ->value('masked_instrument');
        if ($masked !== null) {
            $out['paymentMethodMasked'] = $masked;
        }

        /*
         * §١٤ §١٥ — حقيقةُ الدفع للمتصفّح، لا استنتاجُها من عودة المزوّد.
         *
         * `payment.state`: CREATED (رابطٌ صالح يُعاد)، INTENT_FAILED (يُطلب
         * رابطٌ جديد)، CAPTURED (وصل المال). و`paymentIssue = MISMATCH` حين
         * وصل تحصيلٌ ولم يُموِّل الطلب (مبلغٌ/عملةٌ/طلبٌ لا يطابق أو طلبٌ
         * مُلغى): لا يُقال «لم يُخصم شيء» لمن خُصم منه، ولا يُعرض عليه أن
         * يدفع ثانيةً.
         */
        // الأحدثُ لمسًا أوّلًا: لكلّ طريقة دفعٍ نيّتُها، والمعروضةُ آخرُ ما اختاره المشتري.
        $payments = Payment::query()->where('order_id', $order->id)->orderByDesc('updated_at')->orderByDesc('id')->get();
        $captured = $payments->first(fn ($p) => $p->state === 'CAPTURED');
        /*
         * آخر محاولة (الأحدث تعديلًا): قائمة، أو فشل إنشاؤها، أو انتهت بلا
         * مال عند المزوّد (FAILED — رُفضت أو أُلغيت أو انتهت مهلتها). الأخيرة
         * تُعرض فشلًا صريحًا قابلًا للإعادة، لا «نتحقّق» إلى الأبد.
         */
        $intent = $payments->sortByDesc('updated_at')->first(fn ($p) => in_array($p->state, ['CREATED', 'INTENT_FAILED', 'FAILED'], true));
        $awaiting = in_array($order->status, ['PENDING', 'AWAITING_PAYMENT'], true);

        $out['payment'] = [
            'provider' => ($captured ?? $intent)?->provider,
            'state' => ($captured ?? $intent)?->state,
            'payUrl' => $awaiting && $captured === null && $intent?->state === 'CREATED' ? $intent->pay_url : null,
            'canRetry' => $awaiting && $captured === null,
        ];
        $out['paymentIssue'] = $awaiting && $captured !== null ? 'MISMATCH'
            : (! $paid && ! $awaiting && $captured !== null && ! OrderStatus::wasEverCollected($order->status) ? 'MISMATCH' : null);

        return response()->json($out);
    }

    /** n***a@gmail.com — raw email is never exposed. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $first = mb_substr($local, 0, 1);
        $last = mb_strlen($local) > 1 ? mb_substr($local, -1) : '';

        return $first.'***'.$last.'@'.$domain;
    }

    /** Real attached file name only; null until an asset is linked — never invented. */
    private function mainFilename(int $productId): ?string
    {
        $row = DB::table('product_files')
            ->join('assets', 'assets.id', '=', 'product_files.asset_id')
            ->where('product_files.product_id', $productId)
            ->where('product_files.role', 'main')
            ->orderBy('product_files.position')
            ->select('assets.customer_label', 'assets.original_name')
            ->first();

        if ($row === null) {
            return null;
        }

        return $row->customer_label ?? $row->original_name;
    }
}
